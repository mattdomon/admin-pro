<?php

namespace app\openclaw\controller;

use app\common\controller\Base;
use think\facade\Config;

/**
 * OpenClaw监控控制器
 */
class Monitor extends Base
{
    /**
     * 获取OpenClaw状态概览
     */
    public function overview()
    {
        try {
            $status = $this->execOpenClawCommand('status');
            
            // 解析状态信息
            $overview = $this->parseOpenClawStatus($status);
            
            return $this->success($overview);
        } catch (\Exception $e) {
            return $this->error('获取OpenClaw状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取网关状态
     */
    public function gateway()
    {
        try {
            $status = $this->execOpenClawCommand('status');
            
            // 解析网关信息
            $gateway = $this->parseGatewayInfo($status);
            
            return $this->success($gateway);
        } catch (\Exception $e) {
            return $this->error('获取网关状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取Agent列表
     */
    public function agents()
    {
        try {
            $status = $this->execOpenClawCommand('status --deep');
            
            // 解析Agent信息
            $agents = $this->parseAgentsInfo($status);
            
            return $this->success($agents);
        } catch (\Exception $e) {
            return $this->error('获取Agent信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取会话列表
     */
    public function sessions()
    {
        try {
            $status = $this->execOpenClawCommand('status');
            
            // 解析会话信息
            $sessions = $this->parseSessionsInfo($status);
            
            return $this->success($sessions);
        } catch (\Exception $e) {
            return $this->error('获取会话信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取任务状态
     */
    public function tasks()
    {
        try {
            $status = $this->execOpenClawCommand('status');
            
            // 解析任务信息
            $tasks = $this->parseTasksInfo($status);
            
            return $this->success($tasks);
        } catch (\Exception $e) {
            return $this->error('获取任务信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取完整监控数据
     */
    public function index()
    {
        try {
            $status = $this->execOpenClawCommand('status');
            
            $data = [
                'overview' => $this->parseOpenClawStatus($status),
                'gateway' => $this->parseGatewayInfo($status),
                'agents' => $this->parseAgentsInfo($status),
                'sessions' => $this->parseSessionsInfo($status),
                'tasks' => $this->parseTasksInfo($status),
            ];
            
            return $this->success($data);
        } catch (\Exception $e) {
            return $this->error('获取监控数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 执行OpenClaw命令
     */
    private function execOpenClawCommand($command)
    {
        $fullCommand = "openclaw {$command} 2>&1";
        $output = [];
        $returnCode = 0;
        
        exec($fullCommand, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('OpenClaw命令执行失败: ' . implode("\n", $output));
        }
        
        return implode("\n", $output);
    }

    /**
     * 解析OpenClaw状态概览
     */
    private function parseOpenClawStatus($status)
    {
        $overview = [
            'online' => false,
            'version' => 'Unknown',
            'uptime' => 'Unknown',
            'memory' => 'Unknown',
            'agents' => 0,
            'sessions' => 0,
        ];

        // 检查Gateway是否在线
        if (strpos($status, 'reachable') !== false) {
            $overview['online'] = true;
        }

        // 提取版本信息
        if (preg_match('/app ([\d.]+)/', $status, $matches)) {
            $overview['version'] = $matches[1];
        }

        // 提取Agent数量
        if (preg_match('/Agents\s+│\s+(\d+)/', $status, $matches)) {
            $overview['agents'] = (int)$matches[1];
        }

        // 提取会话数量
        if (preg_match('/Sessions\s+│\s+(\d+)\s+active/', $status, $matches)) {
            $overview['sessions'] = (int)$matches[1];
        }

        // 提取Memory状态
        if (preg_match('/Memory\s+│\s+([^│]+)/', $status, $matches)) {
            $overview['memory'] = trim($matches[1]);
        }

        return $overview;
    }

    /**
     * 解析网关信息
     */
    private function parseGatewayInfo($status)
    {
        $gateway = [
            'status' => 'offline',
            'url' => '',
            'latency' => 0,
            'pid' => null,
        ];

        // 检查Gateway状态
        if (preg_match('/Gateway\s+│\s+([^│]+)/', $status, $matches)) {
            $gatewayInfo = $matches[1];
            
            if (strpos($gatewayInfo, 'reachable') !== false) {
                $gateway['status'] = 'online';
            }
            
            // 提取URL
            if (preg_match('/(ws:\/\/[^)]+)/', $gatewayInfo, $urlMatches)) {
                $gateway['url'] = $urlMatches[1];
            }
            
            // 提取延迟
            if (preg_match('/reachable (\d+)ms/', $gatewayInfo, $latencyMatches)) {
                $gateway['latency'] = (int)$latencyMatches[1];
            }
        }

        // 提取PID
        if (preg_match('/running \(pid (\d+)/', $status, $matches)) {
            $gateway['pid'] = (int)$matches[1];
        }

        return $gateway;
    }

    /**
     * 解析Agent信息
     */
    private function parseAgentsInfo($status)
    {
        $agents = [];
        
        // 这里可以进一步解析Agent详细信息
        // 暂时返回基本信息
        if (preg_match('/Agents\s+│\s+(\d+)/', $status, $matches)) {
            $agentCount = (int)$matches[1];
            
            $agents = [
                'total' => $agentCount,
                'active' => $agentCount, // 简化处理
                'list' => []
            ];
        }

        return $agents;
    }

    /**
     * 解析会话信息
     */
    private function parseSessionsInfo($status)
    {
        $sessions = [
            'total' => 0,
            'active' => 0,
            'list' => []
        ];

        // 提取会话数量
        if (preg_match('/Sessions\s+│\s+(\d+)\s+active/', $status, $matches)) {
            $sessions['total'] = (int)$matches[1];
            $sessions['active'] = (int)$matches[1];
        }

        return $sessions;
    }

    /**
     * 解析任务信息
     */
    private function parseTasksInfo($status)
    {
        $tasks = [
            'active' => 0,
            'queued' => 0,
            'running' => 0,
            'issues' => 0,
        ];

        // 提取任务信息
        if (preg_match('/Tasks\s+│\s+(\d+)\s+active\s+·\s+(\d+)\s+queued\s+·\s+(\d+)\s+running\s+·\s+(\d+)\s+issues/', $status, $matches)) {
            $tasks['active'] = (int)$matches[1];
            $tasks['queued'] = (int)$matches[2]; 
            $tasks['running'] = (int)$matches[3];
            $tasks['issues'] = (int)$matches[4];
        }

        return $tasks;
    }
}