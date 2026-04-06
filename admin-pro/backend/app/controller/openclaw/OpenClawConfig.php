<?php
declare(strict_types=1);

namespace app\controller\openclaw;

use app\controller\BaseController;

/**
 * OpenClaw 模型配置管理
 * 
 * 直接读写 ~/.openclaw/openclaw.json
 * 实现 UI 中真正配置 OpenClaw 模型的功能
 */
class OpenClawConfig extends BaseController
{
    // openclaw.json 路径
    protected string $configPath;
    
    public function __construct()
    {
        parent::__construct();
        $home = getenv('HOME') ?: '/Users/hc';
        $this->configPath = $home . '/.openclaw/openclaw.json';
    }
    
    /**
     * 读取配置
     */
    protected function readConfig(): array
    {
        if (!file_exists($this->configPath)) {
            throw new \Exception('openclaw.json 不存在');
        }
        $content = file_get_contents($this->configPath);
        if (!$content) {
            throw new \Exception('无法读取 openclaw.json');
        }
        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('openclaw.json 解析失败: ' . json_last_error_msg());
        }
        return $config;
    }
    
    /**
     * 写入配置（原子操作）
     */
    protected function writeConfig(array $config): bool
    {
        $tmp = $this->configPath . '.tmp';
        $result = file_put_contents($tmp, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result === false) {
            throw new \Exception('写入临时文件失败');
        }
        if (!rename($tmp, $this->configPath)) {
            throw new \Exception('替换配置文件失败');
        }
        return true;
    }
    
    /**
     * 获取所有模型
     * GET /api/openclaw/config/models
     */
    public function index()
    {
        try {
            $config = $this->readConfig();
            $providers = $config['models']['providers'] ?? [];
            $primaryModel = $config['agents']['defaults']['model']['primary'] ?? '';
            $fallbackModel = $config['agents']['defaults']['model']['fallback'] ?? '';
            
            $models = [];
            $idx = 0;
            foreach ($providers as $providerId => $pc) {
                if (!isset($pc['models']) || !is_array($pc['models'])) {
                    continue;
                }
                foreach ($pc['models'] as $model) {
                    $modelPath = "{$providerId}/{$model['id']}";
                    $models[] = [
                        'id' => strval($idx++),
                        'modelId' => $model['id'],
                        'modelPath' => $modelPath,
                        'name' => $model['name'] ?? $model['id'],
                        'provider' => $providerId,
                        'baseUrl' => $pc['baseUrl'] ?? '',
                        'apiKey' => $pc['apiKey'] ?? '',
                        'api' => $pc['api'] ?? 'openai-completions',
                        'isPrimary' => $primaryModel === $modelPath,
                        'isFallback' => $fallbackModel === $modelPath,
                        'contextWindow' => $model['contextWindow'] ?? null,
                        'maxTokens' => $model['maxTokens'] ?? null,
                        'reasoning' => $model['reasoning'] ?? false,
                        'cost' => $model['cost'] ?? null,
                    ];
                }
            }
            
            return $this->json(200, '获取成功', [
                'models' => $models,
                'primaryModel' => $primaryModel,
                'fallbackModel' => $fallbackModel,
            ]);
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 保存模型（新增或更新）
     * PUT /api/openclaw/config/models
     */
    public function save()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $config = $this->readConfig();
            $providerId = $body['provider'] ?? '';
            if (empty($providerId)) {
                return $this->json(400, '提供商不能为空');
            }
            $modelId = $body['modelId'] ?? '';
            if (empty($modelId)) {
                return $this->json(400, '模型ID不能为空');
            }
            
            // 确保 providers 存在
            if (!isset($config['models']['providers'])) {
                $config['models']['providers'] = [];
            }
            
            $existingProvider = $config['models']['providers'][$providerId] ?? null;
            
            // 创建或更新 provider
            if (!$existingProvider) {
                $config['models']['providers'][$providerId] = [
                    'baseUrl' => $body['baseUrl'] ?? '',
                    'apiKey' => $body['apiKey'] ?? '',
                    'api' => $body['api'] ?? 'openai-completions',
                    'models' => [],
                ];
            } else {
                // 更新 provider 配置（保留原有值如果表单未提供）
                if (!empty($body['baseUrl'])) {
                    $config['models']['providers'][$providerId]['baseUrl'] = $body['baseUrl'];
                }
                if (!empty($body['apiKey'])) {
                    // 只覆盖非掩码值
                    if (!str_starts_with($body['apiKey'], '•') && $body['apiKey'] !== $existingProvider['apiKey'] ?? '') {
                        $config['models']['providers'][$providerId]['apiKey'] = $body['apiKey'];
                    }
                }
                if (!empty($body['api'])) {
                    $config['models']['providers'][$providerId]['api'] = $body['api'];
                }
            }
            
            $providers = &$config['models']['providers'];
            $pc = &$providers[$providerId];
            
            // 查找现模型或添加新模型
            $found = false;
            foreach ($pc['models'] as &$m) {
                if ($m['id'] === $modelId) {
                    $m['name'] = $body['name'] ?? ($m['name'] ?? $modelId);
                    $found = true;
                    break;
                }
            }
            unset($m);
            
            if (!$found) {
                $newModel = ['id' => $modelId];
                if (isset($body['name'])) {
                    $newModel['name'] = $body['name'];
                }
                if (isset($body['contextWindow'])) {
                    $newModel['contextWindow'] = (int)$body['contextWindow'];
                }
                if (isset($body['maxTokens'])) {
                    $newModel['maxTokens'] = (int)$body['maxTokens'];
                }
                if (isset($body['reasoning'])) {
                    $newModel['reasoning'] = (bool)$body['reasoning'];
                }
                if (isset($body['api'])) {
                    $newModel['api'] = $body['api'];
                }
                $pc['models'][] = $newModel;
            }
            
            // 设置主模型和备用模型
            if (!empty($body['isPrimary'])) {
                $fullPath = "{$providerId}/{$modelId}";
                $config['agents']['defaults']['model']['primary'] = $fullPath;
            }
            if (!empty($body['isFallback'])) {
                $fullPath = "{$providerId}/{$modelId}";
                $config['agents']['defaults']['model']['fallback'] = $fullPath;
            }
            
            $this->writeConfig($config);
            return $this->json(200, '保存成功');
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 删除模型
     * DELETE /api/openclaw/config/models/:provider/:modelId
     */
    public function delete()
    {
        try {
            $providerId = $this->input('route.provider');
            // 处理 modelId 中可能包含 / (如 qwen/qwen3.6-plus:free)
            $modelId = $this->input('route.modelId');
            
            if (empty($providerId)) {
                return $this->json(400, '提供商不能为空');
            }
            if (empty($modelId)) {
                return $this->json(400, '模型ID不能为空');
            }
            
            $config = $this->readConfig();
            $providers = $config['models']['providers'] ?? [];
            
            if (!isset($providers[$providerId])) {
                return $this->json(404, '提供商不存在');
            }
            
            $pc = &$providers[$providerId];
            if (!isset($pc['models']) || !is_array($pc['models'])) {
                return $this->json(404, '模型列表不存在');
            }
            
            $newModels = [];
            $found = false;
            foreach ($pc['models'] as $m) {
                if ($m['id'] === $modelId) {
                    $found = true;
                    continue;
                }
                $newModels[] = $m;
            }
            
            if (!$found) {
                return $this->json(404, '模型不存在');
            }
            
            $pc['models'] = $newModels;
            if (empty($pc['models'])) {
                unset($config['models']['providers'][$providerId]);
            }
            
            // 如果删除的是默认模型，清除 primary
            $primaryModel = $config['agents']['defaults']['model']['primary'] ?? '';
            if ($primaryModel === "{$providerId}/{$modelId}") {
                $config['agents']['defaults']['model']['primary'] = '';
            }
            
            $this->writeConfig($config);
            return $this->json(200, '删除成功');
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 测试连通性
     * POST /api/openclaw/config/models/test
     */
    public function test()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $baseUrl = rtrim($body['baseUrl'] ?? '', '/');
            $apiKey = $body['apiKey'] ?? '';
            $apiType = $body['api'] ?? 'openai-completions';
            
            if (empty($baseUrl)) {
                return $this->json(400, 'Base URL 不能为空');
            }
            
            // 如果 baseUrl 已包含 /v1，不再重复拼接
            $hasV1 = str_ends_with($baseUrl, '/v1');
            
            $ch = curl_init();
            
            // Anthropic API
            if ($apiType === 'anthropic' || (stripos($baseUrl, 'anthropic') !== false)) {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . ($hasV1 ? '/messages' : '/v1/messages'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . $apiKey,
                        'anthropic-version: 2023-06-01',
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $body['modelId'] ?? 'claude-3-haiku-20240307',
                        'max_tokens' => 10,
                        'messages' => [['role' => 'user', 'content' => 'test']],
                    ]),
                ]);
            } else {
                // OpenAI compatible
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . ($hasV1 ? '/models' : '/v1/models'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                ]);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            @curl_close($ch);
            
            if ($error) {
                return $this->json(200, 'success', [
                    'success' => false,
                    'message' => '连接失败: ' . $error,
                ]);
            }
            
            if ($httpCode >= 200 && $httpCode < 500) {
                // 200-499 说明服务器可达
                return $this->json(200, 'success', [
                    'success' => true,
                    'message' => '连接成功 (HTTP ' . $httpCode . ')',
                    'httpCode' => $httpCode,
                ]);
            }
            
            return $this->json(200, 'success', [
                'success' => false,
                'message' => '连接失败 (HTTP ' . $httpCode . ')',
                'response' => substr($response, 0, 500),
            ]);
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 对话测试
     * POST /api/openclaw/config/models/chat-test
     */
    public function chatTest()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $baseUrl = rtrim($body['baseUrl'] ?? '', '/');
            $apiKey = $body['apiKey'] ?? '';
            $modelId = $body['modelId'] ?? '';
            $apiType = $body['api'] ?? 'openai-completions';
            $message = $body['message'] ?? 'Hello, reply with OK only';
            
            if (empty($baseUrl) || empty($modelId)) {
                return $this->json(400, 'Base URL 和模型ID不能为空');
            }
            
            // 如果 baseUrl 已包含 /v1，不再重复拼接
            $hasV1 = str_ends_with($baseUrl, '/v1');
            
            $ch = curl_init();
            
            if ($apiType === 'anthropic' || (stripos($baseUrl, 'anthropic') !== false)) {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . ($hasV1 ? '/messages' : '/v1/messages'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . $apiKey,
                        'anthropic-version: 2023-06-01',
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $modelId,
                        'max_tokens' => 256,
                        'messages' => [['role' => 'user', 'content' => $message]],
                    ]),
                ]);
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . ($hasV1 ? '/chat/completions' : '/v1/chat/completions'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $modelId,
                        'max_tokens' => 256,
                        'messages' => [['role' => 'user', 'content' => $message]],
                    ]),
                ]);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            @curl_close($ch);
            
            if ($error) {
                return $this->json(200, 'success', [
                    'success' => false,
                    'message' => '连接失败: ' . $error,
                ]);
            }
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $reply = '';
                if (isset($data['choices'][0]['message']['content'])) {
                    $reply = $data['choices'][0]['message']['content'];
                } elseif (isset($data['content'][0]['text'])) {
                    $reply = $data['content'][0]['text'];
                }
                
                return $this->json(200, 'success', [
                    'success' => true,
                    'reply' => $reply ?: '收到响应',
                ]);
            }
            
            // 详细的错误处理
            $errorMsg = 'HTTP ' . $httpCode;
            $data = json_decode($response, true);
            
            if ($httpCode === 429) {
                $errorMsg = '速率限制 - 请稍后再试';
                if (isset($data['error']['message'])) {
                    $errorMsg .= ': ' . $data['error']['message'];
                }
            } elseif ($httpCode === 401) {
                $errorMsg = 'API密钥无效或已过期';
            } elseif ($httpCode === 403) {
                $errorMsg = '权限不足或余额不足';
            } elseif ($httpCode === 400) {
                $errorMsg = '请求参数错误';
                if (isset($data['error']['message'])) {
                    $errorMsg .= ': ' . $data['error']['message'];
                }
            } elseif ($httpCode >= 500) {
                $errorMsg = '服务器内部错误';
            }
            
            return $this->json(200, 'success', [
                'success' => false,
                'message' => $errorMsg,
                'httpCode' => $httpCode,
                'response' => substr($response, 0, 300),
            ]);
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 设置主模型
     */
    public function setPrimary()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $providerId = $body['provider'] ?? '';
            $modelId = $body['modelId'] ?? '';
            if (empty($providerId) || empty($modelId)) {
                return $this->json(400, '提供商和模型ID不能为空');
            }
            
            $config = $this->readConfig();
            $fullPath = "{$providerId}/{$modelId}";
            $config['agents']['defaults']['model']['primary'] = $fullPath;
            
            $this->writeConfig($config);
            return $this->json(200, '设置成功');
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 设置备用模型
     */
    public function setFallback()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $providerId = $body['provider'] ?? '';
            $modelId = $body['modelId'] ?? '';
            if (empty($providerId) || empty($modelId)) {
                return $this->json(400, '提供商和模型ID不能为空');
            }
            
            $config = $this->readConfig();
            $fullPath = "{$providerId}/{$modelId}";
            $config['agents']['defaults']['model']['fallback'] = $fullPath;
            
            $this->writeConfig($config);
            return $this->json(200, '设置成功');
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
    
    /**
     * 取消备用模型
     */
    public function removeFallback()
    {
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (!$body) {
                return $this->json(400, '请求数据为空');
            }
            
            $providerId = $body['provider'] ?? '';
            $modelId = $body['modelId'] ?? '';
            if (empty($providerId) || empty($modelId)) {
                return $this->json(400, '提供商和模型ID不能为空');
            }
            
            $config = $this->readConfig();
            $fullPath = "{$providerId}/{$modelId}";
            
            $currentFallback = $config['agents']['defaults']['model']['fallback'] ?? '';
            if ($currentFallback === $fullPath) {
                $config['agents']['defaults']['model']['fallback'] = '';
            }
            
            $this->writeConfig($config);
            return $this->json(200, '取消成功');
        } catch (\Exception $e) {
            return $this->json(500, $e->getMessage());
        }
    }
}
