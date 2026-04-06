<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\Device;
use app\model\Task;
use app\model\TaskQueue;
use app\model\SystemAlert;

/**
 * 系统监控与告警控制器
 * 
 * 功能：
 * - 系统健康检查
 * - 性能报表统计
 * - 告警规则管理
 * - 邮件/微信通知
 */
class MonitorCtrl extends BaseController
{
    /**
     * 系统健康概览
     * GET /api/monitor/overview
     */
    public function overview()
    {
        // 节点健康状态
        $totalDevices  = Device::count();
        $onlineDevices = Device::where('status', 'online')
            ->where('last_heartbeat', '>', date('Y-m-d H:i:s', time() - 120))
            ->count();
        $offlineDevices = $totalDevices - $onlineDevices;

        // 任务执行统计
        $totalTasks = Task::count();
        $runningTasks = Task::where('status', Task::STATUS_RUNNING)->count();
        $todayTasks = Task::whereRaw("DATE(created_at) = CURDATE()")->count();
        $successRate = $this->calculateSuccessRate();

        // 队列状态
        $queueStats = TaskQueue::getQueueStats();

        // 系统资源统计
        $resourceStats = $this->getSystemResourceStats();

        // 最近告警
        $recentAlerts = SystemAlert::where('created_at', '>', date('Y-m-d H:i:s', time() - 3600))
            ->order('created_at', 'desc')
            ->limit(5)
            ->select()
            ->toArray();

        return $this->json(200, '获取成功', [
            'devices' => [
                'total'   => $totalDevices,
                'online'  => $onlineDevices,
                'offline' => $offlineDevices,
                'health_rate' => $totalDevices > 0 ? round($onlineDevices / $totalDevices * 100, 2) : 0,
            ],
            'tasks' => [
                'total'        => $totalTasks,
                'running'      => $runningTasks,
                'today'        => $todayTasks,
                'success_rate' => $successRate,
            ],
            'queue' => $queueStats,
            'resources' => $resourceStats,
            'recent_alerts' => $recentAlerts,
        ]);
    }

    /**
     * 节点健康检查详情
     * GET /api/monitor/devices
     */
    public function deviceHealth()
    {
        $devices = Device::order('last_heartbeat', 'desc')
            ->select()
            ->toArray();

        $healthyDevices = [];
        $warningDevices = [];
        $criticalDevices = [];

        foreach ($devices as $device) {
            $sysInfo = json_decode($device['sys_info'], true) ?: [];
            $lastHeartbeat = strtotime($device['last_heartbeat']);
            $isOnline = $device['status'] === 'online' && (time() - $lastHeartbeat) < 120;

            $healthScore = $this->calculateDeviceHealth($sysInfo, $isOnline, $lastHeartbeat);

            $deviceHealth = [
                'uuid'         => $device['uuid'],
                'name'         => $device['name'] ?: $device['uuid'],
                'status'       => $device['status'],
                'online'       => $isOnline,
                'health_score' => $healthScore,
                'last_seen'    => $device['last_heartbeat'],
                'sys_info'     => $sysInfo,
                'running_tasks' => Task::getRunningTaskCount($device['uuid']),
            ];

            if ($healthScore >= 80) {
                $healthyDevices[] = $deviceHealth;
            } elseif ($healthScore >= 50) {
                $warningDevices[] = $deviceHealth;
            } else {
                $criticalDevices[] = $deviceHealth;
            }
        }

        return $this->json(200, '获取成功', [
            'healthy'  => $healthyDevices,
            'warning'  => $warningDevices,
            'critical' => $criticalDevices,
        ]);
    }

    /**
     * 性能报表统计
     * GET /api/monitor/reports
     */
    public function reports()
    {
        $days = $this->input('days', 7); // 默认7天
        $days = min($days, 30); // 最多30天

        // 任务成功率趋势
        $taskTrends = $this->getTaskTrends($days);

        // 节点利用率
        $deviceUtilization = $this->getDeviceUtilization($days);

        // 热门脚本TOP5
        $topScripts = $this->getTopScripts($days);

        // 错误统计
        $errorStats = $this->getErrorStats($days);

        return $this->json(200, '获取成功', [
            'task_trends'         => $taskTrends,
            'device_utilization' => $deviceUtilization,
            'top_scripts'        => $topScripts,
            'error_stats'        => $errorStats,
        ]);
    }

    /**
     * 告警规则管理
     * GET /api/monitor/alert-rules
     */
    public function alertRules()
    {
        // 预定义告警规则
        $rules = [
            [
                'id'          => 1,
                'name'        => '节点离线告警',
                'type'        => 'device_offline',
                'condition'   => '节点超过2分钟未心跳',
                'enabled'     => true,
                'notify_methods' => ['email'],
            ],
            [
                'id'          => 2,
                'name'        => 'CPU使用率告警',
                'type'        => 'high_cpu',
                'condition'   => 'CPU使用率 > 90%，持续5分钟',
                'enabled'     => true,
                'notify_methods' => ['email', 'webhook'],
            ],
            [
                'id'          => 3,
                'name'        => '任务失败率告警',
                'type'        => 'task_failure',
                'condition'   => '1小时内任务失败率 > 50%',
                'enabled'     => false,
                'notify_methods' => ['email'],
            ],
            [
                'id'          => 4,
                'name'        => '队列堆积告警',
                'type'        => 'queue_backlog',
                'condition'   => '待处理队列 > 100个任务',
                'enabled'     => true,
                'notify_methods' => ['webhook'],
            ],
        ];

        return $this->json(200, '获取成功', $rules);
    }

    /**
     * 触发系统检查（定时任务调用）
     * GET /api/monitor/check
     */
    public function systemCheck()
    {
        $alerts = [];

        // 1. 检查离线节点
        $offlineDevices = Device::where('status', 'online')
            ->where('last_heartbeat', '<', date('Y-m-d H:i:s', time() - 120))
            ->select();

        foreach ($offlineDevices as $device) {
            $alerts[] = $this->createAlert('device_offline', [
                'device_uuid' => $device->uuid,
                'device_name' => $device->name ?: $device->uuid,
                'last_seen'   => $device->last_heartbeat,
            ]);
        }

        // 2. 检查高CPU使用率
        $highCpuDevices = Device::where('status', 'online')
            ->select();

        foreach ($highCpuDevices as $device) {
            $sysInfo = json_decode($device->sys_info, true) ?: [];
            $cpuPercent = $sysInfo['cpu_percent'] ?? 0;
            
            if ($cpuPercent > 90) {
                $alerts[] = $this->createAlert('high_cpu', [
                    'device_uuid' => $device->uuid,
                    'device_name' => $device->name ?: $device->uuid,
                    'cpu_percent' => $cpuPercent,
                ]);
            }
        }

        // 3. 检查队列堆积
        $queueCount = TaskQueue::where('status', 'queued')->count();
        if ($queueCount > 100) {
            $alerts[] = $this->createAlert('queue_backlog', [
                'queue_count' => $queueCount,
            ]);
        }

        // 4. 检查任务失败率
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $recentTasks = Task::where('created_at', '>', $oneHourAgo)->count();
        $failedTasks = Task::where('created_at', '>', $oneHourAgo)
            ->where('status', Task::STATUS_FAILED)->count();

        if ($recentTasks > 10 && $failedTasks / $recentTasks > 0.5) {
            $alerts[] = $this->createAlert('task_failure', [
                'failure_rate' => round($failedTasks / $recentTasks * 100, 2),
                'failed_count' => $failedTasks,
                'total_count'  => $recentTasks,
            ]);
        }

        return $this->json(200, '检查完成', [
            'checked_at'    => date('Y-m-d H:i:s'),
            'alerts_count'  => count($alerts),
            'alerts'        => $alerts,
        ]);
    }

    // ===== 私有辅助方法 =====

    private function calculateSuccessRate(): float
    {
        $total = Task::count();
        if ($total === 0) return 0;

        $successful = Task::whereIn('status', [Task::STATUS_SUCCESS, Task::STATUS_COMPLETED])->count();
        return round($successful / $total * 100, 2);
    }

    private function getSystemResourceStats(): array
    {
        // 聚合所有在线节点的资源使用情况
        $devices = Device::where('status', 'online')
            ->where('last_heartbeat', '>', date('Y-m-d H:i:s', time() - 120))
            ->select();

        $totalCpu = 0;
        $totalMem = 0;
        $count = 0;

        foreach ($devices as $device) {
            $sysInfo = json_decode($device->sys_info, true) ?: [];
            $totalCpu += $sysInfo['cpu_percent'] ?? 0;
            $totalMem += $sysInfo['mem_percent'] ?? 0;
            $count++;
        }

        return [
            'avg_cpu' => $count > 0 ? round($totalCpu / $count, 2) : 0,
            'avg_mem' => $count > 0 ? round($totalMem / $count, 2) : 0,
            'nodes_count' => $count,
        ];
    }

    private function calculateDeviceHealth(array $sysInfo, bool $isOnline, int $lastHeartbeat): int
    {
        $score = 100;

        // 在线状态 (40%)
        if (!$isOnline) $score -= 40;
        elseif (time() - $lastHeartbeat > 60) $score -= 20;

        // CPU使用率 (30%)
        $cpu = $sysInfo['cpu_percent'] ?? 0;
        if ($cpu > 90) $score -= 30;
        elseif ($cpu > 70) $score -= 15;

        // 内存使用率 (20%)
        $mem = $sysInfo['mem_percent'] ?? 0;
        if ($mem > 95) $score -= 20;
        elseif ($mem > 80) $score -= 10;

        // 运行任务数 (10%)
        // TODO: 根据运行任务数调整评分

        return max(0, min(100, $score));
    }

    private function getTaskTrends(int $days): array
    {
        // TODO: 实现任务趋势统计
        return [];
    }

    private function getDeviceUtilization(int $days): array
    {
        // TODO: 实现设备利用率统计
        return [];
    }

    private function getTopScripts(int $days): array
    {
        // TODO: 实现热门脚本统计
        return [];
    }

    private function getErrorStats(int $days): array
    {
        // TODO: 实现错误统计
        return [];
    }

    private function createAlert(string $type, array $context): array
    {
        $alertData = [
            'type'       => $type,
            'level'      => $this->getAlertLevel($type),
            'message'    => $this->generateAlertMessage($type, $context),
            'context'    => $context,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // 保存到数据库
        try {
            SystemAlert::create($alertData);
        } catch (\Exception $e) {
            // 如果表不存在，忽略错误（待创建表）
        }

        return $alertData;
    }

    private function getAlertLevel(string $type): string
    {
        $levels = [
            'device_offline' => 'warning',
            'high_cpu'       => 'warning',
            'task_failure'   => 'critical',
            'queue_backlog'  => 'info',
        ];

        return $levels[$type] ?? 'info';
    }

    private function generateAlertMessage(string $type, array $context): string
    {
        switch ($type) {
            case 'device_offline':
                return "节点 {$context['device_name']} 已离线，最后心跳: {$context['last_seen']}";
            case 'high_cpu':
                return "节点 {$context['device_name']} CPU使用率过高: {$context['cpu_percent']}%";
            case 'task_failure':
                return "任务失败率过高: {$context['failure_rate']}% ({$context['failed_count']}/{$context['total_count']})";
            case 'queue_backlog':
                return "任务队列堆积: {$context['queue_count']} 个待处理任务";
            default:
                return "未知告警类型: $type";
        }
    }
}