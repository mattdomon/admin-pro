<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\Task;
use app\model\Device;
use app\model\TaskQueue;
use app\service\OpenClawAdapter;

/**
 * 批量任务调度控制器
 * 
 * 功能：
 * - 批量任务下发
 * - 任务队列管理 
 * - 负载均衡调度
 * - 任务模板管理
 */
class BatchTaskCtrl extends BaseController
{
    /**
     * 批量下发任务到多个节点
     * POST /api/batch/dispatch
     */
    public function batchDispatch()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $deviceUuids = $body['device_uuids'] ?? [];  // 目标设备列表
        $scriptName  = $body['script_name'] ?? '';    // 脚本名
        $params      = $body['params_json'] ?? [];    // 参数
        $priority    = $body['priority'] ?? 5;        // 优先级 1-10
        $strategy    = $body['strategy'] ?? 'parallel'; // parallel|sequential

        if (empty($deviceUuids)) {
            return $this->json(400, '请选择目标设备');
        }
        if (!$scriptName) {
            return $this->json(400, '脚本名称不能为空');
        }

        $batchId = 'batch_' . uniqid();
        $tasks   = [];
        $adapter = new OpenClawAdapter();

        foreach ($deviceUuids as $deviceUuid) {
            $taskId = Task::generateId();

            // 创建任务记录
            Task::create([
                'id'          => $taskId,
                'batch_id'    => $batchId,
                'device_uuid' => $deviceUuid,
                'script_name' => $scriptName,
                'params_json' => $params,
                'priority'    => $priority,
                'status'      => Task::STATUS_PENDING,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            // 根据策略处理
            if ($strategy === 'parallel') {
                // 并行：立即下发
                Task::where('id', $taskId)->update(['status' => Task::STATUS_RUNNING]);
                $result = $adapter->dispatchTask($deviceUuid, $taskId, $scriptName, $params);
                
                if (!$result) {
                    Task::where('id', $taskId)->update(['status' => Task::STATUS_FAILED]);
                }
            } else {
                // 串行：加入队列
                TaskQueue::create([
                    'task_id'     => $taskId,
                    'batch_id'    => $batchId,
                    'device_uuid' => $deviceUuid,
                    'priority'    => $priority,
                    'status'      => 'queued',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            $tasks[] = [
                'task_id'     => $taskId,
                'device_uuid' => $deviceUuid,
                'status'      => $strategy === 'parallel' ? 'running' : 'queued'
            ];
        }

        return $this->json(200, "批量任务已创建", [
            'batch_id' => $batchId,
            'strategy' => $strategy,
            'total'    => count($tasks),
            'tasks'    => $tasks,
        ]);
    }

    /**
     * 智能负载均衡分配
     * POST /api/batch/auto-dispatch
     */
    public function autoDispatch()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];

        $scriptName = $body['script_name'] ?? '';
        $params     = $body['params_json'] ?? [];
        $count      = (int)($body['count'] ?? 1);        // 需要执行的数量
        $priority   = $body['priority'] ?? 5;

        if (!$scriptName) {
            return $this->json(400, '脚本名称不能为空');
        }
        if ($count < 1 || $count > 50) {
            return $this->json(400, '任务数量范围：1-50');
        }

        // 获取在线且空闲的设备
        $availableDevices = Device::where('status', 'online')
            ->where('last_heartbeat', '>', date('Y-m-d H:i:s', time() - 60))
            ->select()
            ->toArray();

        if (empty($availableDevices)) {
            return $this->json(400, '暂无可用设备');
        }

        // 按CPU使用率排序，优选空闲设备
        usort($availableDevices, function($a, $b) {
            $cpuA = json_decode($a['sys_info'], true)['cpu_percent'] ?? 100;
            $cpuB = json_decode($b['sys_info'], true)['cpu_percent'] ?? 100;
            return $cpuA <=> $cpuB;
        });

        $batchId = 'auto_' . uniqid();
        $tasks   = [];
        $adapter = new OpenClawAdapter();

        // 循环分配任务到最优设备
        for ($i = 0; $i < $count; $i++) {
            $device = $availableDevices[$i % count($availableDevices)];
            $taskId = Task::generateId();

            Task::create([
                'id'          => $taskId,
                'batch_id'    => $batchId,
                'device_uuid' => $device['uuid'],
                'script_name' => $scriptName,
                'params_json' => $params,
                'priority'    => $priority,
                'status'      => Task::STATUS_RUNNING,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $result = $adapter->dispatchTask($device['uuid'], $taskId, $scriptName, $params);
            
            if (!$result) {
                Task::where('id', $taskId)->update(['status' => Task::STATUS_FAILED]);
            }

            $tasks[] = [
                'task_id'     => $taskId,
                'device_uuid' => $device['uuid'],
                'device_name' => $device['name'] ?? '未知设备',
            ];
        }

        return $this->json(200, "智能调度完成", [
            'batch_id'         => $batchId,
            'total_tasks'      => $count,
            'available_devices' => count($availableDevices),
            'tasks'            => $tasks,
        ]);
    }

    /**
     * 队列任务调度器（定时任务调用）
     * GET /api/batch/process-queue
     */
    public function processQueue()
    {
        // 获取待处理队列任务（按优先级排序）
        $queueTasks = TaskQueue::where('status', 'queued')
            ->order('priority', 'desc')
            ->order('created_at', 'asc')
            ->limit(10)
            ->select();

        $processed = 0;
        $adapter   = new OpenClawAdapter();

        foreach ($queueTasks as $queueTask) {
            $task = Task::find($queueTask->task_id);
            if (!$task) continue;

            // 检查设备是否在线
            $device = Device::where('uuid', $task->device_uuid)
                ->where('status', 'online')
                ->find();
            
            if (!$device) continue;

            // 下发任务
            Task::where('id', $task->id)->update(['status' => Task::STATUS_RUNNING]);
            $result = $adapter->dispatchTask($task->device_uuid, $task->id, $task->script_name, $task->params_json);

            if ($result) {
                TaskQueue::where('id', $queueTask->id)->update(['status' => 'dispatched']);
                $processed++;
            } else {
                Task::where('id', $task->id)->update(['status' => Task::STATUS_FAILED]);
                TaskQueue::where('id', $queueTask->id)->update(['status' => 'failed']);
            }
        }

        return $this->json(200, "队列处理完成", [
            'processed_count' => $processed,
            'remaining_count' => TaskQueue::where('status', 'queued')->count(),
        ]);
    }

    /**
     * 批次状态查询
     * GET /api/batch/status/:batchId
     */
    public function batchStatus()
    {
        $batchId = $this->input('batch_id', '');
        if (!$batchId) {
            return $this->json(400, '批次ID不能为空');
        }

        $tasks = Task::where('batch_id', $batchId)
            ->select()
            ->toArray();

        if (empty($tasks)) {
            return $this->json(404, '批次不存在');
        }

        // 统计各状态数量
        $statusCount = [];
        foreach ($tasks as $task) {
            $status = $task['status'];
            $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;
        }

        $total     = count($tasks);
        $completed = ($statusCount['completed'] ?? 0) + ($statusCount['failed'] ?? 0);
        $progress  = $total > 0 ? round($completed / $total * 100, 2) : 0;

        return $this->json(200, '获取成功', [
            'batch_id'     => $batchId,
            'total_tasks'  => $total,
            'status_count' => $statusCount,
            'progress'     => $progress,
            'tasks'        => $tasks,
        ]);
    }
}