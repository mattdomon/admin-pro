<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TaskService;

class TestCtrl extends BaseController
{
    private TaskService $taskService;

    public function __construct()
    {
        parent::__construct();
        $this->taskService = new TaskService();
    }

    /**
     * 运行测试脚本
     * POST /api/test/run-script
     * 
     * Body: {
     *   "script_name": "test_script.py",
     *   "params": {"key": "value"}
     * }
     */
    public function runScript()
    {
        try {
            // 获取请求参数
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?: [];
            
            $scriptName = $body['script_name'] ?? 'test_script.py';
            $params = $body['params'] ?? [];
            
            // 构建脚本路径（支持多个可能的位置）
            $possiblePaths = [
                realpath(__DIR__ . '/../../scripts/' . $scriptName),
                realpath(__DIR__ . '/../../../scripts/' . $scriptName),  // 项目根目录
                realpath(__DIR__ . '/../../storage/openclaw_scripts/' . $scriptName)
            ];
            
            $scriptPath = null;
            foreach ($possiblePaths as $path) {
                if ($path && file_exists($path)) {
                    $scriptPath = $path;
                    break;
                }
            }
            
            if (!$scriptPath) {
                return $this->json(404, "脚本文件不存在: {$scriptName}", [
                    'searched_paths' => array_filter($possiblePaths)
                ]);
            }
            
            // 发送任务请求
            $result = $this->taskService->sendScriptTask($scriptPath, $params);
            
            if ($result['success']) {
                return $this->json(200, '测试脚本任务已提交', [
                    'request_id' => $result['request_id'],
                    'task_type' => $result['task_type'],
                    'script_path' => $scriptPath,
                    'script_name' => $scriptName,
                    'params' => $params
                ]);
            } else {
                return $this->json(500, $result['error'] ?? '任务提交失败');
            }
            
        } catch (\Exception $e) {
            return $this->json(500, '服务器错误: ' . $e->getMessage());
        }
    }

    /**
     * 测试 OpenClaw 调用
     * POST /api/test/openclaw
     * 
     * Body: {
     *   "agent_id": "hc-coding",
     *   "message": "测试消息",
     *   "options": {"timeout": 60}
     * }
     */
    public function testOpenClaw()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?: [];
            
            $agentId = $body['agent_id'] ?? 'hc-coding';
            $message = $body['message'] ?? '系统测试消息 - ' . date('Y-m-d H:i:s');
            $options = $body['options'] ?? [];
            
            $result = $this->taskService->sendOpenClawTask($agentId, $message, $options);
            
            if ($result['success']) {
                return $this->json(200, 'OpenClaw 任务已提交', [
                    'request_id' => $result['request_id'],
                    'task_type' => $result['task_type'],
                    'agent_id' => $agentId,
                    'message' => $message,
                    'options' => $options
                ]);
            } else {
                return $this->json(500, $result['error'] ?? '任务提交失败');
            }
            
        } catch (\Exception $e) {
            return $this->json(500, '服务器错误: ' . $e->getMessage());
        }
    }

    /**
     * 测试 AI 调用
     * POST /api/test/ai
     * 
     * Body: {
     *   "provider": "openai",
     *   "model": "gpt-3.5-turbo", 
     *   "prompt": "Hello, how are you?"
     * }
     */
    public function testAi()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?: [];
            
            $provider = $body['provider'] ?? 'openai';
            $model = $body['model'] ?? 'gpt-3.5-turbo';
            $prompt = $body['prompt'] ?? 'Hello from AdminPro!';
            $options = $body['options'] ?? [];
            
            $result = $this->taskService->sendAiTask($provider, $model, $prompt, $options);
            
            if ($result['success']) {
                return $this->json(200, 'AI 任务已提交', [
                    'request_id' => $result['request_id'],
                    'task_type' => $result['task_type'],
                    'provider' => $provider,
                    'model' => $model,
                    'prompt' => $prompt
                ]);
            } else {
                return $this->json(500, $result['error'] ?? '任务提交失败');
            }
            
        } catch (\Exception $e) {
            return $this->json(500, '服务器错误: ' . $e->getMessage());
        }
    }

    /**
     * 获取连接状态和待处理任务
     * GET /api/test/status
     */
    public function status()
    {
        try {
            $connectionStatus = $this->taskService->getConnectionStatus();
            $pendingRequests = $this->taskService->getPendingRequests();
            
            // 清理过期请求
            $cleanedCount = $this->taskService->cleanupExpiredRequests();
            
            return $this->json(200, '状态获取成功', [
                'connection' => $connectionStatus,
                'pending_requests' => $pendingRequests,
                'cleaned_requests' => $cleanedCount,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            return $this->json(500, '获取状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 列出可用的测试脚本
     * GET /api/test/scripts
     */
    public function listScripts()
    {
        try {
            $scriptDirs = [
                __DIR__ . '/../../scripts',
                __DIR__ . '/../../../scripts',
                __DIR__ . '/../../storage/openclaw_scripts'
            ];
            
            $scripts = [];
            
            foreach ($scriptDirs as $dir) {
                $realDir = realpath($dir);
                if ($realDir && is_dir($realDir)) {
                    $files = glob($realDir . '/*.py');
                    foreach ($files as $file) {
                        $scripts[] = [
                            'name' => basename($file),
                            'path' => $file,
                            'dir' => basename($realDir),
                            'size' => filesize($file),
                            'modified' => date('Y-m-d H:i:s', filemtime($file))
                        ];
                    }
                }
            }
            
            return $this->json(200, '脚本列表获取成功', [
                'scripts' => $scripts,
                'total' => count($scripts)
            ]);
            
        } catch (\Exception $e) {
            return $this->json(500, '获取脚本列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 快速测试 - 运行 hello.py 脚本
     * POST /api/test/quick
     */
    public function quickTest()
    {
        try {
            $result = $this->taskService->sendScriptTask(
                realpath(__DIR__ . '/../../storage/openclaw_scripts/hello.py'),
                ['message' => 'Quick test from AdminPro at ' . date('Y-m-d H:i:s')]
            );
            
            if ($result['success']) {
                return $this->json(200, '快速测试已启动', [
                    'request_id' => $result['request_id'],
                    'message' => '运行 hello.py 脚本测试',
                    'tip' => '请检查 Bridge 服务日志查看执行结果'
                ]);
            } else {
                return $this->json(500, $result['error'] ?? '快速测试失败');
            }
            
        } catch (\Exception $e) {
            return $this->json(500, '快速测试异常: ' . $e->getMessage());
        }
    }
}