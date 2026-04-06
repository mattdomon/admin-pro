<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 任务队列模型
 * 
 * 用于串行任务调度、优先级管理
 * 
 * 字段说明：
 * - task_id: 关联任务ID
 * - batch_id: 批次ID
 * - device_uuid: 目标设备
 * - priority: 优先级 1-10，数字越大优先级越高
 * - status: queued|dispatched|failed
 */
class TaskQueue extends Model
{
    protected $table = 'oc_task_queue';

    // 状态常量
    const STATUS_QUEUED     = 'queued';     // 排队中
    const STATUS_DISPATCHED = 'dispatched'; // 已下发
    const STATUS_FAILED     = 'failed';     // 失败

    /**
     * 获取队列统计信息
     */
    public static function getQueueStats(): array
    {
        $total    = self::count();
        $queued   = self::where('status', self::STATUS_QUEUED)->count();
        $pending  = self::where('status', self::STATUS_DISPATCHED)->count();

        return [
            'total_queue'   => $total,
            'queued_count'  => $queued,
            'pending_count' => $pending,
        ];
    }

    /**
     * 清理已完成的队列记录（定期清理）
     */
    public static function cleanup(): int
    {
        // 清理7天前的已完成记录
        $cutoffDate = date('Y-m-d H:i:s', time() - 7 * 24 * 3600);
        
        return self::where('status', 'in', [self::STATUS_DISPATCHED, self::STATUS_FAILED])
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }
}