<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 系统告警模型
 * 
 * 记录系统各种告警信息
 */
class SystemAlert extends Model
{
    protected $table = 'oc_system_alerts';

    protected $type = [
        'context' => 'json',
    ];

    // 告警级别
    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_CRITICAL = 'critical';

    // 告警类型
    const TYPE_DEVICE_OFFLINE = 'device_offline';
    const TYPE_HIGH_CPU       = 'high_cpu';
    const TYPE_HIGH_MEMORY    = 'high_memory';
    const TYPE_TASK_FAILURE   = 'task_failure';
    const TYPE_QUEUE_BACKLOG  = 'queue_backlog';

    /**
     * 获取最近告警
     */
    public static function getRecentAlerts(int $hours = 24, int $limit = 50): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
        
        return self::where('created_at', '>', $cutoff)
            ->order('created_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取告警统计
     */
    public static function getAlertStats(int $hours = 24): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
        
        $alerts = self::where('created_at', '>', $cutoff)->select();
        
        $stats = [
            'total' => count($alerts),
            'by_level' => [
                self::LEVEL_INFO     => 0,
                self::LEVEL_WARNING  => 0,
                self::LEVEL_CRITICAL => 0,
            ],
            'by_type' => [],
        ];
        
        foreach ($alerts as $alert) {
            $level = $alert->level;
            $type = $alert->type;
            
            if (isset($stats['by_level'][$level])) {
                $stats['by_level'][$level]++;
            }
            
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        }
        
        return $stats;
    }

    /**
     * 清理过期告警
     */
    public static function cleanup(int $keepDays = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 24 * 3600);
        
        return self::where('created_at', '<', $cutoff)->delete();
    }

    /**
     * 检查是否为重复告警（防止告警轰炸）
     */
    public static function isDuplicateAlert(string $type, array $context, int $windowMinutes = 10): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
        
        // 简单实现：检查相同类型和设备的告警
        $deviceUuid = $context['device_uuid'] ?? null;
        
        $query = self::where('type', $type)
            ->where('created_at', '>', $cutoff);
        
        if ($deviceUuid) {
            $query->whereRaw("JSON_EXTRACT(context, '$.device_uuid') = ?", [$deviceUuid]);
        }
        
        return $query->find() !== null;
    }
}