<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\Auth;
use app\model\NodeKey;
use think\facade\Db;

/**
 * 节点凭证控制器
 *
 * POST   /api/nodes/generate         — 为当前用户生成一个 node_key
 * GET    /api/nodes/list             — 获取当前用户的所有节点
 * DELETE /api/nodes/:id              — 删除节点
 * PUT    /api/nodes/:id/name         — 修改节点备注名
 */
class NodeKeyCtrl extends Auth
{
    /**
     * 生成节点凭证
     *
     * POST /api/nodes/generate
     * Body: { "node_name": "我的家庭服务器" }
     *
     * 返回:
     * {
     *   "node_key": "a1b2c3d4...(32位)",
     *   "node_name": "我的家庭服务器",
     *   "status": "pending"
     * }
     */
    public function generate()
    {
        $userInfo = $this->getCurrentUser();
        if (!$userInfo) {
            return $this->json(401, '未登录或 Token 已过期');
        }

        $nodeName = trim((string) $this->input('node_name', ''));
        if (empty($nodeName)) {
            $nodeName = '节点-' . date('mdHi'); // 默认名：节点-04071530
        }

        $nodeKey = NodeKey::generateKey();

        $record = NodeKey::create([
            'user_id'   => $userInfo['user_id'],
            'node_key'  => $nodeKey,
            'node_name' => $nodeName,
            'status'    => NodeKey::STATUS_PENDING,
        ]);

        return $this->json(200, '节点凭证已生成', [
            'id'        => $record->id,
            'node_key'  => $nodeKey,
            'node_name' => $nodeName,
            'status'    => NodeKey::STATUS_PENDING,
        ]);
    }

    /**
     * 获取当前用户节点列表
     *
     * GET /api/nodes/list
     */
    public function list()
    {
        $userInfo = $this->getCurrentUser();
        if (!$userInfo) {
            return $this->json(401, '未登录或 Token 已过期');
        }

        $nodes = NodeKey::where('user_id', $userInfo['user_id'])
                        ->order('created_at', 'desc')
                        ->select()
                        ->toArray();

        // 对 node_key 做脱敏展示（前8位 + **** + 后4位）
        foreach ($nodes as &$node) {
            $key = $node['node_key'];
            $node['node_key_masked'] = substr($key, 0, 8) . '****' . substr($key, -4);
            // 完整 key 只在生成时返回一次，这里不再返回
            unset($node['node_key']);
        }

        return $this->json(200, '获取成功', ['list' => $nodes]);
    }

    /**
     * 删除节点
     *
     * DELETE /api/nodes/:id
     */
    public function delete(int $id)
    {
        $userInfo = $this->getCurrentUser();
        if (!$userInfo) {
            return $this->json(401, '未登录或 Token 已过期');
        }

        $node = NodeKey::where('id', $id)
                       ->where('user_id', $userInfo['user_id'])
                       ->find();

        if (!$node) {
            return $this->json(404, '节点不存在');
        }

        if ($node->status === NodeKey::STATUS_ONLINE) {
            return $this->json(400, '节点在线中，请先断开连接再删除');
        }

        $node->delete();
        return $this->json(200, '节点已删除');
    }

    /**
     * 修改节点备注名
     *
     * PUT /api/nodes/:id/name
     * Body: { "node_name": "新名字" }
     */
    public function rename(int $id)
    {
        $userInfo = $this->getCurrentUser();
        if (!$userInfo) {
            return $this->json(401, '未登录或 Token 已过期');
        }

        $nodeName = trim((string) $this->input('node_name', ''));
        if (empty($nodeName)) {
            return $this->json(400, '节点名不能为空');
        }

        $affected = NodeKey::where('id', $id)
                           ->where('user_id', $userInfo['user_id'])
                           ->update(['node_name' => $nodeName]);

        if (!$affected) {
            return $this->json(404, '节点不存在');
        }

        return $this->json(200, '节点名已更新');
    }
}
