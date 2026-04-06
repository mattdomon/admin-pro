<?php
declare(strict_types=1);

namespace app\api\controller;

use app\controller\BaseController;
use app\model\LlmProvider;
use app\model\Task;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use think\Request;

/**
 * LLM 代理控制器
 * 实现主备模型自动切换的透明代理
 */
class LlmProxy extends BaseController
{
    private Client $httpClient;
    
    public function initialize()
    {
        parent::initialize();
        
        // 初始化 Guzzle HTTP 客户端
        $this->httpClient = new Client([
            'timeout' => 60,           // 总超时时间
            'connect_timeout' => 10,   // 连接超时时间
            'http_errors' => false,    // 不自动抛出4xx/5xx异常，我们手动处理
        ]);
    }
    
    /**
     * LLM 代理接口
     * POST /api/llm_proxy/v1/chat/completions
     * 
     * 实现功能：
     * 1. 解析 TaskToken 鉴权
     * 2. 查询主备模型配置
     * 3. 尝试主模型请求
     * 4. 失败时自动切换备用模型
     * 5. 返回最终结果
     */
    public function chatCompletions(Request $request)
    {
        try {
            // 1. 提取和验证 TaskToken
            $taskToken = $this->extractTaskToken($request);
            if (!$taskToken) {
                return $this->json(401, 'Missing or invalid Authorization header');
            }
            
            // 2. 查询任务配置和模型信息
            $taskConfig = $this->getTaskConfig($taskToken);
            if (!$taskConfig) {
                return $this->json(403, 'Invalid task token or task not found');
            }
            
            // 3. 获取请求体
            $requestData = json_decode($request->getContent(), true);
            if (!$requestData) {
                return $this->json(400, 'Invalid JSON request body');
            }
            
            // 4. 尝试主模型请求
            Log::info('LLM_PROXY_DEBUG', [
                'step' => 'attempting_primary_model',
                'task_token' => $taskToken,
                'primary_model' => $taskConfig['primary_model']['name'],
                'fallback_available' => !empty($taskConfig['fallback_model'])
            ]);
            
            $primaryResult = $this->attemptModelRequest(
                $taskConfig['primary_model'], 
                $requestData, 
                'primary'
            );
            
            Log::info('LLM_PROXY_DEBUG', [
                'step' => 'primary_result',
                'success' => $primaryResult['success'],
                'status_code' => $primaryResult['status_code'],
                'error' => $primaryResult['success'] ? null : $primaryResult['error']
            ]);
            
            if ($primaryResult['success']) {
                // 主模型成功，记录日志并返回
                $this->logRequest($taskConfig['task_id'], $taskConfig['primary_model']['id'], 'success', 'primary_model_used');
                return $this->formatResponse($primaryResult['response']);
            }
            
            // 5. 主模型失败，检查是否有备用模型且满足切换条件
            $hasFallback = !empty($taskConfig['fallback_model']);
            $shouldSwitch = $this->shouldFallback($primaryResult['status_code']);
            
            Log::info('LLM_PROXY_DEBUG', [
                'step' => 'fallback_decision',
                'has_fallback_model' => $hasFallback,
                'should_fallback' => $shouldSwitch,
                'primary_status_code' => $primaryResult['status_code'],
                'fallback_model_name' => $hasFallback ? $taskConfig['fallback_model']['name'] : null
            ]);
            
            if ($hasFallback && $shouldSwitch) {
                
                // 记录主模型失败
                $this->logRequest(
                    $taskConfig['task_id'], 
                    $taskConfig['primary_model']['id'], 
                    'failed', 
                    "Primary model failed: {$primaryResult['error']}"
                );
                
                Log::info('LLM_PROXY_DEBUG', [
                    'step' => 'attempting_fallback_model',
                    'fallback_model' => $taskConfig['fallback_model']['name']
                ]);
                
                // 尝试备用模型
                $fallbackResult = $this->attemptModelRequest(
                    $taskConfig['fallback_model'], 
                    $requestData, 
                    'fallback'
                );
                
                Log::info('LLM_PROXY_DEBUG', [
                    'step' => 'fallback_result',
                    'success' => $fallbackResult['success'],
                    'status_code' => $fallbackResult['status_code'],
                    'error' => $fallbackResult['success'] ? null : $fallbackResult['error']
                ]);
                
                if ($fallbackResult['success']) {
                    // 备用模型成功
                    $this->logRequest($taskConfig['task_id'], $taskConfig['fallback_model']['id'], 'success', 'fallback_model_used');
                    return $this->formatResponse($fallbackResult['response']);
                } else {
                    // 备用模型也失败
                    $this->logRequest(
                        $taskConfig['task_id'], 
                        $taskConfig['fallback_model']['id'], 
                        'failed', 
                        "Fallback model failed: {$fallbackResult['error']}"
                    );
                    
                    return $this->json(500, 'Both primary and fallback models failed', [
                        'primary_error' => $primaryResult['error'],
                        'fallback_error' => $fallbackResult['error']
                    ]);
                }
            } else {
                // 没有备用模型或不满足切换条件
                $this->logRequest(
                    $taskConfig['task_id'], 
                    $taskConfig['primary_model']['id'], 
                    'failed', 
                    "Primary model failed, no fallback: {$primaryResult['error']}"
                );
                
                return $this->json(
                    $primaryResult['status_code'] ?: 500, 
                    'Request failed', 
                    ['error' => $primaryResult['error']]
                );
            }
            
        } catch (\Exception $e) {
            // 在处理逼网络错误时，不要直接返回异常，而是记录日志并返回错误
            Log::error('LLM Proxy Exception: ' . $e->getMessage());
            
            // 检查是否是 RequestException，如果是则尝试重新抛出让内部处理
            if (strpos($e->getMessage(), 'cURL error') !== false) {
                // 这是网络错误，不应该在这里被捕获
                Log::error('LLM Proxy: Network error should have been handled internally', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            return $this->json(500, 'Internal server error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 从请求头中提取 TaskToken
     */
    private function extractTaskToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            return null;
        }
        
        // 匹配 "Bearer {token}" 格式
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * 根据 TaskToken 获取任务配置和模型信息
     */
    private function getTaskConfig(string $taskToken): ?array
    {
        // 在 oc_tasks 表中查找对应的任务（使用 id 字段作为 TaskToken）
        $task = Task::where('id', $taskToken)
            ->where('status', '!=', 'deleted')
            ->find();
            
        if (!$task) {
            Log::error('LLM_PROXY_ERROR', ['error' => 'Task not found', 'token' => $taskToken]);
            return null;
        }
        
        // 解析任务参数，获取主备模型ID（假设存储在 params_json 中）
        $params = $task->params_json ?? [];
        $primaryModelId = $params['primary_model_id'] ?? null;
        $fallbackModelId = $params['fallback_model_id'] ?? null;
        
        Log::info('LLM_PROXY_DEBUG', [
            'step' => 'parsing_task_config',
            'task_id' => $task->id,
            'primary_model_id' => $primaryModelId,
            'fallback_model_id' => $fallbackModelId
        ]);
        
        if (!$primaryModelId) {
            Log::error('LLM_PROXY_ERROR', ['error' => 'Primary model ID not found', 'params' => $params]);
            return null;
        }
        
        // 查询主模型
        $primaryModel = LlmProvider::find($primaryModelId);
        if (!$primaryModel) {
            Log::error('LLM_PROXY_ERROR', ['error' => 'Primary model not found in database', 'id' => $primaryModelId]);
            return null;
        }
        
        // 查询备用模型（可选）
        $fallbackModel = null;
        if ($fallbackModelId) {
            $fallbackModel = LlmProvider::find($fallbackModelId);
            if (!$fallbackModel) {
                Log::warning('LLM_PROXY_WARNING', ['warning' => 'Fallback model not found in database', 'id' => $fallbackModelId]);
            }
        }
        
        $config = [
            'task_id' => $task->id,
            'task_token' => $taskToken,
            'primary_model' => [
                'id' => $primaryModel->id,
                'name' => $primaryModel->model_name ?: 'Unknown Primary Model',
                'base_url' => $primaryModel->base_url,
                'api_key' => $primaryModel->api_key,
                'model_id' => $primaryModel->provider_type === 'openai' ? 'gpt-4' : 
                             ($primaryModel->provider_type === 'minimax' ? 'MiniMax-M2.7' : 'claude-3-5-haiku-latest'), // 根据provider_type推导
            ],
            'fallback_model' => $fallbackModel ? [
                'id' => $fallbackModel->id,
                'name' => $fallbackModel->model_name ?: 'Unknown Fallback Model',
                'base_url' => $fallbackModel->base_url,
                'api_key' => $fallbackModel->api_key,
                'model_id' => $fallbackModel->provider_type === 'openai' ? 'gpt-4' : 
                             ($fallbackModel->provider_type === 'minimax' ? 'MiniMax-M2.7' : 'claude-3-5-haiku-latest'),
            ] : null,
        ];
        
        Log::info('LLM_PROXY_DEBUG', [
            'step' => 'task_config_loaded',
            'primary_name' => $config['primary_model']['name'],
            'fallback_name' => $config['fallback_model'] ? $config['fallback_model']['name'] : 'None'
        ]);
        
        return $config;
    }
    
    /**
     * 向指定模型发起请求
     */
    private function attemptModelRequest(array $modelConfig, array $requestData, string $modelType): array
    {
        // 记录调试信息
        Log::info('LLM_PROXY_DEBUG', [
            'model_type' => $modelType,
            'model_name' => $modelConfig['name'],
            'base_url' => $modelConfig['base_url'],
            'model_id' => $modelConfig['model_id'],
            'api_key_prefix' => substr($modelConfig['api_key'], 0, 10) . '...'
        ]);
        
        try {
            // 修改请求中的model字段为真实的模型ID
            $requestData['model'] = $modelConfig['model_id'];
            
            // 确保非流式请求
            $requestData['stream'] = false;
            
            // 构建请求
            $response = $this->httpClient->post($modelConfig['base_url'] . '/v1/chat/completions', [
                'json' => $requestData,
                'headers' => [
                    'Authorization' => 'Bearer ' . $modelConfig['api_key'],
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'admin-pro-proxy/1.0',
                ],
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            
            Log::info('LLM_PROXY_DEBUG', [
                'model_type' => $modelType,
                'status_code' => $statusCode,
                'response_length' => strlen($responseBody)
            ]);
            
            // 检查HTTP状态码
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'response' => $responseBody,
                    'status_code' => $statusCode,
                    'model_type' => $modelType
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "HTTP {$statusCode}: {$responseBody}",
                    'status_code' => $statusCode,
                    'model_type' => $modelType
                ];
            }
            
        } catch (RequestException $e) {
            // 网络错误或连接错误时，response可能为null
            $statusCode = 0; // 默认网络错误状态码
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
            }
            
            $errorMessage = $e->getMessage();
            
            Log::error('LLM_PROXY_ERROR', [
                'model_type' => $modelType,
                'error' => $errorMessage,
                'status_code' => $statusCode,
                'has_response' => $e->hasResponse()
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $statusCode,
                'model_type' => $modelType
            ];
        } catch (\Exception $e) {
            // 捕获所有其他异常（包括 cURL 错误）
            Log::error('LLM_PROXY_ERROR', [
                'model_type' => $modelType,
                'error' => $e->getMessage(),
                'error_type' => get_class($e)
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 0, // 网络错误
                'model_type' => $modelType
            ];
        }
    }
    
    /**
     * 判断是否应该切换到备用模型
     * 条件：429 (Too Many Requests) 或 5xx (Server Error) 或 0 (网络连接错误)
     */
    private function shouldFallback(int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode < 600) || $statusCode === 0;
    }
    
    /**
     * 格式化响应返回给客户端
     */
    private function formatResponse(string $responseBody): \think\Response
    {
        // 解析JSON确保格式正确
        $data = json_decode($responseBody, true);
        if (!$data) {
            return $this->json(500, 'Invalid response format from model');
        }
        
        // 直接返回模型的响应，保持透明性
        return response($responseBody)
            ->code(200)
            ->header('Content-Type', 'application/json');
    }
    
    /**
     * 记录请求日志
     */
    private function logRequest(string $taskId, int $modelId, string $status, string $details = ''): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'task_id' => $taskId,
            'model_id' => $modelId,
            'status' => $status,
            'details' => $details,
        ];
        
        Log::info('LLM_PROXY', $logData);
        
        // 可以考虑将重要信息写入数据库的操作日志表
        // OperationLog::create([
        //     'type' => 'llm_proxy',
        //     'content' => json_encode($logData),
        //     'created_at' => time()
        // ]);
    }
}