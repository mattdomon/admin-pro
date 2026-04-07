<?php
namespace app\controller;

use think\Request;
use think\facade\Db;
use think\facade\Log;

/**
 * 系统监控控制器
 * 负责接收、存储和查询系统监控数据
 */
class MonitorCtrl extends BaseController
{
    /**
     * 保存监控指标数据 (来自 bridge.py)
     * POST /api/monitor/saveMetrics
     */
    public function saveMetrics(Request $request)
    {
        try {
            // 获取 JSON 数据
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return json(['code' => 400, 'message' => '无效的JSON数据']);
            }
            
            // 验证必需字段
            $requiredFields = [
                'timestamp', 'cpu_percent', 'memory_percent', 
                'disk_percent', 'network_sent', 'network_recv',
                'tasks_total', 'tasks_running', 'tasks_failed'
            ];
            
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return json(['code' => 400, 'message' => "缺少必需字段: {$field}"]);
                }
            }
            
            // 插入到监控日志表
            $monitorData = [
                'timestamp' => $data['timestamp'],
                'cpu_percent' => round($data['cpu_percent'], 2),
                'memory_percent' => round($data['memory_percent'], 2),
                'disk_percent' => round($data['disk_percent'], 2),
                'network_sent_per_sec' => round($data['network_sent'], 2),
                'network_recv_per_sec' => round($data['network_recv'], 2),
                'tasks_total' => intval($data['tasks_total']),
                'tasks_running' => intval($data['tasks_running']),
                'tasks_failed' => intval($data['tasks_failed']),
                'created_at' => date('Y-m-d H:i:s', $data['timestamp'])
            ];
            
            Db::name('monitor_logs')->insert($monitorData);
            
            Log::info("监控数据已保存", $monitorData);
            
            return json(['code' => 200, 'message' => '监控数据保存成功']);
            
        } catch (\Exception $e) {
            Log::error("保存监控数据失败: " . $e->getMessage());
            return json(['code' => 500, 'message' => '保存监控数据失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 获取监控数据趋势 (24小时内)
     * GET /api/monitor/trends?hours=24
     */
    public function getTrends(Request $request)
    {
        try {
            $hours = intval($request->param('hours', 24));
            $hours = max(1, min($hours, 168)); // 限制在1-168小时(7天)之间
            
            $startTime = time() - ($hours * 3600);
            
            // 查询监控数据，按小时分组聚合
            $query = Db::name('monitor_logs')
                ->where('timestamp', '>=', $startTime)
                ->field([
                    'FROM_UNIXTIME(timestamp, "%Y-%m-%d %H:00:00") as hour_time',
                    'AVG(cpu_percent) as avg_cpu',
                    'AVG(memory_percent) as avg_memory', 
                    'AVG(disk_percent) as avg_disk',
                    'AVG(network_sent_per_sec) as avg_network_sent',
                    'AVG(network_recv_per_sec) as avg_network_recv',
                    'MAX(tasks_total) as max_tasks_total',
                    'AVG(tasks_running) as avg_tasks_running',
                    'SUM(tasks_failed) as total_tasks_failed'
                ])
                ->group('hour_time')
                ->order('hour_time ASC');
                
            $trends = $query->select()->toArray();
            
            // 格式化数据
            $formattedTrends = array_map(function($item) {
                return [
                    'time' => $item['hour_time'],
                    'cpu' => round($item['avg_cpu'], 2),
                    'memory' => round($item['avg_memory'], 2),
                    'disk' => round($item['avg_disk'], 2),
                    'network_sent' => round($item['avg_network_sent'], 2),
                    'network_recv' => round($item['avg_network_recv'], 2),
                    'tasks_total' => intval($item['max_tasks_total']),
                    'tasks_running' => round($item['avg_tasks_running'], 2),
                    'tasks_failed' => intval($item['total_tasks_failed'])
                ];
            }, $trends);
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'trends' => $formattedTrends,
                    'period' => $hours,
                    'count' => count($formattedTrends)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("获取监控趋势失败: " . $e->getMessage());
            return json(['code' => 500, 'message' => '获取监控趋势失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 获取实时统计概览
     * GET /api/monitor/overview
     */
    public function getOverview(Request $request)
    {
        try {
            // 获取最近5分钟的监控数据
            $recentTime = time() - 300; // 5分钟前
            
            $recentData = Db::name('monitor_logs')
                ->where('timestamp', '>=', $recentTime)
                ->field([
                    'AVG(cpu_percent) as avg_cpu',
                    'AVG(memory_percent) as avg_memory',
                    'AVG(disk_percent) as avg_disk',
                    'COUNT(*) as data_points'
                ])
                ->find();
            
            // 获取今日任务统计
            $todayStart = strtotime(date('Y-m-d 00:00:00'));
            $taskStats = Db::name('monitor_logs')
                ->where('timestamp', '>=', $todayStart)
                ->field([
                    'MAX(tasks_total) as total_tasks',
                    'SUM(tasks_failed) as total_failed',
                    'AVG(tasks_running) as avg_running'
                ])
                ->find();
            
            // 计算成功率
            $totalTasks = intval($taskStats['total_tasks'] ?? 0);
            $totalFailed = intval($taskStats['total_failed'] ?? 0);
            $successRate = $totalTasks > 0 ? round((($totalTasks - $totalFailed) / $totalTasks) * 100, 2) : 100;
            
            $overview = [
                'system' => [
                    'cpu_avg' => round($recentData['avg_cpu'] ?? 0, 2),
                    'memory_avg' => round($recentData['avg_memory'] ?? 0, 2),
                    'disk_avg' => round($recentData['avg_disk'] ?? 0, 2),
                    'data_points' => intval($recentData['data_points'] ?? 0)
                ],
                'tasks' => [
                    'total_today' => $totalTasks,
                    'failed_today' => $totalFailed,
                    'success_rate' => $successRate,
                    'avg_running' => round($taskStats['avg_running'] ?? 0, 2)
                ],
                'status' => [
                    'bridge_connected' => true, // 这里可以添加实际的连接状态检查
                    'last_update' => date('Y-m-d H:i:s')
                ]
            ];
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $overview
            ]);
            
        } catch (\Exception $e) {
            Log::error("获取监控概览失败: " . $e->getMessage());
            return json(['code' => 500, 'message' => '获取监控概览失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 系统健康概览（SystemMonitor.vue 使用）
     * GET /api/monitor/overview
     * 路由别名，指向 getOverview
     */
    public function overview(Request $request)
    {
        return $this->getOverview($request);
    }

    /**
     * 节点健康状态
     * GET /api/monitor/devices
     */
    public function deviceHealth(Request $request)
    {
        try {
            // 获取所有设备及状态
            $devices = Db::name('oc_devices')
                ->order('last_heartbeat', 'desc')
                ->select()
                ->toArray();

            $now = time();
            $healthy  = [];
            $warning  = [];
            $critical = [];

            foreach ($devices as &$device) {
                // 离线判定：45秒未心跳
                if ($device['status'] > 0 && $device['last_heartbeat']) {
                    $diff = $now - $device['last_heartbeat'];
                    if ($diff > 45) {
                        $device['status'] = 0;
                    }
                }

                if ($device['status'] == 1) {
                    $healthy[] = $device;
                } elseif ($device['status'] == 2) {
                    $warning[] = $device;
                } else {
                    $critical[] = $device;
                }
            }

            return json([
                'code'    => 200,
                'message' => '获取成功',
                'data'    => compact('healthy', 'warning', 'critical')
            ]);
        } catch (\Exception $e) {
            Log::error('获取设备健康状态失败: ' . $e->getMessage());
            return json(['code' => 500, 'message' => '获取失败']);
        }
    }

    /**
     * 告警规则列表
     * GET /api/monitor/alert-rules
     */
    public function alertRules(Request $request)
    {
        try {
            $rules = Db::name('monitor_alerts')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            return json(['code' => 200, 'message' => '获取成功', 'data' => $rules]);
        } catch (\Exception $e) {
            Log::error('获取告警规则失败: ' . $e->getMessage());
            return json(['code' => 500, 'message' => '获取失败']);
        }
    }

    /**
     * 系统健康检查
     * GET /api/monitor/check
     */
    public function systemCheck(Request $request)
    {
        try {
            // 检查近5分钟的告警
            $recentTime = time() - 300;
            $alertHistory = Db::name('monitor_alert_history')
                ->where('triggered_at', '>=', date('Y-m-d H:i:s', $recentTime))
                ->count();

            return json([
                'code'    => 200,
                'message' => '检查完成',
                'data'    => [
                    'alerts_count' => (int)$alertHistory,
                    'checked_at'   => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('系统健康检查失败: ' . $e->getMessage());
            return json(['code' => 500, 'message' => '检查失败']);
        }
    }

    /**
     * 获取任务成功率统计
     * GET /api/monitor/taskStats?days=7
     */
    public function getTaskStats(Request $request)
    {
        try {
            $days = intval($request->param('days', 7));
            $days = max(1, min($days, 30)); // 限制在1-30天之间
            
            $startTime = strtotime("-{$days} days");
            
            // 按天统计任务数据
            $dailyStats = Db::name('monitor_logs')
                ->where('timestamp', '>=', $startTime)
                ->field([
                    'DATE(FROM_UNIXTIME(timestamp)) as date',
                    'MAX(tasks_total) - MIN(tasks_total) as daily_tasks',
                    'SUM(tasks_failed) as daily_failed'
                ])
                ->group('date')
                ->order('date ASC')
                ->select()
                ->toArray();
            
            // 计算每日成功率
            $stats = array_map(function($item) {
                $total = max(1, intval($item['daily_tasks'])); // 避免除零
                $failed = intval($item['daily_failed']);
                $success = max(0, $total - $failed);
                $successRate = round(($success / $total) * 100, 2);
                
                return [
                    'date' => $item['date'],
                    'total_tasks' => $total,
                    'failed_tasks' => $failed,
                    'success_tasks' => $success,
                    'success_rate' => $successRate
                ];
            }, $dailyStats);
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'stats' => $stats,
                    'period_days' => $days,
                    'total_days' => count($stats)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("获取任务统计失败: " . $e->getMessage());
            return json(['code' => 500, 'message' => '获取任务统计失败: ' . $e->getMessage()]);
        }
    }
}