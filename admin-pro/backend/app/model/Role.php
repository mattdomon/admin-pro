<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 角色模型
 */
class Role extends Model
{
    protected $table = 'oc_roles';

    protected $type = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'oc_user_roles', 'role_id', 'user_id');
    }

    /**
     * 关联权限
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'oc_role_permissions', 'role_id', 'permission_id');
    }
}