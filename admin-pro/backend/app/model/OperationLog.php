<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 操作日志模型
 */
class OperationLog extends Model
{
    protected $table = 'oc_operation_logs';

    protected $type = [
        'details'    => 'json',
        'created_at' => 'datetime',
    ];

    /**
     * 获取操作类型描述
     */
    public function getActionTextAttr(): string
    {
        $actions = [
            'login'        => '用户登录',
            'logout'       => '用户退出',
            'create_user'  => '创建用户',
            'update_user'  => '更新用户',
            'delete_user'  => '删除用户',
            'create_role'  => '创建角色',
            'update_role'  => '更新角色',
            'delete_role'  => '删除角色',
            'create_task'  => '创建任务',
            'kill_task'    => '终止任务',
            'batch_task'   => '批量任务',
            'create_script' => '创建脚本',
            'update_script' => '更新脚本',
            'delete_script' => '删除脚本',
            'system_config' => '系统配置',
        ];

        return $actions[$this->action] ?? $this->action;
    }

    /**
     * 清理过期日志
     */
    public static function cleanup(int $keepDays = 90): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 24 * 3600);
        
        return self::where('created_at', '<', $cutoff)->delete();
    }

    /**
     * 获取操作统计
     */
    public static function getActionStats(int $days = 7): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 24 * 3600);
        
        $logs = self::where('created_at', '>', $cutoff)
            ->field('action, COUNT(*) as count')
            ->group('action')
            ->order('count', 'desc')
            ->select()
            ->toArray();

        return $logs;
    }
}