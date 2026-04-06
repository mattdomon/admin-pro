<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\User;
use app\model\Role;
use app\model\Permission;
use app\model\OperationLog;
use think\facade\Db;

/**
 * 用户权限管理控制器
 * 
 * 功能：
 * - 用户管理 (CRUD)
 * - 角色管理 (CRUD)
 * - 权限分配
 * - 操作审计日志
 */
class UserCtrl extends BaseController
{
    /**
     * 用户列表
     * GET /api/user/list
     */
    public function userList()
    {
        $page = $this->input('page', 1);
        $limit = $this->input('limit', 20);
        
        $users = User::with(['roles'])
            ->order('created_at', 'desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        return $this->json(200, '获取成功', [
            'list' => $users->items(),
            'total' => $users->total(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 创建用户
     * POST /api/user/create
     */
    public function createUser()
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $nickname = $data['nickname'] ?? '';
        $email = $data['email'] ?? '';
        $roleIds = $data['role_ids'] ?? [];

        if (!$username) return $this->json(400, '用户名不能为空');
        if (!$password) return $this->json(400, '密码不能为空');

        // 检查用户名重复
        if (User::where('username', $username)->find()) {
            return $this->json(400, '用户名已存在');
        }

        Db::startTrans();
        try {
            // 创建用户
            $user = User::create([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'nickname' => $nickname,
                'email'    => $email,
                'status'   => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // 分配角色
            if (!empty($roleIds)) {
                $user->roles()->attach($roleIds);
            }

            // 记录操作日志
            $this->logOperation('create_user', [
                'username' => $username,
                'nickname' => $nickname,
                'roles' => $roleIds,
            ]);

            Db::commit();
            return $this->json(200, '用户创建成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->json(500, '创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新用户
     * POST /api/user/update/:id
     */
    public function updateUser()
    {
        $id = $this->input('id', 0);
        $user = User::find($id);
        
        if (!$user) {
            return $this->json(404, '用户不存在');
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $updateData = [];
        if (isset($data['nickname'])) $updateData['nickname'] = $data['nickname'];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        if (isset($data['status'])) $updateData['status'] = $data['status'];
        
        // 更新密码
        if (!empty($data['password'])) {
            $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        Db::startTrans();
        try {
            // 更新用户信息
            if (!empty($updateData)) {
                $user->save($updateData);
            }

            // 更新角色
            if (isset($data['role_ids'])) {
                $user->roles()->detach();
                if (!empty($data['role_ids'])) {
                    $user->roles()->attach($data['role_ids']);
                }
            }

            // 记录操作日志
            $this->logOperation('update_user', [
                'user_id' => $id,
                'username' => $user->username,
                'updates' => $updateData,
                'roles' => $data['role_ids'] ?? null,
            ]);

            Db::commit();
            return $this->json(200, '更新成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->json(500, '更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除用户
     * DELETE /api/user/delete/:id
     */
    public function deleteUser()
    {
        $id = $this->input('id', 0);
        $user = User::find($id);
        
        if (!$user) {
            return $this->json(404, '用户不存在');
        }

        // 不能删除超级管理员
        if ($user->username === 'admin') {
            return $this->json(403, '不能删除超级管理员');
        }

        Db::startTrans();
        try {
            // 解除角色关联
            $user->roles()->detach();
            
            // 删除用户
            $user->delete();

            // 记录操作日志
            $this->logOperation('delete_user', [
                'user_id' => $id,
                'username' => $user->username,
            ]);

            Db::commit();
            return $this->json(200, '删除成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->json(500, '删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 角色列表
     * GET /api/role/list
     */
    public function roleList()
    {
        $roles = Role::with(['permissions'])
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        return $this->json(200, '获取成功', $roles);
    }

    /**
     * 创建角色
     * POST /api/role/create
     */
    public function createRole()
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $name = $data['name'] ?? '';
        $description = $data['description'] ?? '';
        $permissionIds = $data['permission_ids'] ?? [];

        if (!$name) return $this->json(400, '角色名称不能为空');

        // 检查角色名重复
        if (Role::where('name', $name)->find()) {
            return $this->json(400, '角色名称已存在');
        }

        Db::startTrans();
        try {
            // 创建角色
            $role = Role::create([
                'name' => $name,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // 分配权限
            if (!empty($permissionIds)) {
                $role->permissions()->attach($permissionIds);
            }

            // 记录操作日志
            $this->logOperation('create_role', [
                'name' => $name,
                'description' => $description,
                'permissions' => $permissionIds,
            ]);

            Db::commit();
            return $this->json(200, '角色创建成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->json(500, '创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 权限列表
     * GET /api/permission/list
     */
    public function permissionList()
    {
        $permissions = Permission::order('category', 'asc')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        // 按分类分组
        $grouped = [];
        foreach ($permissions as $permission) {
            $category = $permission['category'] ?: 'default';
            $grouped[$category][] = $permission;
        }

        return $this->json(200, '获取成功', $grouped);
    }

    /**
     * 操作日志
     * GET /api/log/operations
     */
    public function operationLogs()
    {
        $page = $this->input('page', 1);
        $limit = $this->input('limit', 50);
        $action = $this->input('action', '');
        $username = $this->input('username', '');

        $query = OperationLog::order('created_at', 'desc');

        if ($action) {
            $query->where('action', $action);
        }
        if ($username) {
            $query->where('username', 'like', "%{$username}%");
        }

        $logs = $query->paginate([
            'list_rows' => $limit,
            'page' => $page,
        ]);

        return $this->json(200, '获取成功', [
            'list' => $logs->items(),
            'total' => $logs->total(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    // ===== 私有方法 =====

    /**
     * 记录操作日志
     */
    private function logOperation(string $action, array $details): void
    {
        try {
            OperationLog::create([
                'username'   => 'admin', // TODO: 从JWT获取当前用户
                'action'     => $action,
                'details'    => $details,
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->header('user-agent', ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // 日志记录失败不影响主要功能
            error_log("Failed to log operation: " . $e->getMessage());
        }
    }
}