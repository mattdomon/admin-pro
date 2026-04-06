<?php
declare(strict_types=1);

namespace app\controller\ai;

use app\controller\BaseController;
use app\model\Model as AiModel;

class ChatController extends BaseController
{
    /**
     * 聊天接口 - 支持流式返回
     */
    public function chat()
    {
        $modelId = $this->input('model_id');
        $messages = $this->input('messages', []);
        $stream = $this->input('stream', false);
        $temperature = $this->input('temperature', 0.7);
        $max_tokens = $this->input('max_tokens', 2048);
        
        if (empty($messages)) {
            return $this->json(400, '消息不能为空');
        }
        
        // 获取模型配置
        $modelConfig = null;
        if ($modelId) {
            $modelConfig = AiModel::find((int) $modelId);
        }
        
        // 如果没有指定或找不到，使用默认模型
        if (!$modelConfig) {
            $modelConfig = AiModel::getDefault();
        }
        
        // 如果没有默认模型，尝试备用
        if (!$modelConfig) {
            $modelConfig = AiModel::getBackup();
        }
        
        if (!$modelConfig) {
            return $this->json(400, '没有可用的AI模型配置');
        }
        
        $baseUrl = rtrim($modelConfig['base_url'], '/');
        $apiKey = $modelConfig['api_key'];
        $apiType = $modelConfig['api_type'] ?? 'openai';
        
        try {
            if ($apiType === 'anthropic') {
                return $this->chatAnthropic($baseUrl, $apiKey, $messages, $stream, $temperature, $max_tokens, $modelConfig);
            } else {
                return $this->chatOpenAI($baseUrl, $apiKey, $messages, $stream, $temperature, $max_tokens, $modelConfig);
            }
        } catch (\Exception $e) {
            return $this->json(500, '请求失败: ' . $e->getMessage());
        }
    }
    
    /**
     * OpenAI 兼容格式聊天
     */
    private function chatOpenAI($baseUrl, $apiKey, $messages, $stream, $temperature, $max_tokens, $modelConfig)
    {
        $url = $baseUrl . '/v1/chat/completions';
        
        // 获取模型名称
        $modelName = 'gpt-3.5-turbo';
        if (is_array($modelConfig['models']) && !empty($modelConfig['models'])) {
            $modelName = $modelConfig['models'][0];
        }
        
        $postData = [
            'model' => $modelName,
            'messages' => $messages,
            'temperature' => (float) $temperature,
            'max_tokens' => (int) $max_tokens,
        ];
        
        if ($stream) {
            $postData['stream'] = true;
        }
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        
        if ($stream) {
            return $this->streamRequest($url, $headers, $postData);
        }
        
        return $this->normalRequest($url, $headers, $postData);
    }
    
    /**
     * Anthropic 格式聊天
     */
    private function chatAnthropic($baseUrl, $apiKey, $messages, $stream, $temperature, $max_tokens, $modelConfig)
    {
        $url = $baseUrl . '/v1/messages';
        
        // 获取模型名称
        $modelName = 'claude-3-haiku-20240307';
        if (is_array($modelConfig['models']) && !empty($modelConfig['models'])) {
            $modelName = $modelConfig['models'][0];
        }
        
        // 转换消息格式
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                // Anthropic 使用特殊的 system 键
                continue;
            }
            $anthropicMessages[] = [
                'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $msg['content'],
            ];
        }
        
        $postData = [
            'model' => $modelName,
            'messages' => $anthropicMessages,
            'temperature' => (float) $temperature,
            'max_tokens' => (int) $max_tokens,
        ];
        
        if ($stream) {
            $postData['stream'] = true;
        }
        
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-dangerous-direct-browser-access: true',
        ];
        
        if ($stream) {
            return $this->streamRequest($url, $headers, $postData);
        }
        
        return $this->normalRequest($url, $headers, $postData);
    }
    
    /**
     * 普通请求
     */
    private function normalRequest($url, $headers, $postData)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            return $this->json(500, '请求失败: ' . $error);
        }
        
        if ($httpCode !== 200) {
            return $this->json($httpCode, '请求失败', ['response' => substr($response, 0, 500)]);
        }
        
        $result = json_decode($response, true);
        return $this->json(200, 'success', $result);
    }
    
    /**
     * 流式请求
     */
    private function streamRequest($url, $headers, $postData)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            return $this->json(500, '请求失败: ' . $error);
        }
        
        return response('', 200)->contentType('text/event-stream');
    }
}
