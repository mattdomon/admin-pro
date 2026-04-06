<?php
declare(strict_types=1);

namespace app\controller\openclaw;

use app\controller\BaseController;

/**
 * OpenClaw 监控数据接口
 * 
 * 获取 OpenClaw 实时状态数据
 */
class Monitor extends BaseController
{
    private ?array $statusData = null;
    
    /**
     * 获取完整监控数据
     */
    public function index()
    {
        return $this->json(200, '获取成功', [
            'overview' => $this->getOverview(),
            'gateway' => $this->getGateway(),
            'agents' => $this->getAgents(),
            'sessions' => $this->getSessions(),
            'tasks' => $this->getTasks(),
            'system' => $this->getSystemInfo(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * 获取概览数据
     */
    public function overview()
    {
        return $this->json(200, '获取成功', $this->getOverview());
    }
    
    /**
     * 获取网关状态
     */
    public function gateway()
    {
        return $this->json(200, '获取成功', $this->getGateway());
    }
    
    /**
     * 获取 Agent 列表
     */
    public function agents()
    {
        return $this->json(200, '获取成功', $this->getAgents());
    }
    
    /**
     * 获取会话列表
     */
    public function sessions()
    {
        return $this->json(200, '获取成功', $this->getSessions());
    }
    
    /**
     * 获取任务统计
     */
    public function tasks()
    {
        return $this->json(200, '获取成功', $this->getTasks());
    }
    
    /**
     * 获取状态数据（带缓存）
     */
    private function getStatusData(): array
    {
        if ($this->statusData !== null) {
            return $this->statusData;
        }
        
        $output = $this->execCommand('openclaw status --json 2>/dev/null');
        $this->statusData = json_decode($output, true) ?? [];
        
        return $this->statusData;
    }
    
    /**
     * 获取概览数据
     */
    private function getOverview(): array
    {
        $data = $this->getStatusData();
        
        if (empty($data)) {
            return $this->getFallbackOverview();
        }
        
        $gateway = $data['gateway'] ?? [];
        $agents = $data['agents'] ?? [];
        $sessions = $data['sessions'] ?? [];
        $tasks = $data['tasks'] ?? [];
        
        $sessionsData = $data['sessions'] ?? [];
        $sessionsCount = $sessionsData['count'] ?? 0;
        
        return [
            'agents_count' => is_array($agents) ? count($agents) : 0,
            'sessions_count' => $sessionsCount,
            'tasks_active' => $tasks['active'] ?? 0,
            'tasks_queued' => $tasks['queued'] ?? 0,
            'tasks_running' => $tasks['running'] ?? 0,
            'gateway_status' => !empty($gateway['reachable']) ? 'running' : 'stopped',
            'gateway_reachable' => $gateway['reachable'] ?? false,
            'version' => $data['runtimeVersion'] ?? 'unknown',
            'os' => $data['os'] ?? 'unknown',
            'dashboard_url' => $gateway['dashboard'] ?? 'http://127.0.0.1:18789',
        ];
    }
    
    /**
     * 获取网关详情
     */
    private function getGateway(): array
    {
        $data = $this->getStatusData();
        
        $gateway = $data['gateway'] ?? [];
        $gatewayService = $data['gatewayService'] ?? [];
        $nodeService = $data['nodeService'] ?? [];
        
        return [
            'url' => $gateway['url'] ?? 'ws://127.0.0.1:18789',
            'reachable' => $gateway['reachable'] ?? false,
            'local' => $gateway['local'] ?? false,
            'auth_token' => !empty($gateway['auth token']),
            'service_status' => $this->parseServiceStatus($gatewayService),
            'service_pid' => $gatewayService['pid'] ?? null,
            'node_status' => $nodeService['state'] ?? 'not_installed',
            'tailscale' => $gateway['tailscale'] ?? 'off',
            'channel' => $data['updateChannel'] ?? 'stable',
            'dashboard' => $gateway['dashboard'] ?? '',
        ];
    }
    
    /**
     * 获取 Agent 列表
     */
    private function getAgents(): array
    {
        $data = $this->getStatusData();
        $agentsData = $data['agents'] ?? [];
        $agentsList = $agentsData['agents'] ?? [];
        
        if (empty($agentsList)) {
            return [
                ['name' => 'hc-coding', 'status' => 'active', 'type' => 'coding', 'sessions_count' => 0],
                ['name' => 'main', 'status' => 'active', 'type' => 'main', 'sessions_count' => 0],
            ];
        }
        
        return array_map(function($agent) {
            return [
                'name' => $agent['id'] ?? $agent['name'] ?? 'unknown',
                'display_name' => $agent['name'] ?? 'unknown',
                'status' => !empty($agent['bootstrapPending']) ? 'pending' : 'active',
                'type' => $this->detectAgentType($agent['id'] ?? ''),
                'sessions_count' => $agent['sessionsCount'] ?? 0,
                'last_active' => $this->formatAge($agent['lastActiveAgeMs'] ?? 0),
            ];
        }, $agentsList);
    }
    
    /**
     * 获取会话列表
     */
    private function getSessions(): array
    {
        $data = $this->getStatusData();
        $sessionsData = $data['sessions'] ?? [];
        $sessions = $sessionsData['recent'] ?? [];
        
        if (empty($sessions)) {
            return [];
        }
        
        return array_map(function($s) {
            $inputTokens = $s['inputTokens'] ?? 0;
            $outputTokens = $s['outputTokens'] ?? 0;
            $totalTokens = $inputTokens + $outputTokens;
            $cacheRead = $s['cacheRead'] ?? 0;
            
            return [
                'key' => $s['key'] ?? '',
                'agent_id' => $s['agentId'] ?? '',
                'kind' => $s['kind'] ?? 'unknown',
                'age' => $this->formatAge($s['age'] ?? 0),
                'model' => $s['model'] ?? 'unknown',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'cache_read' => $cacheRead,
                'percent_used' => $s['percentUsed'] ?? 0,
            ];
        }, $sessions);
    }
    
    /**
     * 获取任务统计
     */
    private function getTasks(): array
    {
        $data = $this->getStatusData();
        $tasks = $data['tasks'] ?? [];
        $taskAudit = $data['taskAudit'] ?? [];
        
        return [
            'active' => $tasks['active'] ?? 0,
            'queued' => $tasks['queued'] ?? 0,
            'running' => $tasks['running'] ?? 0,
            'issues' => $tasks['issues'] ?? 0,
            'audit_errors' => $taskAudit['errors'] ?? 0,
            'warnings' => $taskAudit['warn'] ?? 0,
            'tracked' => $taskAudit['tracked'] ?? 0,
        ];
    }
    
    /**
     * 获取系统信息
     */
    private function getSystemInfo(): array
    {
        $data = $this->getStatusData();
        
        return [
            'os' => $data['os'] ?? 'unknown',
            'version' => $data['runtimeVersion'] ?? 'unknown',
            'update_channel' => $data['updateChannel'] ?? 'stable',
            'update_status' => $data['update'] ?? 'unknown',
            'memory_enabled' => !empty($data['memory']),
            'memory_plugin' => $data['memoryPlugin'] ?? '',
        ];
    }
    
    /**
     * 备用概览数据
     */
    private function getFallbackOverview(): array
    {
        return [
            'agents_count' => $this->countProcesses('openclaw'),
            'sessions_count' => 0,
            'tasks_active' => 0,
            'tasks_queued' => 0,
            'tasks_running' => 0,
            'gateway_status' => 'unknown',
            'gateway_reachable' => false,
            'version' => trim($this->execCommand('openclaw --version 2>/dev/null') ?? 'unknown'),
            'os' => is_array($this->getStatusData()['os'] ?? []) ? ($this->getStatusData()['os']['label'] ?? php_uname()) : php_uname(),
            'dashboard_url' => 'http://127.0.0.1:18789',
        ];
    }
    
    /**
     * 执行命令
     */
    private function execCommand(string $cmd): string
    {
        exec($cmd, $output, $returnCode);
        return implode("\n", $output);
    }
    
    /**
     * 解析服务状态
     */
    private function parseServiceStatus(array $service): string
    {
        $state = $service['state'] ?? '';
        
        if (strpos($state, 'active') !== false) {
            return 'running';
        }
        
        if (strpos($state, 'loaded') !== false) {
            return 'loaded';
        }
        
        if (strpos($state, 'not installed') !== false) {
            return 'not_installed';
        }
        
        return 'stopped';
    }
    
    /**
     * 检测 Agent 类型
     */
    private function detectAgentType(string $agentId): string
    {
        if (strpos($agentId, 'coding') !== false) return 'coding';
        if (strpos($agentId, 'accounting') !== false) return 'accounting';
        if (strpos($agentId, 'model') !== false) return 'model';
        if (strpos($agentId, 'trending') !== false) return 'trending';
        if ($agentId === 'main') return 'main';
        
        return 'unknown';
    }
    
    /**
     * 格式化年龄/时间
     */
    private function formatAge(int $ms): string
    {
        if ($ms <= 0) return 'unknown';
        
        $seconds = floor($ms / 1000);
        
        if ($seconds < 60) return $seconds . 's ago';
        if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h ago';
        return floor($seconds / 86400) . 'd ago';
    }
    
    /**
     * 统计进程数
     */
    private function countProcesses(string $name): int
    {
        exec("ps aux | grep -v grep | grep '$name' | wc -l", $output);
        return max(0, (int)($output[0] ?? 0));
    }
}
