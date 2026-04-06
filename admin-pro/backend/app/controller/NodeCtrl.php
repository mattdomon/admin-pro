<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\Device;
use app\service\OpenClawAdapter;
use think\facade\Db;

/**
 * 设备节点 API 控制器
 * 
 * 负责设备注册、状态查询、心跳处理等
 */
class NodeCtrl extends BaseController
{
    /**
     * 设备列表
     * GET /api/device/list
     */
    public function list()
    {
        $status = $this->input('status', '');
        $query  = Device::order('last_heartbeat', 'desc');

        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        $devices = $query->select()->toArray();

        // 离线判定: 3次心跳(45s)未收到
        $now = time();
        foreach ($devices as &$device) {
            if ($device['status'] > 0 && $device['last_heartbeat']) {
                $diff = $now - $device['last_heartbeat'];
                if ($diff > 45) {
                    $device['status'] = 0;  // 标记为离线
                    Device::where('id', $device['id'])->update(['status' => 0]);
                }
            }
        }

        return $this->json(200, '获取成功', $devices);
    }

    /**
     * 设备详情
     * GET /api/device/detail/:uuid
     */
    public function detail()
    {
        $uuid = $this->input('uuid', '');
        if (empty($uuid)) {
            return $this->json(400, '设备UUID不能为空');
        }

        $device = Device::where('uuid', $uuid)->find();
        if (!$device) {
            return $this->json(404, '设备不存在');
        }

        return $this->json(200, '获取成功', $device);
    }

    /**
     * 手动触发连通性测试
     * POST /api/device/test/:uuid
     */
    public function test()
    {
        $uuid = $this->input('uuid', '');
        if (empty($uuid)) {
            return $this->json(400, '设备UUID不能为空');
        }

        $device = Device::where('uuid', $uuid)->find();
        if (!$device) {
            return $this->json(404, '设备不存在');
        }

        if ($device->status !== 1) {  // 1=在线
            return $this->json(400, '设备不在线，无法测试');
        }

        $adapter = new OpenClawAdapter();
        $taskId  = \app\model\Task::generateId() . '_ping';

        $result = $adapter->dispatchTask($uuid, $taskId, '__ping__', [
            'objective' => 'ping test'
        ]);

        return $result
            ? $this->json(200, '测试指令已发送')
            : $this->json(500, '测试指令发送失败');
    }

    /**
     * 删除设备
     * DELETE /api/device/delete/:uuid
     */
    public function delete()
    {
        $uuid = $this->input('uuid', '');
        if (empty($uuid)) {
            return $this->json(400, '设备UUID不能为空');
        }

        Device::where('uuid', $uuid)->delete();
        return $this->json(200, '删除成功');
    }
}
