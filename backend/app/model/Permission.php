<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 权限模型
 */
class Permission extends Model
{
    protected $table = 'oc_permissions';

    protected $type = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联角色
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'oc_role_permissions', 'permission_id', 'role_id');
    }

    /**
     * 获取预定义权限列表
     */
    public static function getPresetPermissions(): array
    {
        return [
            // 系统管理
            ['category' => 'system', 'code' => 'system.view', 'name' => '查看系统信息', 'description' => '查看系统状态和配置'],
            ['category' => 'system', 'code' => 'system.config', 'name' => '系统配置', 'description' => '修改系统配置'],
            
            // 用户管理
            ['category' => 'user', 'code' => 'user.list', 'name' => '用户列表', 'description' => '查看用户列表'],
            ['category' => 'user', 'code' => 'user.create', 'name' => '创建用户', 'description' => '创建新用户'],
            ['category' => 'user', 'code' => 'user.edit', 'name' => '编辑用户', 'description' => '编辑用户信息'],
            ['category' => 'user', 'code' => 'user.delete', 'name' => '删除用户', 'description' => '删除用户'],
            
            // 角色权限
            ['category' => 'role', 'code' => 'role.list', 'name' => '角色列表', 'description' => '查看角色列表'],
            ['category' => 'role', 'code' => 'role.create', 'name' => '创建角色', 'description' => '创建新角色'],
            ['category' => 'role', 'code' => 'role.edit', 'name' => '编辑角色', 'description' => '编辑角色和权限'],
            ['category' => 'role', 'code' => 'role.delete', 'name' => '删除角色', 'description' => '删除角色'],
            
            // 设备管理
            ['category' => 'device', 'code' => 'device.list', 'name' => '设备列表', 'description' => '查看设备列表'],
            ['category' => 'device', 'code' => 'device.manage', 'name' => '设备管理', 'description' => '管理设备配置'],
            ['category' => 'device', 'code' => 'device.delete', 'name' => '删除设备', 'description' => '删除设备'],
            
            // 任务管理
            ['category' => 'task', 'code' => 'task.list', 'name' => '任务列表', 'description' => '查看任务列表'],
            ['category' => 'task', 'code' => 'task.create', 'name' => '创建任务', 'description' => '创建和下发任务'],
            ['category' => 'task', 'code' => 'task.kill', 'name' => '终止任务', 'description' => '终止运行中的任务'],
            ['category' => 'task', 'code' => 'task.batch', 'name' => '批量任务', 'description' => '批量任务调度'],
            
            // 脚本管理
            ['category' => 'script', 'code' => 'script.list', 'name' => '脚本列表', 'description' => '查看脚本列表'],
            ['category' => 'script', 'code' => 'script.create', 'name' => '创建脚本', 'description' => '创建新脚本'],
            ['category' => 'script', 'code' => 'script.edit', 'name' => '编辑脚本', 'description' => '编辑脚本内容'],
            ['category' => 'script', 'code' => 'script.delete', 'name' => '删除脚本', 'description' => '删除脚本'],
            
            // 监控告警
            ['category' => 'monitor', 'code' => 'monitor.view', 'name' => '查看监控', 'description' => '查看系统监控信息'],
            ['category' => 'monitor', 'code' => 'monitor.alert', 'name' => '告警管理', 'description' => '管理告警规则和通知'],
            
            // 日志审计
            ['category' => 'log', 'code' => 'log.view', 'name' => '查看日志', 'description' => '查看操作日志'],
            ['category' => 'log', 'code' => 'log.export', 'name' => '导出日志', 'description' => '导出日志文件'],
        ];
    }

    /**
     * 初始化权限数据
     */
    public static function initPermissions(): int
    {
        $presets = self::getPresetPermissions();
        $created = 0;

        foreach ($presets as $index => $preset) {
            // 检查是否已存在
            if (!self::where('code', $preset['code'])->find()) {
                self::create($preset + [
                    'sort_order' => $index,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
        }

        return $created;
    }
}