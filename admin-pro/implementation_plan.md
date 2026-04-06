# LLM Proxy 代理实现方案

## 架构设计

```
客户端 -> ProxyController -> 主模型API -> (失败) -> 备用模型API -> 返回结果
         ↓                    ↓                      ↓
      TaskToken验证         Guzzle请求             异常重试机制
```

## 核心组件

### 1. 数据库表结构
```sql
-- 任务配置表
CREATE TABLE llm_tasks (
    id int PRIMARY KEY AUTO_INCREMENT,
    task_token varchar(64) UNIQUE NOT NULL,
    task_name varchar(100) NOT NULL,
    primary_model_id int NOT NULL,
    fallback_model_id int,
    fallback_strategy enum('auto','manual') DEFAULT 'auto',
    status tinyint DEFAULT 1,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

-- 模型配置表  
CREATE TABLE llm_models (
    id int PRIMARY KEY AUTO_INCREMENT,
    model_name varchar(100) NOT NULL,
    provider varchar(50) NOT NULL,
    base_url varchar(255) NOT NULL,
    api_key varchar(255) NOT NULL,
    model_id varchar(100) NOT NULL, -- 如: gpt-4, claude-3-sonnet
    max_tokens int DEFAULT 4000,
    temperature decimal(3,2) DEFAULT 0.7,
    status tinyint DEFAULT 1
);
```

### 2. ProxyController 核心逻辑

```php
<?php
namespace app\controller;

use think\Request;
use think\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use app\model\LlmTask;
use app\model\LlmModel;

class ProxyController
{
    private $client;
    
    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }
    
    /**
     * LLM 代理接口
     * POST /api/llm_proxy/v1/chat/completions
     */
    public function chatCompletions(Request $request)
    {
        // 1. 鉴权：提取 TaskToken
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !preg_match('/Bearer (.+)/', $authHeader, $matches)) {
            return json(['error' => 'Missing or invalid Authorization header'], 401);
        }
        
        $taskToken = $matches[1];
        
        // 2. 查询任务配置
        $task = LlmTask::where('task_token', $taskToken)
                      ->where('status', 1)
                      ->find();
        
        if (!$task) {
            return json(['error' => 'Invalid task token'], 403);
        }
        
        // 3. 获取主模型和备用模型配置
        $primaryModel = LlmModel::find($task->primary_model_id);
        $fallbackModel = $task->fallback_model_id ? LlmModel::find($task->fallback_model_id) : null;
        
        // 4. 获取请求体
        $requestData = $request->getContent();
        $requestJson = json_decode($requestData, true);
        
        // 5. 尝试主模型
        try {
            $response = $this->forwardRequest($primaryModel, $requestJson, $request);
            
            // 记录成功日志
            $this->logRequest($task->id, $primaryModel->id, 'success', null);
            
            return $response;
            
        } catch (RequestException $e) {
            // 6. 主模型失败，判断是否需要切换到备用模型
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            
            if ($this->shouldFallback($statusCode) && $fallbackModel && $task->fallback_strategy === 'auto') {
                
                // 记录主模型失败日志
                $this->logRequest($task->id, $primaryModel->id, 'failed', $e->getMessage());
                
                try {
                    $response = $this->forwardRequest($fallbackModel, $requestJson, $request);
                    
                    // 记录备用模型成功日志
                    $this->logRequest($task->id, $fallbackModel->id, 'success', 'fallback_used');
                    
                    return $response;
                    
                } catch (RequestException $fallbackError) {
                    // 备用模型也失败
                    $this->logRequest($task->id, $fallbackModel->id, 'failed', $fallbackError->getMessage());
                    
                    return json([
                        'error' => 'Both primary and fallback models failed',
                        'details' => [
                            'primary_error' => $e->getMessage(),
                            'fallback_error' => $fallbackError->getMessage()
                        ]
                    ], 500);
                }
            } else {
                // 不满足切换条件，直接返回原错误
                $this->logRequest($task->id, $primaryModel->id, 'failed', $e->getMessage());
                
                return json([
                    'error' => 'Request failed',
                    'message' => $e->getMessage()
                ], $statusCode ?: 500);
            }
        }
    }
    
    /**
     * 转发请求到指定模型
     */
    private function forwardRequest(LlmModel $model, array $requestData, Request $originalRequest)
    {
        // 修改请求数据中的模型名
        $requestData['model'] = $model->model_id;
        
        // 构建请求选项
        $options = [
            'json' => $requestData,
            'headers' => [
                'Authorization' => 'Bearer ' . $model->api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'admin-pro-proxy/1.0',
            ],
            'stream' => $originalRequest->param('stream', false), // 支持流式响应
        ];
        
        // 发送请求
        $response = $this->client->post($model->base_url . '/v1/chat/completions', $options);
        
        // 处理流式响应
        if ($originalRequest->param('stream')) {
            return $this->handleStreamResponse($response);
        } else {
            // 普通响应直接返回
            return Response::create($response->getBody()->getContents())
                          ->code($response->getStatusCode())
                          ->header('Content-Type', 'application/json');
        }
    }
    
    /**
     * 判断是否应该切换到备用模型
     */
    private function shouldFallback(int $statusCode): bool
    {
        // 429 (Too Many Requests) 或 5xx (服务器错误) 时切换
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode < 600);
    }
    
    /**
     * 处理流式响应
     */
    private function handleStreamResponse($guzzleResponse)
    {
        return Response::create()
                      ->code($guzzleResponse->getStatusCode())
                      ->header('Content-Type', 'text/event-stream')
                      ->header('Cache-Control', 'no-cache')
                      ->header('Connection', 'keep-alive')
                      ->data($guzzleResponse->getBody());
    }
    
    /**
     * 记录请求日志
     */
    private function logRequest(int $taskId, int $modelId, string $status, ?string $error = null)
    {
        // 记录到数据库或日志文件
        trace("LLM Proxy: Task={$taskId}, Model={$modelId}, Status={$status}, Error={$error}");
    }
}
```

### 3. 路由配置
```php
// route/api.php
Route::group('llm_proxy', function () {
    Route::post('v1/chat/completions', 'ProxyController/chatCompletions');
})->prefix('api/');
```

### 4. 模型管理接口
```php
// 添加模型
Route::post('models', 'ModelController/create');

// 创建任务Token  
Route::post('tasks', 'TaskController/create');

// 任务-模型绑定
Route::put('tasks/:id/models', 'TaskController/updateModels');
```

## 使用方式

```javascript
// 客户端调用
fetch('http://localhost:8000/api/llm_proxy/v1/chat/completions', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer task_abc123_xyz789',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    model: 'gpt-4', // 会被代理自动替换为真实配置的模型
    messages: [
      {role: 'user', content: 'Hello!'}
    ],
    stream: false
  })
})
```

## 优势

1. **透明代理**：客户端无需关心具体用哪个模型
2. **自动切换**：主模型失败自动使用备用模型  
3. **完整日志**：记录所有请求和切换行为
4. **流式支持**：原生支持 SSE 流式输出
5. **鉴权安全**：基于 TaskToken 的权限控制
6. **易于扩展**：可以轻松添加新的模型提供商

## 下一步

1. 创建数据库表和模型文件
2. 安装 Guzzle 依赖：`composer require guzzlehttp/guzzle`
3. 实现 ProxyController
4. 创建管理界面用于配置任务和模型
5. 添加监控和告警功能