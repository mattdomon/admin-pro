<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户模型
 */
class User extends Model
{
    protected $table = 'oc_users';

    protected $type = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 关联角色
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'oc_user_roles', 'user_id', 'role_id');
    }

    /**
     * 获取用户权限
     */
    public function getPermissions(): array
    {
        $permissions = [];
        
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[$permission->code] = $permission->name;
            }
        }
        
        return $permissions;
    }

    /**
     * 检查用户是否有指定权限
     */
    public function hasPermission(string $permissionCode): bool
    {
        $permissions = $this->getPermissions();
        return isset($permissions[$permissionCode]);
    }

    /**
     * 检查用户是否有指定角色
     */
    public function hasRole(string $roleName): bool
    {
        foreach ($this->roles as $role) {
            if ($role->name === $roleName) {
                return true;
            }
        }
        return false;
    }
}