<?php
declare(strict_types=1);

namespace app\controller;

use think\Response;
use think\Exception;
use app\service\LogService;

/**
 * OpenClaw Bridge 任务管理控制器
 * 
 * 负责与 bridge.py 通信，管理脚本执行任务的生命周期
 */
class BridgeCtrl extends BaseController
{
    /**
     * 任务存储目录
     */
    private const TASK_DIR = 'data/tasks/';
    
    /**
     * bridge.py 进程信息文件
     */
    private const BRIDGE_PID_FILE = 'data/bridge.pid';
    
    /**
     * 提交任务到bridge.py
     */
    public function submitTask(): Response
    {
        try {
            $type = $this->request->param('type', 'script');
            $payload = $this->request->param('payload', []);
            
            // 验证参数
            if (empty($payload)) {
                return $this->json(400, '任务payload不能为空');
            }
            
            // 确保bridge.py正在运行
            if (!$this->isBridgeRunning()) {
                return $this->json(500, 'Bridge服务未运行，请联系管理员');
            }
            
            // 生成任务ID
            $taskId = $type . '_' . time() . '_' . uniqid();
            
            // 构造任务数据
            $taskData = [
                'id' => $taskId,
                'type' => $type,
                'payload' => $payload,
                'status' => 'pending',
                'created_at' => time(),
                'started_at' => null,
                'completed_at' => null,
                'result' => null,
                'error' => null,
                'retries' => 0,
                'script_name' => $payload['script_path'] ?? ''
            ];
            
            // 保存任务到磁盘（模拟bridge.py的任务队列）
            $taskFile = $this->getTaskDir() . $taskId . '.json';
            if (!file_put_contents($taskFile, json_encode($taskData, JSON_PRETTY_PRINT))) {
                return $this->json(500, '保存任务失败');
            }
            
            // 这里实际环境中应该通过WebSocket或者队列通知bridge.py
            // 暂时模拟立即处理
            $this->simulateTaskExecution($taskId, $taskData);
            
            return $this->success([
                'task_id' => $taskId,
                'message' => '任务已提交'
            ]);
            
        } catch (\Exception $e) {
            LogService::error('bridge', '提交任务失败', $e);
            return $this->json(500, '提交任务失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取任务列表
     */
    public function getTasks(): Response
    {
        try {
            $page = (int) $this->request->param('page', 1);
            $size = (int) $this->request->param('size', 10);
            $status = $this->request->param('status', '');
            
            $taskDir = $this->getTaskDir();
            $tasks = [];
            
            // 扫描任务文件
            $files = glob($taskDir . '*.json');
            
            // 按时间倒序排列（最新的在前）
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            foreach ($files as $file) {
                $taskData = json_decode(file_get_contents($file), true);
                if (!$taskData) continue;
                
                // 状态筛选
                if ($status && $taskData['status'] !== $status) {
                    continue;
                }
                
                $tasks[] = $taskData;
            }
            
            $total = count($tasks);
            $offset = ($page - 1) * $size;
            $paginatedTasks = array_slice($tasks, $offset, $size);
            
            return $this->success([
                'tasks' => $paginatedTasks,
                'total' => $total,
                'page' => $page,
                'size' => $size
            ]);
            
        } catch (\Exception $e) {
            LogService::error('bridge', '获取任务列表失败', $e);
            return $this->json(500, '获取任务列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取单个任务详情
     */
    public function getTask(): Response
    {
        try {
            $taskId = $this->request->param('task_id');
            
            if (empty($taskId)) {
                return $this->json(400, '任务ID不能为空');
            }
            
            $taskFile = $this->getTaskDir() . $taskId . '.json';
            
            if (!file_exists($taskFile)) {
                return $this->json(404, '任务不存在');
            }
            
            $taskData = json_decode(file_get_contents($taskFile), true);
            
            if (!$taskData) {
                return $this->json(500, '任务数据损坏');
            }
            
            return $this->success($taskData);
            
        } catch (\Exception $e) {
            LogService::error("bridge", "获取任务详情失败", $e);
            return $this->json(500, '获取任务详情失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 清理已完成的任务
     */
    public function clearCompletedTasks(): Response
    {
        try {
            $taskDir = $this->getTaskDir();
            $files = glob($taskDir . '*.json');
            $clearedCount = 0;
            
            foreach ($files as $file) {
                $taskData = json_decode(file_get_contents($file), true);
                if (!$taskData) continue;
                
                // 清理已完成（成功/失败/超时）的任务
                if (in_array($taskData['status'], ['success', 'failed', 'timeout', 'cancelled'])) {
                    unlink($file);
                    $clearedCount++;
                }
            }
            
            return $this->success([
                'cleared_count' => $clearedCount,
                'message' => "已清理 {$clearedCount} 个完成的任务"
            ]);
            
        } catch (\Exception $e) {
            LogService::error("bridge", "清理任务失败", $e);
            return $this->json(500, '清理任务失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 取消任务
     */
    public function cancelTask(): Response
    {
        try {
            $taskId = $this->request->param('task_id');
            
            if (empty($taskId)) {
                return $this->json(400, '任务ID不能为空');
            }
            
            $taskFile = $this->getTaskDir() . $taskId . '.json';
            
            if (!file_exists($taskFile)) {
                return $this->json(404, '任务不存在');
            }
            
            $taskData = json_decode(file_get_contents($taskFile), true);
            
            if (!$taskData) {
                return $this->json(500, '任务数据损坏');
            }
            
            // 只能取消等待中或运行中的任务
            if (!in_array($taskData['status'], ['pending', 'running'])) {
                return $this->json(400, '任务已完成，无法取消');
            }
            
            // 更新任务状态
            $taskData['status'] = 'cancelled';
            $taskData['completed_at'] = time();
            $taskData['error'] = '任务已被用户取消';
            
            file_put_contents($taskFile, json_encode($taskData, JSON_PRETTY_PRINT));
            
            return $this->success([
                'task_id' => $taskId,
                'message' => '任务已取消'
            ]);
            
        } catch (\Exception $e) {
            LogService::error("bridge", "取消任务失败", $e);
            return $this->json(500, '取消任务失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取Bridge服务状态
     */
    public function getBridgeStatus(): Response
    {
        try {
            $isRunning = $this->isBridgeRunning();
            $pid = $this->getBridgePid();
            
            $status = [
                'running' => $isRunning,
                'pid' => $pid,
                'uptime' => $isRunning ? $this->getBridgeUptime() : 0,
                'task_dir' => $this->getTaskDir(),
                'active_tasks' => $this->getActiveTaskCount()
            ];
            
            return $this->success($status);
            
        } catch (\Exception $e) {
            LogService::error("bridge", "获取Bridge状态失败", $e);
            return $this->json(500, '获取Bridge状态失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 模拟任务执行（生产环境中由bridge.py实际执行）
     */
    private function simulateTaskExecution(string $taskId, array $taskData): void
    {
        // 这里应该通过WebSocket或队列通知bridge.py
        // 暂时模拟执行过程
        
        if ($taskData['type'] === 'script') {
            $scriptPath = $taskData['payload']['script_path'] ?? '';
            
            // 检查脚本是否存在
            $fullScriptPath = app()->getRootPath() . '/storage/openclaw_scripts/' . $scriptPath;
            
            if (!file_exists($fullScriptPath)) {
                $this->updateTaskStatus($taskId, 'failed', null, '脚本文件不存在: ' . $scriptPath);
                return;
            }
            
            // 模拟异步执行（实际应该在bridge.py中处理）
            $this->asyncExecuteScript($taskId, $fullScriptPath);
        }
    }
    
    /**
     * 异步执行脚本（模拟）
     */
    private function asyncExecuteScript(string $taskId, string $scriptPath): void
    {
        // 更新任务为运行中
        $this->updateTaskStatus($taskId, 'running');
        
        // 模拟在后台执行脚本
        // 实际环境中这应该在bridge.py中通过subprocess处理
        $command = sprintf('cd %s && python3 "%s" > /dev/null 2>&1 &', 
            dirname($scriptPath), 
            $scriptPath
        );
        
        exec($command, $output, $returnCode);
        
        // 模拟执行结果
        if ($returnCode === 0) {
            $this->updateTaskStatus($taskId, 'success', [
                'returncode' => 0,
                'stdout' => '脚本执行成功',
                'stderr' => '',
                'execution_time' => rand(1, 5) // 模拟执行时间
            ]);
        } else {
            $this->updateTaskStatus($taskId, 'failed', null, '脚本执行失败，退出码: ' . $returnCode);
        }
    }
    
    /**
     * 更新任务状态
     */
    private function updateTaskStatus(string $taskId, string $status, ?array $result = null, ?string $error = null): void
    {
        try {
            $taskFile = $this->getTaskDir() . $taskId . '.json';
            
            if (!file_exists($taskFile)) {
                return;
            }
            
            $taskData = json_decode(file_get_contents($taskFile), true);
            
            if (!$taskData) {
                return;
            }
            
            $taskData['status'] = $status;
            
            if ($status === 'running' && !$taskData['started_at']) {
                $taskData['started_at'] = time();
            }
            
            if (in_array($status, ['success', 'failed', 'timeout', 'cancelled'])) {
                $taskData['completed_at'] = time();
            }
            
            if ($result !== null) {
                $taskData['result'] = $result;
            }
            
            if ($error !== null) {
                $taskData['error'] = $error;
            }
            
            file_put_contents($taskFile, json_encode($taskData, JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            LogService::error("bridge", "更新任务状态失败", $e);
        }
    }
    
    /**
     * 检查Bridge服务是否运行
     */
    private function isBridgeRunning(): bool
    {
        $pidFile = $this->getBridgePidFile();
        
        if (!file_exists($pidFile)) {
            return false;
        }
        
        $pid = (int) trim(file_get_contents($pidFile));
        
        if ($pid <= 0) {
            return false;
        }
        
        // 检查进程是否存在（Unix系统）
        return posix_kill($pid, 0);
    }
    
    /**
     * 获取Bridge进程PID
     */
    private function getBridgePid(): int
    {
        $pidFile = $this->getBridgePidFile();
        
        if (!file_exists($pidFile)) {
            return 0;
        }
        
        return (int) trim(file_get_contents($pidFile));
    }
    
    /**
     * 获取Bridge运行时间
     */
    private function getBridgeUptime(): int
    {
        $pidFile = $this->getBridgePidFile();
        
        if (!file_exists($pidFile)) {
            return 0;
        }
        
        return time() - filemtime($pidFile);
    }
    
    /**
     * 获取活跃任务数量
     */
    private function getActiveTaskCount(): int
    {
        try {
            $taskDir = $this->getTaskDir();
            $files = glob($taskDir . '*.json');
            $count = 0;
            
            foreach ($files as $file) {
                $taskData = json_decode(file_get_contents($file), true);
                if (!$taskData) continue;
                
                if (in_array($taskData['status'], ['pending', 'running'])) {
                    $count++;
                }
            }
            
            return $count;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * 获取任务目录
     */
    private function getTaskDir(): string
    {
        $dir = app()->getRootPath() . self::TASK_DIR;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return $dir;
    }
    
    /**
     * 获取Bridge PID文件路径
     */
    private function getBridgePidFile(): string
    {
        return app()->getRootPath() . self::BRIDGE_PID_FILE;
    }
}