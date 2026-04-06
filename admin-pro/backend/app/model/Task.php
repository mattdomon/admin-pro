<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 任务与运行审计模型
 */
class Task extends Model
{
    protected $name = 'oc_tasks';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'params_json'      => 'json',
        'token_usage_json' => 'json',
    ];

    // 状态常量
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';  // 新增：已完成
    public const STATUS_SUCCESS   = 'success';    // 保持兼容
    public const STATUS_FAILED    = 'failed';
    public const STATUS_KILLED    = 'killed';

    /**
     * 生成全局唯一任务ID
     */
    public static function generateId(): string
    {
        return 'T_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
    }

    /**
     * 获取批次任务统计
     */
    public static function getBatchStats(string $batchId): array
    {
        $tasks = self::where('batch_id', $batchId)->select();
        
        $statusCount = [];
        foreach ($tasks as $task) {
            $status = $task->status;
            $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;
        }
        
        $total = count($tasks);
        $completed = ($statusCount[self::STATUS_COMPLETED] ?? 0) + 
                    ($statusCount[self::STATUS_SUCCESS] ?? 0) + 
                    ($statusCount[self::STATUS_FAILED] ?? 0) + 
                    ($statusCount[self::STATUS_KILLED] ?? 0);
        
        return [
            'total'        => $total,
            'completed'    => $completed,
            'progress'     => $total > 0 ? round($completed / $total * 100, 2) : 0,
            'status_count' => $statusCount,
        ];
    }

    /**
     * 获取设备当前运行任务数
     */
    public static function getRunningTaskCount(string $deviceUuid): int
    {
        return self::where('device_uuid', $deviceUuid)
            ->where('status', self::STATUS_RUNNING)
            ->count();
    }
}
