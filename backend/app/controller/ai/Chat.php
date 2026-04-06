<?php
declare(strict_types=1);

namespace app\controller\ai;

use app\controller\BaseController;
use app\model\Model as AiModel;

class Chat extends BaseController
{
    /**
     * AI 对话接口
     * POST /api/ai/chat
     * 
     * 支持两种模型ID格式：
     * - model_id: 1,2,3 (本地 ai_models.json 的ID)
     * - model_path: claudeagent/claude-sonnet-4-6 (OpenClaw 配置的完整路径)
     */
    public function chat()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->error('请求数据为空');
            }
            
            $messages = $body['messages'] ?? [];
            $modelId = $body['model_id'] ?? null;
            $modelPath = $body['model_path'] ?? null;
            $stream = (bool)($body['stream'] ?? false);
            
            // 获取模型配置
            $modelConfig = null;
            
            // 方式1：通过 OpenClaw 配置的完整路径（如 claudeagent/claude-sonnet-4-6）
            if ($modelPath) {
                $modelConfig = $this->findModelByPath($modelPath);
            }
            // 方式2：通过本地模型ID
            elseif ($modelId) {
                $modelConfig = AiModel::find((int)$modelId);
            }
            
            if (!$modelConfig) {
                return $this->error('未找到模型配置');
            }
            
            $baseUrl = rtrim($modelConfig['baseUrl'] ?? '', '/');
            $apiKey = $modelConfig['apiKey'] ?? '';
            $apiType = $modelConfig['api'] ?? 'openai-completions';
            $modelIdentifier = $modelConfig['modelId'] ?? ($modelConfig['name'] ?? '');
            
            if (empty($baseUrl) || empty($apiKey)) {
                return $this->error('模型Base URL或API Key未配置');
            }
            
            // Anthropic API 格式
            if ($apiType === 'anthropic' || stripos($baseUrl, 'anthropic') !== false) {
                return $this->anthropicChat($baseUrl, $apiKey, $modelIdentifier, $messages, $stream);
            }
            
            // OpenAI 兼容格式
            return $this->openaiChat($baseUrl, $apiKey, $modelIdentifier, $messages, $stream);
        } catch (\Exception $e) {
            return $this->error('对话失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 根据路径查找 OpenClaw 配置中的模型
     */
    private function findModelByPath(string $modelPath): ?array
    {
        $configPath = getenv('HOME') . '/.openclaw/openclaw.json';
        if (!file_exists($configPath)) {
            return null;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        $providers = $config['models']['providers'] ?? [];
        
        foreach ($providers as $providerId => $providerConfig) {
            if (!isset($providerConfig['models']) || !is_array($providerConfig['models'])) {
                continue;
            }
            foreach ($providerConfig['models'] as $model) {
                $fullPath = "{$providerId}/{$model['id']}";
                if ($fullPath === $modelPath) {
                    return [
                        'modelId' => $model['id'],
                        'name' => $model['name'] ?? $model['id'],
                        'provider' => $providerId,
                        'baseUrl' => $providerConfig['baseUrl'] ?? '',
                        'apiKey' => $providerConfig['apiKey'] ?? '',
                        'api' => $model['api'] ?? $providerConfig['api'] ?? 'openai-completions',
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * OpenAI 兼容格式聊天
     */
    private function openaiChat(string $baseUrl, string $apiKey, string $modelId, array $messages, bool $stream)
    {
        $postData = [
            'model' => $modelId,
            'messages' => $messages,
        ];
        
        if ($stream) {
            $postData['stream'] = true;
        } else {
            $postData['max_tokens'] = 4096;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => !$stream,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
        ]);
        
        if ($stream) {
            return $this->handleStreamResponse($ch);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            return $this->error('请求失败: ' . $error);
        }
        
        if ($httpCode !== 200) {
            return $this->error('HTTP ' . $httpCode . ': ' . substr($response, 0, 500));
        }
        
        $data = json_decode($response, true);
        return $this->success('OK', $data);
    }
    
    /**
     * Anthropic 格式聊天
     */
    private function anthropicChat(string $baseUrl, string $apiKey, string $modelId, array $messages, bool $stream)
    {
        // 转换消息格式
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            $anthropicMessages[] = [
                'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $msg['content'] ?? '',
            ];
        }
        
        $postData = [
            'model' => $modelId,
            'messages' => $anthropicMessages,
            'max_tokens' => 4096,
        ];
        
        if ($stream) {
            $postData['stream'] = true;
        }
        
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/v1/messages',
            CURLOPT_RETURNTRANSFER => !$stream,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($postData),
        ]);
        
        if ($stream) {
            return $this->handleStreamResponse($ch);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            return $this->error('请求失败: ' . $error);
        }
        
        if ($httpCode !== 200) {
            return $this->error('HTTP ' . $httpCode . ': ' . substr($response, 0, 500));
        }
        
        $data = json_decode($response, true);
        return $this->success('OK', $data);
    }
    
    /**
     * 处理流式响应
     */
    private function handleStreamResponse($ch)
    {
        // 设置回调函数处理流式数据
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            echo "data: " . json_encode(['error' => $error]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        }
        
        exit;
    }
    
    private function success($message = 'OK', $data = null)
    {
        return json([
            'code' => 200,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    private function error($message)
    {
        return json([
            'code' => 500,
            'message' => $message,
            'data' => null
        ], 500);
    }
}
