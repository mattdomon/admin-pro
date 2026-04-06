<?php
declare(strict_types=1);

namespace app\worker;

use app\model\Device;
use app\model\Task as TaskModel;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;

/**
 * GatewayWorker 事件处理
 * 
 * 处理客户端节点的 WebSocket 连接、认证、心跳、任务分发
 */
class Events
{
    /**
     * 心跳间隔: 15 秒
     */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * 节点离线判定: 超过 3 次心跳未收到
     */
    private const OFFLINE_THRESHOLD = 3;

    /**
     * 客户端连接时触发
     */
    public static function onConnect(string $clientId): void
    {
        trace("客户端连接: {$clientId}", 'info');
        Gateway::sendToClient($clientId, json_encode([
            'action' => 'welcome',
            'message' => 'Connected to OpenClaw-Admin'
        ]));
    }

    /**
     * 收到客户端消息时触发
     */
    public static function onMessage(string $clientId, string $message): void
    {
        $data = json_decode($message, true);
        if (!$data) {
            Gateway::sendToClient($clientId, json_encode(['error' => 'Invalid JSON']));
            return;
        }

        $action = $data['action'] ?? '';

        match ($action) {
            'auth'      => self::handleAuth($clientId, $data),
            'ping'      => self::handlePing($clientId, $data),
            'cot_logs'  => self::handleLogs($clientId, $data),
            'task_result' => self::handleTaskResult($clientId, $data),
            'task_killed' => self::handleTaskKilled($clientId, $data),
            default     => trace("未知 action: {$action} from {$clientId}", 'warning'),
        };
    }

    /**
     * 客户端断开连接时触发
     */
    public static function onClose(string $clientId): void
    {
        // 检查是否有绑定的 UUID
        $uuid = Gateway::getUidByClientId($clientId);
        if ($uuid) {
            Gateway::unbindUid($clientId);
            Device::where('uuid', $uuid)->update([
                'status' => 0,  // 离线
                'last_heartbeat' => time(),
            ]);
            trace("节点 {$uuid} 断开连接 (clientId: {$clientId})", 'warning');
        }
    }

    /**
     * 处理节点认证
     */
    private static function handleAuth(string $clientId, array $data): void
    {
        $uuid    = $data['uuid'] ?? '';
        $token   = $data['token'] ?? '';

        if (empty($uuid)) {
            Gateway::sendToClient($clientId, json_encode(['action' => 'auth_failed', 'reason' => 'uuid required']));
            Gateway::closeClient($clientId);
            return;
        }

        // 查找或注册设备
        $device = Device::where('uuid', $uuid)->find();
        if (!$device) {
            // 自动注册新节点
            $device = Device::create([
                'uuid'   => $uuid,
                'name'   => $uuid,
                'token'  => $token ?: bin2hex(random_bytes(16)),
                'status' => 1,  // 在线
            ]);
            trace("新节点自动注册: {$uuid}", 'info');
        } else {
            $device->save(['status' => 1, 'last_heartbeat' => time()]);
            trace("节点重连: {$uuid}", 'info');
        }

        // 绑定 UID
        Gateway::bindUid($clientId, $uuid);

        Gateway::sendToClient($clientId, json_encode([
            'action'  => 'auth_ok',
            'message' => 'Authentication successful',
        ]));
    }

    /**
     * 处理心跳
     */
    private static function handlePing(string $clientId, array $data): void
    {
        $uuid = Gateway::getUidByClientId($clientId);
        if (!$uuid) return;

        $update = [
            'last_heartbeat' => time(),
        ];

        if (isset($data['cpu'])) {
            $update['sys_info'] = [
                'cpu' => $data['cpu'] ?? '',
                'mem' => $data['mem'] ?? '',
            ];
        }

        Device::where('uuid', $uuid)->update($update);
    }

    /**
     * 处理日志回传
     */
    private static function handleLogs(string $clientId, array $data): void
    {
        $taskId = $data['task_id'] ?? '';
        $logs   = $data['logs'] ?? [];

        if (empty($taskId) || empty($logs)) return;

        $task = TaskModel::where('id', $taskId)->find();
        if ($task) {
            // 日志追加到 error_traceback (简化存储，生产环境可改为独立日志表)
            $existing = $task->error_traceback ?? '';
            foreach ($logs as $log) {
                $line = "[{$log['level']}] {$log['msg']}" . PHP_EOL;
                $existing .= $line;
            }
            $task->save(['error_traceback' => $existing]);
        }
    }

    /**
     * 处理任务结果
     */
    private static function handleTaskResult(string $clientId, array $data): void
    {
        $taskId = $data['task_id'] ?? '';
        $status = $data['status'] ?? 'failed';
        $returnCode = $data['return_code'] ?? null;
        $error = $data['error'] ?? null;

        if (empty($taskId)) return;

        $task = TaskModel::where('id', $taskId)->find();
        if ($task) {
            $update = [
                'status'      => $status,
                'finished_at' => date('Y-m-d H:i:s'),
            ];
            if ($error) {
                $update['error_traceback'] = $error;
            }
            $task->save($update);

            trace("任务 {$taskId} 完成: status={$status}, code={$returnCode}", 'info');
        }
    }

    /**
     * 处理任务终止确认
     */
    private static function handleTaskKilled(string $clientId, array $data): void
    {
        $taskId = $data['task_id'] ?? '';
        if (empty($taskId)) return;

        $task = TaskModel::where('id', $taskId)->find();
        if ($task) {
            $task->save([
                'status'      => 'killed',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            trace("任务 {$taskId} 已被终止", 'info');
        }
    }

    /**
     * Worker 启动时触发（BusinessWorker）
     */
    public static function onWorkerStart(Worker $worker): void
    {
        trace("BusinessWorker {$worker->id} 启动", 'info');
    }
}
