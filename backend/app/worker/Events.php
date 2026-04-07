<?php
declare(strict_types=1);

namespace app\worker;

use app\model\NodeKey;
use app\model\AdminUser;
use Workerman\Worker;
use Workerman\Timer;
use GatewayWorker\Lib\Gateway;

/**
 * GatewayWorker 事件处理 - SaaS 改造版
 *
 * 核心变化：
 * 1. 强制首包鉴权 (node_key)，3秒超时断连
 * 2. 内存映射 $connections[$user_id][$node_key] = $clientId
 * 3. 废弃全局广播，改为靶向路由 (target_node_key)
 * 4. node_online / node_offline 事件推送给该用户的 Web 前端
 */
class Events
{
    /**
     * 鉴权等待超时（秒）：收到连接后若 3 秒内未发鉴权包则断开
     */
    private const AUTH_TIMEOUT = 3;

    /**
     * 心跳超时（秒）：超过此时间未收到心跳则标记离线
     */
    private const HEARTBEAT_TIMEOUT = 60;

    /**
     * 节点连接映射（内存）
     *
     * 结构：
     *   $nodeConnections[$node_key] = $clientId      // 节点客户端
     *   $webConnections[$user_id][]  = $clientId      // Web 前端客户端
     *
     * 注意：BusinessWorker 多进程，此数组仅在当前 Worker 进程内有效。
     * 对于精确靶向推送，我们使用 Gateway::bindUid 绑定 node_key 作为 UID，
     * 从而让 Gateway 进程路由到正确连接。
     */
    private static array $pendingAuth = []; // $clientId => timer_id（等待鉴权定时器）

    // ─────────────────────────────────────────
    // GatewayWorker 标准事件
    // ─────────────────────────────────────────

    /**
     * 客户端连接时触发
     * 立即设置 3 秒鉴权超时定时器
     */
    public static function onConnect(string $clientId): void
    {
        trace("[Gateway] 新连接: {$clientId}", 'info');

        // 发送欢迎包，要求鉴权
        Gateway::sendToClient($clientId, json_encode([
            'type'    => 'require_auth',
            'message' => '请在 3 秒内发送鉴权包: {"type":"auth","node_key":"xxx"}',
        ]));

        // 设置 3 秒超时定时器
        $timerId = Timer::add(self::AUTH_TIMEOUT, function () use ($clientId) {
            // 超时仍未鉴权，断开连接
            trace("[Gateway] 鉴权超时，断开连接: {$clientId}", 'warning');
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'auth_timeout',
                'error' => '鉴权超时，连接已关闭',
            ]));
            Gateway::closeClient($clientId);
            unset(self::$pendingAuth[$clientId]);
        }, [], false); // false = 只触发一次

        self::$pendingAuth[$clientId] = $timerId;
    }

    /**
     * 收到客户端消息时触发
     */
    public static function onMessage(string $clientId, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'error',
                'error' => 'Invalid JSON',
            ]));
            return;
        }

        $type = $data['type'] ?? ($data['action'] ?? '');
        
        // 🐛 调试日志5：PHP网关收到消息
        echo "Gateway Received: " . $type . "\n";
        echo "Full message: " . $message . "\n";

        // 未鉴权的连接，处理 auth（Python节点）和 web_register（Web前端）
        if (isset(self::$pendingAuth[$clientId])) {
            if ($type === 'auth') {
                self::handleAuth($clientId, $data);
            } elseif ($type === 'web_register') {
                self::handleWebRegister($clientId, $data);
            } else {
                // 非 auth / web_register 包但还未鉴权，直接拒绝
                Gateway::sendToClient($clientId, json_encode([
                    'type'  => 'auth_required',
                    'error' => '请先发送鉴权包',
                ]));
            }
            return;
        }

        // 已鉴权，正常处理
        match ($type) {
            'ping'          => self::handlePing($clientId, $data),
            'task_result'   => self::handleTaskResult($clientId, $data),
            'task_progress' => self::handleTaskProgress($clientId, $data),
            'task_killed'   => self::handleTaskKilled($clientId, $data),
            'web_register'  => self::handleWebRegister($clientId, $data), // Web 前端注册
            'cot_logs'      => self::handleLogs($clientId, $data),
            'chat_stream'   => self::handleChatStream($clientId, $data),  // 🚀 新增：AI流式数据
            default         => trace("[Gateway] 未知 type: {$type} from {$clientId}", 'warning'),
        };
    }

    /**
     * 客户端断开连接时触发
     */
    public static function onClose(string $clientId): void
    {
        // 清理未完成的鉴权定时器
        if (isset(self::$pendingAuth[$clientId])) {
            Timer::del(self::$pendingAuth[$clientId]);
            unset(self::$pendingAuth[$clientId]);
            return;
        }

        // 获取绑定的 UID（node_key 或 web:{user_id}:{clientId}）
        $uid = Gateway::getUidByClientId($clientId);
        if (!$uid) return;

        Gateway::unbindUid($clientId, $uid);

        // 判断是节点还是 Web 前端
        if (str_starts_with($uid, 'web:')) {
            trace("[Gateway] Web前端断开: uid={$uid}", 'info');
            return;
        }

        // 节点断开：更新数据库状态，广播 node_offline
        $nodeKey = $uid;
        $record  = NodeKey::where('node_key', $nodeKey)->find();
        if ($record) {
            $record->save([
                'status'         => NodeKey::STATUS_OFFLINE,
                'last_heartbeat' => time(),
            ]);

            trace("[Gateway] 节点离线: node_key={$nodeKey}, user_id={$record->user_id}", 'warning');

            // 向该用户所有 Web 前端广播 node_offline
            self::broadcastToUserWebs($record->user_id, [
                'type'      => 'node_offline',
                'node_key'  => $nodeKey,
                'node_name' => $record->node_name,
                'user_id'   => $record->user_id,
                'timestamp' => time(),
            ]);
        }
    }

    /**
     * Worker 启动时触发（BusinessWorker）
     */
    public static function onWorkerStart(Worker $worker): void
    {
        trace("[Gateway] BusinessWorker {$worker->id} 启动", 'info');
    }

    // ─────────────────────────────────────────
    // 核心处理方法
    // ─────────────────────────────────────────

    /**
     * 处理节点鉴权
     *
     * 期望格式：
     * {"type": "auth", "node_key": "a1b2c3d4...（32位）"}
     */
    private static function handleAuth(string $clientId, array $data): void
    {
        $nodeKey = trim((string) ($data['node_key'] ?? ''));

        // 基本格式校验
        if (empty($nodeKey) || strlen($nodeKey) !== 32) {
            trace("[Gateway] 鉴权失败，无效 node_key: {$clientId}", 'warning');
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'auth_failed',
                'error' => 'node_key 无效（必须为 32 位字符串）',
            ]));
            Gateway::closeClient($clientId);
            if (isset(self::$pendingAuth[$clientId])) {
                Timer::del(self::$pendingAuth[$clientId]);
                unset(self::$pendingAuth[$clientId]);
            }
            return;
        }

        // 查询数据库
        $record = NodeKey::where('node_key', $nodeKey)->find();
        if (!$record) {
            trace("[Gateway] 鉴权失败，node_key 不存在: {$nodeKey}", 'warning');
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'auth_failed',
                'error' => 'node_key 不存在或已被删除',
            ]));
            Gateway::closeClient($clientId);
            if (isset(self::$pendingAuth[$clientId])) {
                Timer::del(self::$pendingAuth[$clientId]);
                unset(self::$pendingAuth[$clientId]);
            }
            return;
        }

        // 鉴权成功 ✅
        // 1. 取消超时定时器
        if (isset(self::$pendingAuth[$clientId])) {
            Timer::del(self::$pendingAuth[$clientId]);
            unset(self::$pendingAuth[$clientId]);
        }

        // 2. 绑定 UID = node_key（精准靶向路由的关键）
        Gateway::bindUid($clientId, $nodeKey);

        // 3. 更新数据库：状态改为 online
        $record->save([
            'status'         => NodeKey::STATUS_ONLINE,
            'last_heartbeat' => time(),
        ]);

        trace("[Gateway] 节点上线: node_key={$nodeKey}, user_id={$record->user_id}, node_name={$record->node_name}", 'info');

        // 4. 回复鉴权成功
        Gateway::sendToClient($clientId, json_encode([
            'type'      => 'auth_ok',
            'node_key'  => $nodeKey,
            'node_name' => $record->node_name,
            'message'   => '鉴权成功，节点已上线',
        ]));

        // 5. 向该用户所有 Web 前端广播 node_online
        self::broadcastToUserWebs($record->user_id, [
            'type'      => 'node_online',
            'node_key'  => $nodeKey,
            'node_name' => $record->node_name,
            'user_id'   => $record->user_id,
            'timestamp' => time(),
        ]);
    }

    /**
     * 处理 Web 前端注册
     *
     * Web 浏览器连接后发送：
     * {"type": "web_register", "token": "xxx"}
     * 绑定 UID = "web:{user_id}"，用于接收 node_online/node_offline 事件
     */
    private static function handleWebRegister(string $clientId, array $data): void
    {
        $token = trim((string) ($data['token'] ?? ''));
        if (empty($token)) {
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'error',
                'error' => 'token 不能为空',
            ]));
            return;
        }

        // 验证 Token（从 ThinkPHP cache 中读取）
        $cacheKey = "token:{$token}";
        $userInfo = cache($cacheKey);
        if (!is_array($userInfo) || empty($userInfo['user_id'])) {
            Gateway::sendToClient($clientId, json_encode([
                'type'  => 'error',
                'error' => 'Token 无效或已过期',
            ]));
            // 鉴权失败，关闭连接
            Gateway::closeClient($clientId);
            if (isset(self::$pendingAuth[$clientId])) {
                Timer::del(self::$pendingAuth[$clientId]);
                unset(self::$pendingAuth[$clientId]);
            }
            return;
        }

        $userId = $userInfo['user_id'];

        // ✅ 鉴权成功！立刻销毁超时定时器，防止前端被踢
        if (isset(self::$pendingAuth[$clientId])) {
            Timer::del(self::$pendingAuth[$clientId]);
            unset(self::$pendingAuth[$clientId]);
            trace("[Gateway] Web前端鉴权定时器已销毁: clientId={$clientId}", 'info');
        }

        // 绑定 UID = "web:{user_id}"（一个用户可多个 Tab，所以用 group 概念）
        $uid = "web:{$userId}";
        Gateway::bindUid($clientId, $uid);

        trace("[Gateway] Web前端注册: user_id={$userId}, uid={$uid}, clientId={$clientId}", 'info');

        // 返回当前用户的所有节点在线状态
        $nodes = NodeKey::where('user_id', $userId)
                        ->field('node_key, node_name, status, last_heartbeat')
                        ->select()
                        ->toArray();

        Gateway::sendToClient($clientId, json_encode([
            'type'  => 'web_registered',
            'nodes' => $nodes,
        ]));
    }

    /**
     * 处理心跳
     *
     * {"type": "ping", "cpu": "25.3", "mem": "60.1"}
     */
    private static function handlePing(string $clientId, array $data): void
    {
        $nodeKey = Gateway::getUidByClientId($clientId);
        if (!$nodeKey || str_starts_with($nodeKey, 'web:')) return;

        $update = ['last_heartbeat' => time()];

        if (isset($data['cpu']) || isset($data['mem'])) {
            $update['sys_info'] = json_encode([
                'cpu' => $data['cpu'] ?? null,
                'mem' => $data['mem'] ?? null,
            ]);
        }

        NodeKey::where('node_key', $nodeKey)->update($update);

        Gateway::sendToClient($clientId, json_encode(['type' => 'pong']));
    }

    /**
     * 靶向任务下发（供 PHP 业务调用）
     *
     * 通过 Gateway::sendToUid($node_key, $message) 精准推送到对应节点
     * 调用方式示例（BridgeCtrl.php）：
     *   Gateway::sendToUid($targetNodeKey, json_encode([...task...]));
     */

    /**
     * 处理任务执行结果（节点上报）
     *
     * {"type": "task_result", "task_id": "xxx", "status": "completed", "output": "..."}
     */
    private static function handleTaskResult(string $clientId, array $data): void
    {
        $taskId    = $data['task_id'] ?? '';
        $status    = $data['status'] ?? 'failed';
        $output    = $data['output'] ?? '';
        $error     = $data['error'] ?? null;
        $nodeKey   = Gateway::getUidByClientId($clientId);

        if (empty($taskId)) return;

        // 更新数据库任务状态
        \app\model\Task::where('id', $taskId)->update([
            'status'          => $status,
            'error_traceback' => $error,
            'finished_at'     => date('Y-m-d H:i:s'),
        ]);

        trace("[Gateway] 任务完成: task_id={$taskId}, status={$status}", 'info');

        // 查询该任务属于哪个用户，回推结果给 Web 前端
        $task = \app\model\Task::where('id', $taskId)->find();
        if ($task) {
            $node = NodeKey::where('node_key', $nodeKey)->find();
            if ($node) {
                self::broadcastToUserWebs($node->user_id, [
                    'type'       => 'task_result',
                    'task_id'    => $taskId,
                    'status'     => $status,
                    'output'     => $output,
                    'error'      => $error,
                    'node_key'   => $nodeKey,
                    'timestamp'  => time(),
                ]);
            }
        }
    }

    /**
     * 处理任务执行进度（节点上报）
     *
     * {"type": "task_progress", "task_id": "xxx", "progress": 60, "log": "Step 3/5 done"}
     */
    private static function handleTaskProgress(string $clientId, array $data): void
    {
        $taskId  = $data['task_id'] ?? '';
        $nodeKey = Gateway::getUidByClientId($clientId);

        if (empty($taskId)) return;

        $node = NodeKey::where('node_key', $nodeKey)->find();
        if ($node) {
            self::broadcastToUserWebs($node->user_id, [
                'type'      => 'task_progress',
                'task_id'   => $taskId,
                'progress'  => $data['progress'] ?? 0,
                'log'       => $data['log'] ?? '',
                'node_key'  => $nodeKey,
                'timestamp' => time(),
            ]);
        }
    }

    /**
     * 处理任务终止确认
     */
    private static function handleTaskKilled(string $clientId, array $data): void
    {
        $taskId = $data['task_id'] ?? '';
        if (empty($taskId)) return;

        \app\model\Task::where('id', $taskId)->update([
            'status'      => 'killed',
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        trace("[Gateway] 任务已终止: task_id={$taskId}", 'info');
    }

    /**
     * 处理日志回传
     */
    private static function handleLogs(string $clientId, array $data): void
    {
        $taskId = $data['task_id'] ?? '';
        $logs   = $data['logs'] ?? [];
        if (empty($taskId) || empty($logs)) return;

        $task = \app\model\Task::where('id', $taskId)->find();
        if ($task) {
            $existing = $task->error_traceback ?? '';
            foreach ($logs as $log) {
                $existing .= "[{$log['level']}] {$log['msg']}" . PHP_EOL;
            }
            $task->save(['error_traceback' => $existing]);
        }
    }
    
    /**
     * 处理 AI 流式数据（新增）
     * 
     * 数据格式：
     * {"type": "chat_stream", "task_id": "xxx", "content": "增量字符", "status": "processing"}
     * {"type": "chat_stream", "task_id": "xxx", "content": "", "status": "completed"}
     */
    private static function handleChatStream(string $clientId, array $data): void
    {
        $taskId  = $data['task_id'] ?? '';
        $content = $data['content'] ?? '';
        $status  = $data['status'] ?? 'processing';
        $nodeKey = Gateway::getUidByClientId($clientId);
        
        if (empty($taskId)) {
            echo "❌ chat_stream 消息缺少 task_id\n";
            return;
        }
        
        echo "💬 处理 chat_stream: task_id={$taskId}, status={$status}, content_len=" . strlen($content) . "\n";
        
        // 查找该任务属于哪个用户，路由给对应的 Web 前端
        // 这里需要根据 task_id 查找到用户，然后转发给该用户的所有 Web 客户端
        $node = NodeKey::where('node_key', $nodeKey)->find();
        if (!$node) {
            echo "❌ 找不到节点信息: node_key={$nodeKey}\n";
            return;
        }
        
        $userId = $node->user_id;
        echo "📡 转发 chat_stream 给用户 {$userId} 的 Web 前端\n";
        
        // 转发给该用户的所有 Web 前端
        self::broadcastToUserWebs($userId, [
            'type'      => 'chat_stream',
            'task_id'   => $taskId,
            'content'   => $content,
            'status'    => $status,
            'node_key'  => $nodeKey,
            'timestamp' => time(),
        ]);
    }

    // ─────────────────────────────────────────
    // 工具方法
    // ─────────────────────────────────────────

    /**
     * 向某用户的所有 Web 前端广播消息
     *
     * Web 前端注册时绑定 UID = "web:{user_id}"
     * 通过 Gateway::sendToUid 精准投递
     */
    private static function broadcastToUserWebs(int $userId, array $payload): void
    {
        $uid     = "web:{$userId}";
        $message = json_encode($payload, JSON_UNESCAPED_UNICODE);

        try {
            Gateway::sendToUid($uid, $message);
            trace("[Gateway] 推送给 Web 用户 {$userId}: type={$payload['type']}", 'info');
        } catch (\Throwable $e) {
            trace("[Gateway] 推送失败: {$e->getMessage()}", 'error');
        }
    }
}
