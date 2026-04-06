<?php

namespace app\openclaw\controller;

use app\common\controller\Base;

/**
 * OpenClaw状态控制器
 */
class Status extends Base
{
    /**
     * 获取OpenClaw状态
     */
    public function index()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            // 执行openclaw status命令
            exec('openclaw status 2>&1', $output, $returnCode);
            
            $statusText = implode("\n", $output);
            
            // 解析状态信息
            $status = $this->parseStatus($statusText);
            
            return $this->success($status);
        } catch (\Exception $e) {
            return $this->error('获取OpenClaw状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 重启OpenClaw Gateway
     */
    public function restart()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            exec('openclaw gateway restart 2>&1', $output, $returnCode);
            
            $result = implode("\n", $output);
            
            if ($returnCode === 0) {
                return $this->success(['message' => 'Gateway重启命令已发送', 'output' => $result]);
            } else {
                return $this->error('Gateway重启失败: ' . $result);
            }
        } catch (\Exception $e) {
            return $this->error('Gateway重启失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取OpenClaw日志
     */
    public function logs()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            // 获取最近的日志
            exec('openclaw logs --tail 50 2>&1', $output, $returnCode);
            
            $logs = implode("\n", $output);
            
            return $this->success(['logs' => $logs]);
        } catch (\Exception $e) {
            return $this->error('获取日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 解析状态信息
     */
    private function parseStatus($statusText)
    {
        $status = [
            'online' => false,
            'gateway' => [
                'url' => '',
                'status' => 'offline',
                'latency' => 0,
                'pid' => null
            ],
            'overview' => [],
            'channels' => [],
            'sessions' => [],
            'raw' => $statusText
        ];

        // 检查是否在线
        if (strpos($statusText, 'reachable') !== false) {
            $status['online'] = true;
            $status['gateway']['status'] = 'online';
        }

        // 解析Gateway信息
        if (preg_match('/Gateway\s+│\s+([^│]+)/', $statusText, $matches)) {
            $gatewayLine = $matches[1];
            
            // 提取URL
            if (preg_match('/(ws:\/\/[^)]+)/', $gatewayLine, $urlMatches)) {
                $status['gateway']['url'] = $urlMatches[1];
            }
            
            // 提取延迟
            if (preg_match('/reachable (\d+)ms/', $gatewayLine, $latencyMatches)) {
                $status['gateway']['latency'] = (int)$latencyMatches[1];
            }
        }

        // 解析PID
        if (preg_match('/running \(pid (\d+)/', $statusText, $matches)) {
            $status['gateway']['pid'] = (int)$matches[1];
        }

        // 解析概览信息 - 提取表格数据
        $lines = explode("\n", $statusText);
        $inOverview = false;
        $overview = [];
        
        foreach ($lines as $line) {
            if (strpos($line, 'Overview') !== false) {
                $inOverview = true;
                continue;
            }
            
            if ($inOverview && strpos($line, '└') !== false) {
                break;
            }
            
            if ($inOverview && strpos($line, '│') !== false && strpos($line, '├') === false) {
                $parts = explode('│', $line);
                if (count($parts) >= 3) {
                    $key = trim($parts[1]);
                    $value = trim($parts[2]);
                    if (!empty($key) && !empty($value)) {
                        $overview[$key] = $value;
                    }
                }
            }
        }
        
        $status['overview'] = $overview;

        return $status;
    }
}