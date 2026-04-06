<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\Task;
use app\service\OpenClawAdapter;

/**
 * 任务 API 控制器
 * 
 * 负责任务下发、状态查询、终止等
 */
class TaskCtrl extends BaseController
{
    /**
     * 任务列表
     * GET /api/task/list
     */
    public function list()
    {
        $status      = $this->input('status', '');
        $deviceUuid  = $this->input('device_uuid', '');

        $query = Task::order('created_at', 'desc');

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($deviceUuid !== '') {
            $query->where('device_uuid', $deviceUuid);
        }

        $tasks = $query->select()->toArray();

        return $this->json(200, '获取成功', $tasks);
    }

    /**
     * 任务详情
     * GET /api/task/detail/:id
     */
    public function detail()
    {
        $id = $this->input('id', '');
        if (empty($id)) {
            return $this->json(400, '任务ID不能为空');
        }

        $task = Task::find($id);
        if (!$task) {
            return $this->json(404, '任务不存在');
        }

        return $this->json(200, '获取成功', $task);
    }

    /**
     * 下发任务
     * POST /api/task/dispatch
     */
    public function dispatch()
    {
        $raw   = file_get_contents('php://input');
        $body  = json_decode($raw, true) ?: [];

        $deviceUuid = $body['device_uuid'] ?? '';
        $scriptName = $body['script_name'] ?? '';
        $params     = $body['params_json'] ?? [];

        if (!$deviceUuid) return $this->json(400, '设备UUID不能为空');
        if (!$scriptName) return $this->json(400, '脚本名称不能为空');

        $taskId = Task::generateId();

        // 写入数据库
        Task::create([
            'id'          => $taskId,
            'device_uuid' => $deviceUuid,
            'script_name' => $scriptName,
            'params_json' => $params,
            'status'      => Task::STATUS_PENDING,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // 更新任务状态为running
        Task::where('id', $taskId)->update(['status' => Task::STATUS_RUNNING]);

        // 通过适配器下发
        $adapter = new OpenClawAdapter();
        $result  = $adapter->dispatchTask($deviceUuid, $taskId, $scriptName, $params);

        if (!$result) {
            Task::where('id', $taskId)->update(['status' => Task::STATUS_FAILED]);
            return $this->json(500, '任务下发失败');
        }

        return $this->json(200, '任务已下发', [
            'task_id' => $taskId,
            'device_uuid' => $deviceUuid,
            'script_name' => $scriptName,
        ]);
    }

    /**
     * 终止任务
     * POST /api/task/kill
     */
    public function kill()
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $taskId = $body['task_id'] ?? '';
        if (!$taskId) return $this->json(400, '任务ID不能为空');

        $task = Task::find($taskId);
        if (!$task) return $this->json(404, '任务不存在');

        if ($task->status !== Task::STATUS_RUNNING) {
            return $this->json(400, '任务未在运行中，无法终止');
        }

        // 通过适配器发送终止指令
        $adapter = new OpenClawAdapter();
        $result  = $adapter->killTask($task->device_uuid, $taskId);

        if (!$result) {
            return $this->json(500, '终止指令发送失败');
        }

        return $this->json(200, '终止指令已发送');
    }
}
