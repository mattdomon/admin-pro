<?php
declare(strict_types=1);

namespace app\controller\openclaw;

use app\controller\BaseController;

/**
 * OpenClaw 核心接口控制器
 * 
 * 这个模块是对接 OpenClaw 的核心接口
 * 可以轻松剥离到其他项目使用
 */
class OpenClaw extends BaseController
{
    protected $scriptsPath;
    
    public function __construct()
    {
        parent::__construct();
        $this->scriptsPath = env('OPENCLAW_SCRIPTS_PATH', app()->getRootPath() . 'scripts');
    }
    
    /**
     * 获取系统状态
     */
    public function status()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            // 执行openclaw status命令
            exec('openclaw status 2>&1', $output, $returnCode);
            
            $statusText = implode("\n", $output);
            
            // 解析状态信息
            $status = $this->parseOpenClawStatus($statusText);
            
            return $this->json(200, '获取成功', $status);
        } catch (\Exception $e) {
            return $this->json(500, '获取OpenClaw状态失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取脚本列表
     */
    public function scripts()
    {
        $scripts = [];
        $path = $this->scriptsPath;
        
        if (is_dir($path)) {
            $files = glob($path . '/*.py');
            foreach ($files as $file) {
                $scripts[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }
        
        return $this->json(200, '获取成功', $scripts);
    }
    
    /**
     * 执行脚本
     */
    public function execute()
    {
        $scriptName = $this->input('script_name');
        $params = $this->input('params', '');
        
        if (empty($scriptName)) {
            return $this->json(400, '脚本名称不能为空');
        }
        
        $scriptPath = $this->scriptsPath . '/' . $scriptName;
        
        if (!file_exists($scriptPath)) {
            return $this->json(404, '脚本不存在');
        }
        
        $startTime = microtime(true);
        $command = "python3 " . escapeshellarg($scriptPath) . " " . $params;
        
        exec($command, $output, $returnCode);
        
        $executionTime = round(microtime(true) - $startTime, 3);
        
        return $this->json(200, '执行完成', [
            'script_name' => $scriptName,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'execution_time' => $executionTime,
            'success' => $returnCode === 0
        ]);
    }
    
    /**
     * 上传脚本
     */
    public function uploadScript()
    {
        $file = $this->request->file('file');
        
        if (!$file) {
            return $this->json(400, '请选择文件');
        }
        
        $info = $file->move($this->scriptsPath);
        
        if ($info) {
            return $this->json(200, '上传成功', [
                'name' => $info->getFilename(),
                'path' => $this->scriptsPath . '/' . $info->getFilename()
            ]);
        }
        
        return $this->json(500, '上传失败: ' . $file->getError());
    }
    
    /**
     * 删除脚本
     */
    public function deleteScript()
    {
        $scriptName = $this->input('name');
        
        if (empty($scriptName)) {
            return $this->json(400, '脚本名称不能为空');
        }
        
        $scriptPath = $this->scriptsPath . '/' . $scriptName;
        
        if (!file_exists($scriptPath)) {
            return $this->json(404, '脚本不存在');
        }
        
        if (unlink($scriptPath)) {
            return $this->json(200, '删除成功');
        }
        
        return $this->json(500, '删除失败');
    }
    
    /**
     * 重启 OpenClaw Gateway
     */
    public function restart()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            // 执行重启命令
            exec('openclaw gateway restart 2>&1', $output, $returnCode);
            
            $result = implode("\n", $output);
            
            if ($returnCode === 0) {
                return $this->json(200, 'Gateway 重启成功', [
                    'output' => $result
                ]);
            } else {
                return $this->json(500, 'Gateway 重启失败', [
                    'output' => $result,
                    'code' => $returnCode
                ]);
            }
        } catch (\Exception $e) {
            return $this->json(500, 'Gateway 重启失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 停止 OpenClaw Gateway
     */
    public function stop()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            exec('openclaw gateway stop 2>&1', $output, $returnCode);
            
            $result = implode("\n", $output);
            
            return $this->json(200, '停止命令已执行', [
                'output' => $result,
                'code' => $returnCode
            ]);
        } catch (\Exception $e) {
            return $this->json(500, 'Gateway 停止失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 启动 OpenClaw Gateway
     */
    public function start()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            exec('openclaw gateway start 2>&1', $output, $returnCode);
            
            $result = implode("\n", $output);
            
            if ($returnCode === 0) {
                return $this->json(200, 'Gateway 启动成功', [
                    'output' => $result
                ]);
            } else {
                return $this->json(500, 'Gateway 启动失败', [
                    'output' => $result,
                    'code' => $returnCode
                ]);
            }
        } catch (\Exception $e) {
            return $this->json(500, 'Gateway 启动失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取 OpenClaw 日志
     */
    public function logs()
    {
        try {
            $lines = $this->input('lines', 100);
            $follow = $this->input('follow', false);
            
            $output = [];
            $returnCode = 0;
            
            if ($follow) {
                // 实时日志需要特殊处理，这里先返回最近日志
                exec("openclaw logs --lines {$lines} 2>&1", $output, $returnCode);
            } else {
                exec("openclaw logs --lines {$lines} 2>&1", $output, $returnCode);
            }
            
            $logs = implode("\n", $output);
            
            return $this->json(200, '获取日志成功', [
                'logs' => $logs,
                'lines' => count($output)
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '获取日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取 OpenClaw 版本信息
     */
    public function version()
    {
        try {
            $output = [];
            $returnCode = 0;
            
            exec('openclaw --version 2>&1', $output, $returnCode);
            
            $version = $output[0] ?? 'unknown';
            
            return $this->json(200, '获取版本成功', [
                'version' => $version
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '获取版本失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 执行自定义 OpenClaw 命令
     */
    public function command()
    {
        try {
            $cmd = $this->input('command', '');
            
            if (empty($cmd)) {
                return $this->json(400, '命令不能为空');
            }
            
            // 安全检查：只允许 openclaw 开头的命令
            if (!str_starts_with(trim($cmd), 'openclaw ')) {
                return $this->json(400, '只允许执行 openclaw 命令');
            }
            
            $output = [];
            $returnCode = 0;
            
            exec($cmd . ' 2>&1', $output, $returnCode);
            
            $result = implode("\n", $output);
            
            return $this->json(200, '命令执行完成', [
                'command' => $cmd,
                'output' => $result,
                'code' => $returnCode,
                'success' => $returnCode === 0
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '命令执行失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取服务状态
     */
    private function getServiceStatus()
    {
        exec("ps aux | grep -v grep | grep 'openclaw' | wc -l", $output);
        return (int)($output[0] ?? 0) > 0 ? 'running' : 'stopped';
    }
    
    /**
     * 获取网关状态
     */
    private function getGatewayStatus()
    {
        exec("ps aux | grep -v grep | grep 'openclaw-gateway' | wc -l", $output);
        return (int)($output[0] ?? 0) > 0 ? 'running' : 'stopped';
    }
    
    /**
     * 获取 OpenClaw 版本
     */
    private function getOpenClawVersion()
    {
        exec("openclaw --version 2>/dev/null || echo 'unknown'", $output);
        return $output[0] ?? 'unknown';
    }
    
    /**
     * 获取脚本数量
     */
    private function getScriptsCount()
    {
        if (!is_dir($this->scriptsPath)) {
            return 0;
        }
        $files = glob($this->scriptsPath . '/*.py');
        return count($files);
    }
    
    /**
     * 解析OpenClaw状态信息
     */
    private function parseOpenClawStatus($statusText)
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
            'service_status' => 'stopped',
            'gateway_status' => 'stopped',
            'version' => 'unknown',
            'scripts_count' => $this->getScriptsCount(),
            'system_time' => date('Y-m-d H:i:s')
        ];

        // 检查是否在线
        if (strpos($statusText, 'reachable') !== false) {
            $status['online'] = true;
            $status['service_status'] = 'running';
            $status['gateway']['status'] = 'online';
            $status['gateway_status'] = 'running';
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
        
        // 解析版本 - 匹配 "app 2026.4.5" 格式
        if (preg_match('/app ([\d.]+)/', $statusText, $matches)) {
            $status['version'] = 'OpenClaw ' . $matches[1];
        } else {
            // 备用方案：直接执行 openclaw --version
            $versionOutput = $this->getOpenClawVersion();
            if ($versionOutput !== 'unknown') {
                $status['version'] = $versionOutput;
            }
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