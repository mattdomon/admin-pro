<?php
namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Log;
use think\exception\ValidateException;

/**
 * Agent 管理控制器
 *
 * 功能：
 * - 获取 agent 列表
 * - 配置每个 agent 的模型
 * - 添加/删除 agent
 * - 更新 agent 设置
 *
 * 数据源：直接操作 ~/.openclaw/openclaw.json
 */
class AgentCtrl extends BaseController
{
    private $configPath;

    public function initialize()
    {
        parent::initialize();
        // 使用硬编码路径作为备用；先尝试 getenv、再用 $_SERVER
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/Users/hc');
        $this->configPath = $home . '/.openclaw/openclaw.json';
    }
    
    /**
     * 获取 Agent 列表
     * GET /api/agent/list
     */
    public function list()
    {
        try {
            $config = $this->loadConfig();
            
            $agents = $config['agents']['list'] ?? [];
            $bindings = $config['agents']['bindings'] ?? $config['bindings'] ?? [];
            $defaults = $config['agents']['defaults'] ?? [];
            
            // 合并 agent 信息和绑定信息
            foreach ($agents as &$agent) {
                // 查找对应的绑定信息
                $binding = null;
                foreach ($bindings as $b) {
                    if ($b['agentId'] === $agent['id']) {
                        $binding = $b;
                        break;
                    }
                }
                
                $agent['binding'] = $binding;
                $agent['isOnline'] = $this->checkAgentStatus($agent['id']);
            }
            
            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'agents' => $agents,
                    'defaults' => $defaults,
                    'total' => count($agents)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取 Agent 列表失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '获取 Agent 列表失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取可用模型列表
     * GET /api/agent/models
     */
    public function models()
    {
        try {
            $config = $this->loadConfig();
            $providers = $config['models']['providers'] ?? [];
            
            // 先从 provider 的 models[] 收集所有已定义模型
            $models = [];
            $knownFullIds = []; // 用于去重
            foreach ($providers as $providerName => $provider) {
                foreach ($provider['models'] as $model) {
                    $fullId = $providerName . '/' . $model['id'];
                    $models[] = [
                        'provider' => $providerName,
                        'id' => $model['id'],
                        'name' => $model['name'] ?? $model['id'],
                        'fullId' => $fullId,
                        'contextWindow' => $model['contextWindow'] ?? 0,
                        'cost' => $model['cost'] ?? []
                    ];
                    $knownFullIds[$fullId] = true;
                }
            }

            // 补充：从 agents.list[].model 里收集实际在用但未在 provider 里声明的模型
            // 原因：如 claude-3-5-haiku-latest 只被 agent 引用，provider 列表里没有定义
            $agents = $config['agents']['list'] ?? [];
            foreach ($agents as $agent) {
                $agentModel = $agent['model'] ?? '';
                if (empty($agentModel) || isset($knownFullIds[$agentModel])) {
                    continue;
                }
                // 解析 provider/modelId 格式
                $parts = explode('/', $agentModel, 2);
                $providerName = $parts[0];
                $modelId = $parts[1] ?? $agentModel;
                $models[] = [
                    'provider' => $providerName,
                    'id' => $modelId,
                    'name' => $modelId . ' (agent引用)',
                    'fullId' => $agentModel,
                    'contextWindow' => 0,
                    'cost' => [],
                    'fromAgent' => true  // 标记来源，前端可区分
                ];
                $knownFullIds[$agentModel] = true;
            }

            // 同样补充 agents.defaults.models 里的键
            $defaultModels = $config['agents']['defaults']['models'] ?? [];
            foreach (array_keys($defaultModels) as $fullId) {
                if (empty($fullId) || isset($knownFullIds[$fullId])) {
                    continue;
                }
                $parts = explode('/', $fullId, 2);
                $providerName = $parts[0];
                $modelId = $parts[1] ?? $fullId;
                $models[] = [
                    'provider' => $providerName,
                    'id' => $modelId,
                    'name' => $modelId,
                    'fullId' => $fullId,
                    'contextWindow' => 0,
                    'cost' => []
                ];
                $knownFullIds[$fullId] = true;
            }
            
            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $models
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取模型列表失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '获取模型列表失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 更新 Agent 主/备用模型配置
     * POST /api/agent/update-model
     */
    public function updateModel(Request $request)
    {
        try {
            $agentId = $request->param('agent_id');
            $primaryModel = $request->param('primary_model');
            $fallbackModel = $request->param('fallback_model');
            $fallbackStrategy = $request->param('fallback_strategy', 'auto');
            
            if (empty($agentId) || empty($primaryModel)) {
                return json([
                    'code' => 400,
                    'message' => 'agent_id 和 primary_model 参数必填'
                ]);
            }
            
            // 验证主模型和备用模型不能相同
            if (!empty($fallbackModel) && $primaryModel === $fallbackModel) {
                return json([
                    'code' => 400,
                    'message' => '主模型和备用模型不能相同'
                ]);
            }
            
            $config = $this->loadConfig();
            
            // 查找并更新 agent
            $updated = false;
            foreach ($config['agents']['list'] as &$agent) {
                if ($agent['id'] === $agentId) {
                    // 更新主模型（兼容旧的 model 字段）
                    $agent['model'] = $primaryModel; // 为了兼容性保留
                    $agent['primaryModel'] = $primaryModel;
                    
                    // 更新备用模型
                    if (!empty($fallbackModel)) {
                        $agent['fallbackModel'] = $fallbackModel;
                        $agent['fallbackStrategy'] = $fallbackStrategy;
                    } else {
                        // 禁用备用模型时删除相关字段
                        unset($agent['fallbackModel']);
                        unset($agent['fallbackStrategy']);
                    }
                    
                    $updated = true;
                    break;
                }
            }
            
            if (!$updated) {
                return json([
                    'code' => 404,
                    'message' => "Agent '{$agentId}' 未找到"
                ]);
            }
            
            // 保存配置
            $this->saveConfig($config);
            
            // 记录操作日志
            Log::info("Agent 主/备用模型配置更新", [
                'agent_id' => $agentId,
                'primary_model' => $primaryModel,
                'fallback_model' => $fallbackModel,
                'fallback_strategy' => $fallbackStrategy,
                'user_ip' => $request->ip()
            ]);
            
            return json([
                'code' => 200,
                'message' => 'Agent 主/备用模型配置更新成功'
            ]);
            
        } catch (\Exception $e) {
            Log::error('更新 Agent 模型失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '更新 Agent 模型失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 批量更新 Agent 模型配置
     * POST /api/agent/batch-update
     */
    public function batchUpdate(Request $request)
    {
        try {
            $updates = $request->param('updates', []);
            
            if (empty($updates) || !is_array($updates)) {
                return json([
                    'code' => 400,
                    'message' => 'updates 参数必填且必须为数组'
                ]);
            }
            
            $config = $this->loadConfig();
            $updatedCount = 0;
            
            foreach ($updates as $update) {
                $agentId = $update['agent_id'] ?? '';
                $model = $update['model'] ?? '';
                
                if (empty($agentId) || empty($model)) {
                    continue;
                }
                
                // 查找并更新 agent
                foreach ($config['agents']['list'] as &$agent) {
                    if ($agent['id'] === $agentId) {
                        $agent['model'] = $model;
                        $updatedCount++;
                        break;
                    }
                }
            }
            
            if ($updatedCount === 0) {
                return json([
                    'code' => 400,
                    'message' => '没有找到可更新的 Agent'
                ]);
            }
            
            // 保存配置
            $this->saveConfig($config);
            
            // 记录操作日志
            Log::info("批量更新 Agent 模型配置", [
                'updated_count' => $updatedCount,
                'total_requests' => count($updates),
                'user_ip' => $request->ip()
            ]);
            
            return json([
                'code' => 200,
                'message' => "成功更新 {$updatedCount} 个 Agent 的模型配置"
            ]);
            
        } catch (\Exception $e) {
            Log::error('批量更新 Agent 模型失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '批量更新失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取 Agent 详细信息
     * GET /api/agent/detail?agent_id=hc-coding
     */
    public function detail(Request $request)
    {
        try {
            $agentId = $request->param('agent_id');
            
            if (empty($agentId)) {
                return json([
                    'code' => 400,
                    'message' => 'agent_id 参数必填'
                ]);
            }
            
            $config = $this->loadConfig();
            
            // 查找 agent
            $agent = null;
            foreach ($config['agents']['list'] as $a) {
                if ($a['id'] === $agentId) {
                    $agent = $a;
                    break;
                }
            }
            
            if (!$agent) {
                return json([
                    'code' => 404,
                    'message' => "Agent '{$agentId}' 未找到"
                ]);
            }
            
            // 查找绑定信息
            $bindings = $config['bindings'] ?? [];
            $binding = null;
            foreach ($bindings as $b) {
                if ($b['agentId'] === $agentId) {
                    $binding = $b;
                    break;
                }
            }
            
            // 获取工作空间文件统计
            $workspaceStats = $this->getWorkspaceStats($agent['workspace']);
            
            // 获取 agent 目录配置文件
            $agentConfig = $this->getAgentConfig($agent['agentDir']);
            
            $agent['binding'] = $binding;
            $agent['workspaceStats'] = $workspaceStats;
            $agent['agentConfig'] = $agentConfig;
            $agent['isOnline'] = $this->checkAgentStatus($agentId);
            
            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $agent
            ]);
            
        } catch (\Exception $e) {
            Log::error("获取 Agent 详情失败: " . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '获取 Agent 详情失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 重启 Agent (通过 OpenClaw CLI)
     * POST /api/agent/restart
     */
    public function restart(Request $request)
    {
        try {
            $agentId = $request->param('agent_id');
            
            if (empty($agentId)) {
                return json([
                    'code' => 400,
                    'message' => 'agent_id 参数必填'
                ]);
            }
            
            // 使用 OpenClaw CLI 重启 agent
            $message = $request->param('message', 'Agent 重启 (来自管理面板)');
            
            $command = "openclaw agent --agent {$agentId} --message '{$message}' 2>&1";
            $output = shell_exec($command);
            
            Log::info("重启 Agent", [
                'agent_id' => $agentId,
                'command' => $command,
                'output' => $output
            ]);
            
            return json([
                'code' => 200,
                'message' => 'Agent 重启命令已执行',
                'data' => [
                    'output' => $output,
                    'command' => $command
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("重启 Agent 失败: " . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '重启 Agent 失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 加载 OpenClaw 配置
     */
    private function loadConfig()
    {
        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new \Exception('无法读取配置文件');
        }
        
        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('配置文件 JSON 格式错误: ' . json_last_error_msg());
        }
        
        return $config;
    }
    
    /**
     * 保存 OpenClaw 配置（原子写入）
     */
    private function saveConfig($config)
    {
        $content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // 原子写入：先写临时文件，成功后替换
        // 原因：防止写入中途断电/崩溃导致 openclaw.json 损坏
        $tmpPath = $this->configPath . '.tmp';
        
        if (file_put_contents($tmpPath, $content) === false) {
            throw new \Exception('无法写入临时配置文件');
        }
        
        if (!rename($tmpPath, $this->configPath)) {
            unlink($tmpPath); // 清理临时文件
            throw new \Exception('无法替换配置文件');
        }
    }
    
    /**
     * 检查 Agent 状态（简化版，实际可通过 Gateway WebSocket 检查）
     */
    private function checkAgentStatus($agentId)
    {
        // TODO: 通过 OpenClaw Gateway 或进程检查实际状态
        // 当前简化为检查工作空间目录是否存在
        $config = $this->loadConfig();
        
        foreach ($config['agents']['list'] as $agent) {
            if ($agent['id'] === $agentId) {
                return is_dir($agent['workspace']) && is_dir($agent['agentDir']);
            }
        }
        
        return false;
    }
    
    /**
     * 获取工作空间统计信息
     */
    private function getWorkspaceStats($workspacePath)
    {
        if (!is_dir($workspacePath)) {
            return null;
        }
        
        $files = 0;
        $size = 0;
        $lastModified = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspacePath));
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files++;
                    $size += $file->getSize();
                    $lastModified = max($lastModified, $file->getMTime());
                }
            }
        } catch (\Exception $e) {
            // 权限或其他问题，返回基本信息
        }
        
        return [
            'files' => $files,
            'size' => $size,
            'sizeFormatted' => $this->formatBytes($size),
            'lastModified' => $lastModified,
            'lastModifiedFormatted' => $lastModified ? date('Y-m-d H:i:s', $lastModified) : '-'
        ];
    }
    
    /**
     * 获取 Agent 配置文件信息
     */
    private function getAgentConfig($agentDir)
    {
        if (!is_dir($agentDir)) {
            return null;
        }
        
        $configFiles = [
            'models.json' => $agentDir . '/models.json',
            'auth-profiles.json' => $agentDir . '/auth-profiles.json'
        ];
        
        $configs = [];
        foreach ($configFiles as $name => $path) {
            if (file_exists($path)) {
                $size = filesize($path);
                $modified = filemtime($path);
                
                $configs[$name] = [
                    'path' => $path,
                    'size' => $size,
                    'sizeFormatted' => $this->formatBytes($size),
                    'lastModified' => date('Y-m-d H:i:s', $modified)
                ];
            }
        }
        
        return $configs;
    }
    
    /**
     * 格式化字节大小
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}