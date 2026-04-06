<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\TaskTemplate;

/**
 * 任务模板管理控制器
 */
class TaskTemplateCtrl extends BaseController
{
    /**
     * 模板列表
     * GET /api/template/list
     */
    public function list()
    {
        $templates = TaskTemplate::order('created_at', 'desc')
            ->select()
            ->toArray();

        return $this->json(200, '获取成功', $templates);
    }

    /**
     * 模板详情
     * GET /api/template/detail/:id
     */
    public function detail()
    {
        $id = $this->input('id', 0);
        $template = TaskTemplate::find($id);
        
        if (!$template) {
            return $this->json(404, '模板不存在');
        }

        return $this->json(200, '获取成功', $template);
    }

    /**
     * 创建模板
     * POST /api/template/create
     */
    public function create()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $name         = $body['name'] ?? '';
        $description  = $body['description'] ?? '';
        $scriptName   = $body['script_name'] ?? '';
        $defaultParams = $body['default_params'] ?? [];
        $priority     = $body['priority'] ?? 5;

        if (!$name) return $this->json(400, '模板名称不能为空');
        if (!$scriptName) return $this->json(400, '脚本名称不能为空');

        // 检查名称重复
        if (TaskTemplate::where('name', $name)->find()) {
            return $this->json(400, '模板名称已存在');
        }

        TaskTemplate::create([
            'name'            => $name,
            'description'     => $description,
            'script_name'     => $scriptName,
            'default_params'  => $defaultParams,
            'priority'        => $priority,
            'created_by'      => 'admin', // TODO: 从JWT获取用户
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->json(200, '模板创建成功');
    }

    /**
     * 更新模板
     * POST /api/template/update/:id
     */
    public function update()
    {
        $id = $this->input('id', 0);
        $template = TaskTemplate::find($id);
        
        if (!$template) {
            return $this->json(404, '模板不存在');
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $name         = $body['name'] ?? $template->name;
        $description  = $body['description'] ?? $template->description;
        $scriptName   = $body['script_name'] ?? $template->script_name;
        $defaultParams = $body['default_params'] ?? $template->default_params;
        $priority     = $body['priority'] ?? $template->priority;

        // 检查名称重复（排除自己）
        if ($name !== $template->name && TaskTemplate::where('name', $name)->find()) {
            return $this->json(400, '模板名称已存在');
        }

        TaskTemplate::where('id', $id)->update([
            'name'           => $name,
            'description'    => $description,
            'script_name'    => $scriptName,
            'default_params' => $defaultParams,
            'priority'       => $priority,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->json(200, '模板更新成功');
    }

    /**
     * 删除模板
     * DELETE /api/template/delete/:id
     */
    public function delete()
    {
        $id = $this->input('id', 0);
        $template = TaskTemplate::find($id);
        
        if (!$template) {
            return $this->json(404, '模板不存在');
        }

        TaskTemplate::destroy($id);
        
        return $this->json(200, '模板删除成功');
    }

    /**
     * 使用模板创建任务
     * POST /api/template/use/:id
     */
    public function useTemplate()
    {
        $id = $this->input('id', 0);
        $template = TaskTemplate::find($id);
        
        if (!$template) {
            return $this->json(404, '模板不存在');
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $deviceUuids = $body['device_uuids'] ?? [];
        $customParams = $body['custom_params'] ?? [];

        if (empty($deviceUuids)) {
            return $this->json(400, '请选择目标设备');
        }

        // 合并模板默认参数和用户自定义参数
        $finalParams = array_merge(
            $template->default_params ?: [],
            $customParams
        );

        // 调用批量任务控制器的方法
        $batchController = new BatchTaskCtrl();
        
        // 模拟请求体
        $mockBody = [
            'device_uuids' => $deviceUuids,
            'script_name'  => $template->script_name,
            'params_json'  => $finalParams,
            'priority'     => $template->priority,
            'strategy'     => $body['strategy'] ?? 'parallel',
        ];

        // 临时替换输入内容
        file_put_contents('php://temp', json_encode($mockBody));
        
        return $batchController->batchDispatch();
    }
}