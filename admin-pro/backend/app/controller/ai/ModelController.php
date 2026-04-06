<?php
declare(strict_types=1);

namespace app\controller\ai;

use app\controller\BaseController;
use app\model\AiModel;

class ModelController extends BaseController
{
    /**
     * 模型列表
     */
    public function index()
    {
        $list = AiModel::select();
        return $this->json(200, '获取成功', $list);
    }
    
    /**
     * 创建模型
     */
    public function save()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        
        $data = [
            'name' => $body['name'] ?? '',
            'provider' => $body['provider'] ?? 'openai',
            'api_type' => $body['api_type'] ?? 'openai',
            'base_url' => $body['base_url'] ?? '',
            'api_key' => $body['api_key'] ?? '',
            'models' => $body['models'] ?? [],
            'is_active' => (bool) ($body['is_active'] ?? true),
            'is_default' => false,
            'is_backup' => false,
        ];
        
        if (empty($data['name'])) {
            return $this->json(400, '模型名称不能为空');
        }
        
        $result = AiModel::create($data);
        return $this->json(200, '创建成功', $result);
    }
    
    /**
     * 更新模型
     */
    public function update()
    {
        $id = (int) $this->input('route.id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }
        
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        
        $data = [];
        if (isset($body['name'])) $data['name'] = $body['name'];
        if (isset($body['provider'])) $data['provider'] = $body['provider'];
        if (isset($body['api_type'])) $data['api_type'] = $body['api_type'];
        if (isset($body['base_url'])) $data['base_url'] = $body['base_url'];
        if (isset($body['api_key'])) $data['api_key'] = $body['api_key'];
        if (isset($body['models'])) $data['models'] = $body['models'];
        if (isset($body['is_active'])) $data['is_active'] = (bool) $body['is_active'];
        
        $result = AiModel::update($id, $data);
        if ($result) {
            return $this->json(200, '更新成功', $result);
        }
        return $this->json(404, '模型不存在');
    }
    
    /**
     * 删除模型
     */
    public function delete()
    {
        $id = (int) $this->input('id') ?: (int) $this->input('route.id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }
        
        AiModel::delete($id);
        return $this->json(200, '删除成功');
    }
    
    /**
     * 测试连通性
     */
    public function test()
    {
        $id = (int) $this->input('route.id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }
        
        $model = AiModel::find($id);
        if (!$model) {
            return $this->json(404, '模型不存在');
        }
        
        try {
            $baseUrl = rtrim($model['base_url'], '/');
            $apiKey = $model['api_key'];
            
            // 根据 api_type 选择不同的测试端点
            if ($model['api_type'] === 'anthropic') {
                $url = $baseUrl . '/v1/messages';
                $headers = [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: 2023-06-01',
                    'anthropic-dangerous-direct-browser-access: true',
                ];
                $data = [
                    'model' => is_array($model['models']) && isset($model['models'][0]) ? $model['models'][0] : 'claude-3-haiku-20240307',
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'test']],
                ];
            } else {
                // OpenAI 兼容格式
                $url = $baseUrl . '/v1/models';
                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ];
                $data = [];
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, !empty($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($data) ? json_encode($data) : null);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            @curl_close($ch);
            
            if ($error) {
                return $this->json(500, '连接失败: ' . $error);
            }
            
            if ($httpCode === 200) {
                return $this->json(200, '连接成功', ['http_code' => $httpCode]);
            } else {
                return $this->json(500, '连接失败，HTTP状态码: ' . $httpCode, ['response' => substr($response, 0, 500)]);
            }
        } catch (\Exception $e) {
            return $this->json(500, '测试失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 设为默认
     */
    public function setDefault()
    {
        $id = (int) $this->input('route.id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }
        
        $model = AiModel::find($id);
        if (!$model) {
            return $this->json(404, '模型不存在');
        }
        
        // 先清除所有默认标记
        $all = AiModel::all();
        foreach ($all as $key => $item) {
            if ($item['is_default'] ?? false) {
                $all[$key]['is_default'] = false;
            }
        }
        AiModel::saveAll($all);
        
        // 设置新的默认
        $result = AiModel::update($id, ['is_default' => true]);
        if ($result) {
            return $this->json(200, '设置成功');
        }
        return $this->json(404, '模型不存在');
    }
    
    /**
     * 设为备用
     */
    public function setBackup()
    {
        $id = (int) $this->input('route.id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }
        
        $model = AiModel::find($id);
        if (!$model) {
            return $this->json(404, '模型不存在');
        }
        
        // 先清除所有备用标记
        $all = AiModel::all();
        foreach ($all as $key => $item) {
            if ($item['is_backup'] ?? false) {
                $all[$key]['is_backup'] = false;
            }
        }
        AiModel::saveAll($all);
        
        // 设置新的备用
        $result = AiModel::update($id, ['is_backup' => true]);
        if ($result) {
            return $this->json(200, '设置成功');
        }
        return $this->json(404, '模型不存在');
    }
}
