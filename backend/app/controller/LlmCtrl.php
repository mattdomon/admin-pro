<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\LlmProvider;

/**
 * 模型配置 API 控制器
 * 
 * 负责大模型密钥矩阵管理
 */
class LlmCtrl extends BaseController
{
    /**
     * 模型列表
     * GET /api/model/list
     */
    public function list()
    {
        $models = LlmProvider::order('is_fallback', 'desc')->select()->toArray();

        // API Key 脱敏
        foreach ($models as &$model) {
            if (!empty($model['api_key'])) {
                $key = $model['api_key'];
                $len = strlen($key);
                if ($len > 8) {
                    $model['api_key'] = substr($key, 0, 4) . str_repeat('*', $len - 8) . substr($key, -4);
                }
            }
        }

        return $this->json(200, '获取成功', $models);
    }

    /**
     * 新增模型
     * POST /api/model/create
     */
    public function create()
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $providerType = $body['provider_type'] ?? '';
        $modelName    = $body['model_name'] ?? '';
        $baseUrl      = $body['base_url'] ?? '';
        $apiKey       = $body['api_key'] ?? '';

        if (!$providerType || !$modelName) {
            return $this->json(400, '类型 and 模型ID不能为空');
        }

        $model = LlmProvider::create([
            'provider_type' => $providerType,
            'model_name'    => $modelName,
            'base_url'      => $baseUrl,
            'api_key'       => $apiKey,
            'is_fallback'   => (bool) ($body['is_fallback'] ?? false),
        ]);

        return $this->json(200, '创建成功', $model);
    }

    /**
     * 更新模型
     * POST /api/model/update/:id
     */
    public function update()
    {
        $id = (int) $this->input('id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $model = LlmProvider::find($id);

        if (!$model) {
            return $this->json(404, '模型不存在');
        }

        $data = [];
        if (isset($body['provider_type'])) $data['provider_type'] = $body['provider_type'];
        if (isset($body['model_name']))     $data['model_name'] = $body['model_name'];
        if (isset($body['base_url']))       $data['base_url'] = $body['base_url'];
        if (isset($body['api_key']))        $data['api_key'] = $body['api_key'];
        if (isset($body['is_fallback']))    $data['is_fallback'] = (bool) $body['is_fallback'];

        $model->save($data);

        return $this->json(200, '更新成功', $model);
    }

    /**
     * 删除模型
     * DELETE /api/model/delete/:id
     */
    public function delete()
    {
        $id = (int) $this->input('id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }

        $model = LlmProvider::find($id);
        if (!$model) {
            return $this->json(404, '模型不存在');
        }

        $model->delete();

        return $this->json(200, '删除成功');
    }

    /**
     * 设为备用模型
     * POST /api/model/setFallback/:id
     */
    public function setFallback()
    {
        $id = (int) $this->input('id');
        if (!$id) {
            return $this->json(400, 'ID不能为空');
        }

        $model = LlmProvider::find($id);
        if (!$model) {
            return $this->json(404, '模型不存在');
        }

        // 清除其他备用
        LlmProvider::where('is_fallback', true)->update(['is_fallback' => false]);

        $model->save(['is_fallback' => true]);

        return $this->json(200, '设置成功');
    }
}
