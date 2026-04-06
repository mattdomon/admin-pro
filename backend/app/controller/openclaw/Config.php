<?php
declare(strict_types=1);

namespace app\controller\openclaw;

use app\controller\BaseController;

/**
 * OpenClaw 完整配置管理
 * 
 * 提供对 ~/.openclaw/openclaw.json 的完整读写控制
 */
class Config extends BaseController
{
    protected string $configPath;
    
    public function __construct()
    {
        parent::__construct();
        $home = getenv('HOME') ?: '/Users/hc';
        $this->configPath = $home . '/.openclaw/openclaw.json';
    }
    
    /**
     * 读取完整配置文件
     * GET /openclaw/config
     */
    public function index()
    {
        try {
            if (!file_exists($this->configPath)) {
                return $this->json(404, 'openclaw.json 文件不存在');
            }
            
            $content = file_get_contents($this->configPath);
            if (!$content) {
                return $this->json(500, '无法读取配置文件');
            }
            
            $config = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(500, 'JSON 解析失败: ' . json_last_error_msg());
            }
            
            return $this->json(200, '获取配置成功', [
                'config' => $config,
                'path' => $this->configPath,
                'size' => strlen($content),
                'modified' => date('Y-m-d H:i:s', filemtime($this->configPath))
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '获取配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 保存完整配置文件
     * PUT /openclaw/config
     */
    public function save()
    {
        try {
            $raw = file_get_contents('php://input');
            if (!$raw) {
                return $this->json(400, '请求数据为空');
            }
            
            $body = json_decode($raw, true);
            if (!$body || !isset($body['config'])) {
                return $this->json(400, '配置数据无效');
            }
            
            $config = $body['config'];
            
            // 验证 JSON 有效性
            $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(400, 'JSON 格式无效: ' . json_last_error_msg());
            }
            
            // 备份原文件
            $backupPath = $this->configPath . '.backup.' . date('YmdHis');
            if (file_exists($this->configPath)) {
                copy($this->configPath, $backupPath);
            }
            
            // 原子写入
            $tmp = $this->configPath . '.tmp';
            $result = file_put_contents($tmp, $json);
            if ($result === false) {
                return $this->json(500, '写入临时文件失败');
            }
            
            if (!rename($tmp, $this->configPath)) {
                return $this->json(500, '替换配置文件失败');
            }
            
            return $this->json(200, '配置保存成功', [
                'backup' => $backupPath,
                'size' => $result
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '保存配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取配置文件备份列表
     * GET /openclaw/config/backups
     */
    public function backups()
    {
        try {
            $dir = dirname($this->configPath);
            $pattern = $dir . '/openclaw.json.backup.*';
            $files = glob($pattern);
            
            $backups = [];
            foreach ($files as $file) {
                $backups[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'created' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
            
            // 按时间倒序
            usort($backups, function($a, $b) {
                return filemtime($b['path']) - filemtime($a['path']);
            });
            
            return $this->json(200, '获取备份列表成功', $backups);
        } catch (\Exception $e) {
            return $this->json(500, '获取备份列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 恢复配置文件从备份
     * POST /openclaw/config/restore
     */
    public function restore()
    {
        try {
            $backupFile = $this->input('backup_file');
            if (empty($backupFile)) {
                return $this->json(400, '备份文件名不能为空');
            }
            
            $backupPath = dirname($this->configPath) . '/' . basename($backupFile);
            if (!file_exists($backupPath)) {
                return $this->json(404, '备份文件不存在');
            }
            
            // 验证备份文件是有效的 JSON
            $content = file_get_contents($backupPath);
            if (!$content) {
                return $this->json(500, '无法读取备份文件');
            }
            
            $config = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(400, '备份文件 JSON 格式无效');
            }
            
            // 创建当前配置的备份
            $currentBackup = $this->configPath . '.backup.before_restore.' . date('YmdHis');
            if (file_exists($this->configPath)) {
                copy($this->configPath, $currentBackup);
            }
            
            // 恢复配置
            if (!copy($backupPath, $this->configPath)) {
                return $this->json(500, '恢复配置文件失败');
            }
            
            return $this->json(200, '配置恢复成功', [
                'restored_from' => $backupFile,
                'current_backup' => basename($currentBackup)
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '恢复配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证配置文件格式
     * POST /openclaw/config/validate
     */
    public function validate()
    {
        try {
            $raw = file_get_contents('php://input');
            if (!$raw) {
                return $this->json(400, '请求数据为空');
            }
            
            $body = json_decode($raw, true);
            if (!$body || !isset($body['config'])) {
                return $this->json(400, '配置数据无效');
            }
            
            $config = $body['config'];
            
            $errors = [];
            $warnings = [];
            
            // 基本结构验证
            $requiredKeys = ['models', 'agents', 'channels'];
            foreach ($requiredKeys as $key) {
                if (!isset($config[$key])) {
                    $errors[] = "缺少必需的配置项: {$key}";
                }
            }
            
            // 模型配置验证
            if (isset($config['models']['providers'])) {
                foreach ($config['models']['providers'] as $provider => $pConfig) {
                    if (!isset($pConfig['baseUrl'])) {
                        $warnings[] = "模型提供商 {$provider} 缺少 baseUrl";
                    }
                    if (!isset($pConfig['apiKey'])) {
                        $warnings[] = "模型提供商 {$provider} 缺少 apiKey";
                    }
                    if (!isset($pConfig['models']) || !is_array($pConfig['models'])) {
                        $errors[] = "模型提供商 {$provider} 缺少模型列表";
                    }
                }
            }
            
            // Agent 配置验证
            if (isset($config['agents']['defaults']['model']['primary'])) {
                $primaryModel = $config['agents']['defaults']['model']['primary'];
                if (!empty($primaryModel)) {
                    $parts = explode('/', $primaryModel);
                    if (count($parts) !== 2) {
                        $errors[] = "默认模型格式无效: {$primaryModel}";
                    } else {
                        [$provider, $modelId] = $parts;
                        $found = false;
                        if (isset($config['models']['providers'][$provider]['models'])) {
                            foreach ($config['models']['providers'][$provider]['models'] as $model) {
                                if ($model['id'] === $modelId) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            $errors[] = "默认模型不存在: {$primaryModel}";
                        }
                    }
                }
            }
            
            $isValid = empty($errors);
            
            return $this->json(200, '验证完成', [
                'valid' => $isValid,
                'errors' => $errors,
                'warnings' => $warnings
            ]);
        } catch (\Exception $e) {
            return $this->json(500, '配置验证失败: ' . $e->getMessage());
        }
    }
}