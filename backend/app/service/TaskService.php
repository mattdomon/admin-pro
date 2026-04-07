<?php
declare(strict_types=1);

namespace app\service;

class TaskService
{
    private WebSocketClient $wsClient;
    private array $pendingRequests = [];

    public function __construct()
    {
        $this->wsClient = new WebSocketClient();
    }

    /**
     * 发送任务请求到 Bridge 服务
     */
    public function sendTaskRequest(string $taskType, array $payload): array
    {
        $requestId = $this->generateRequestId();
        
        $message = [
            'type' => 'task_request',
            'task_type' => $taskType,
            'request_id' => $requestId,
            'timestamp' => time(),
            'source' => 'admin-pro-php',
            'payload' => $payload
        ];

        // 记录待处理请求
        $this->pendingRequests[$requestId] = [
            'sent_at' => time(),
            'task_type' => $taskType,
            'payload' => $payload
        ];

        // 先尝试 WebSocket 连接
        $success = $this->wsClient->sendMessage($message);
        
        if (!$success) {
            // WebSocket 失败，尝试 HTTP API 备用方案
            $httpResult = $this->sendViaHttpApi($taskType, $payload);
            if ($httpResult['success']) {
                return [
                    'success' => true,
                    'request_id' => $requestId,
                    'bridge_task_id' => $httpResult['task_id'],
                    'message' => '任务已通过 HTTP API 提交到 Bridge 服务',
                    'task_type' => $taskType,
                    'method' => 'http_api'
                ];
            }
            
            // 清理失败的请求记录
            unset($this->pendingRequests[$requestId]);
            
            return [
                'success' => false,
                'error' => 'WebSocket 和 HTTP API 都无法连接 Bridge 服务',
                'request_id' => $requestId,
                'details' => $httpResult['error'] ?? 'HTTP API 调用失败'
            ];
        }

        return [
            'success' => true,
            'request_id' => $requestId,
            'message' => '任务请求已通过 WebSocket 发送到 Bridge 服务',
            'task_type' => $taskType,
            'method' => 'websocket'
        ];
    }
    
    /**
     * 通过 HTTP API 发送任务（备用方案）
     */
    private function sendViaHttpApi(string $taskType, array $payload): array
    {
        try {
            $bridgeUrl = 'http://localhost:9999/api/tasks';
            $data = [
                'type' => $taskType,
                'payload' => $payload
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/json\r\n",
                    'method' => 'POST',
                    'content' => json_encode($data),
                    'timeout' => 10
                ]
            ];
            
            $context = stream_context_create($options);
            $result = file_get_contents($bridgeUrl, false, $context);
            
            if ($result === false) {
                return ['success' => false, 'error' => '无法连接到 Bridge HTTP API'];
            }
            
            $response = json_decode($result, true);
            if (!$response || !$response['success']) {
                return [
                    'success' => false, 
                    'error' => $response['error'] ?? 'Bridge API 返回错误'
                ];
            }
            
            return [
                'success' => true,
                'task_id' => $response['task_id']
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'HTTP API 调用异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 发送脚本执行任务
     */
    public function sendScriptTask(string $scriptPath, array $params = []): array
    {
        // 验证脚本文件存在
        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error' => "脚本文件不存在: {$scriptPath}"
            ];
        }

        // 验证文件权限
        if (!is_readable($scriptPath)) {
            return [
                'success' => false,
                'error' => "脚本文件不可读: {$scriptPath}"
            ];
        }

        return $this->sendTaskRequest('script', [
            'script_path' => $scriptPath,
            'params' => $params,
            'working_dir' => dirname($scriptPath),
            'timeout' => 300 // 5分钟超时
        ]);
    }

    /**
     * 发送 OpenClaw 任务
     */
    public function sendOpenClawTask(string $agentId, string $message, array $options = []): array
    {
        // 验证 agentId
        if (empty($agentId)) {
            return [
                'success' => false,
                'error' => 'Agent ID 不能为空'
            ];
        }

        return $this->sendTaskRequest('openclaw', [
            'agent_id' => $agentId,
            'message' => $message,
            'options' => array_merge([
                'timeout' => 60,
                'priority' => 'normal'
            ], $options)
        ]);
    }

    /**
     * 发送 AI 处理任务
     */
    public function sendAiTask(string $provider, string $model, string $prompt, array $options = []): array
    {
        return $this->sendTaskRequest('ai', [
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'options' => $options
        ]);
    }

    /**
     * 发送 Webhook 调用任务
     */
    public function sendWebhookTask(string $url, string $method = 'POST', array $data = [], array $headers = []): array
    {
        return $this->sendTaskRequest('webhook', [
            'url' => $url,
            'method' => strtoupper($method),
            'data' => $data,
            'headers' => $headers,
            'timeout' => 30
        ]);
    }

    /**
     * 获取待处理的请求列表
     */
    public function getPendingRequests(): array
    {
        return $this->pendingRequests;
    }

    /**
     * 清理过期的请求记录（超过1小时）
     */
    public function cleanupExpiredRequests(): int
    {
        $cleaned = 0;
        $cutoff = time() - 3600; // 1小时前
        
        foreach ($this->pendingRequests as $requestId => $request) {
            if ($request['sent_at'] < $cutoff) {
                unset($this->pendingRequests[$requestId]);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }

    /**
     * 标记请求为已完成
     */
    public function markRequestCompleted(string $requestId): void
    {
        unset($this->pendingRequests[$requestId]);
    }

    /**
     * 检查 WebSocket 连接状态
     */
    public function getConnectionStatus(): array
    {
        $isConnected = $this->wsClient->isConnected();
        
        return [
            'connected' => $isConnected,
            'pending_requests' => count($this->pendingRequests),
            'status' => $isConnected ? 'online' : 'offline'
        ];
    }

    /**
     * 生成唯一的请求ID
     */
    private function generateRequestId(): string
    {
        return 'req_' . date('YmdHis') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        $this->wsClient->close();
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
    }
}