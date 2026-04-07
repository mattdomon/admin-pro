<?php
declare(strict_types=1);

namespace app\service;

/**
 * OpenClaw 配置服务
 */
class OpenClawConfigService
{
    protected string $configPath;
    
    public function __construct()
    {
        $home = getenv('HOME') ?: '/Users/hc';
        $this->configPath = $home . '/.openclaw/openclaw.json';
    }
    
    /**
     * 读取配置
     */
    protected function readConfig(): array
    {
        if (!file_exists($this->configPath)) {
            throw new \Exception('openclaw.json 不存在');
        }
        $content = file_get_contents($this->configPath);
        if (!$content) {
            throw new \Exception('无法读取 openclaw.json');
        }
        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('openclaw.json 解析失败: ' . json_last_error_msg());
        }
        return $config;
    }
    
    /**
     * 获取主要模型配置
     */
    public function getPrimaryModel(): ?array
    {
        try {
            $config = $this->readConfig();
            $primaryModelPath = $config['agents']['defaults']['model']['primary'] ?? '';
            
            if (empty($primaryModelPath)) {
                return null;
            }
            
            // 解析 provider/modelId 格式
            $parts = explode('/', $primaryModelPath, 2);
            if (count($parts) !== 2) {
                return null;
            }
            
            [$providerId, $modelId] = $parts;
            
            return $this->findModel($providerId, $modelId);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取备用模型配置
     */
    public function getFallbackModel(): ?array
    {
        try {
            $config = $this->readConfig();
            $fallbackModelPath = $config['agents']['defaults']['model']['fallback'] ?? '';
            
            if (empty($fallbackModelPath)) {
                return null;
            }
            
            // 解析 provider/modelId 格式
            $parts = explode('/', $fallbackModelPath, 2);
            if (count($parts) !== 2) {
                return null;
            }
            
            [$providerId, $modelId] = $parts;
            
            return $this->findModel($providerId, $modelId);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 查找指定的模型配置
     */
    protected function findModel(string $providerId, string $modelId): ?array
    {
        try {
            $config = $this->readConfig();
            $providers = $config['models']['providers'] ?? [];
            
            if (!isset($providers[$providerId])) {
                return null;
            }
            
            $provider = $providers[$providerId];
            $models = $provider['models'] ?? [];
            
            foreach ($models as $model) {
                if ($model['id'] === $modelId) {
                    return [
                        'model_id' => $model['id'],
                        'model_path' => "{$providerId}/{$modelId}",
                        'name' => $model['name'] ?? $model['id'],
                        'provider' => $providerId,
                        'base_url' => $provider['baseUrl'] ?? '',
                        'api_key' => $provider['apiKey'] ?? '',
                        'api_type' => $provider['api'] ?? 'openai-completions',
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取第一个可用的模型配置
     */
    public function getFirstAvailableModel(): ?array
    {
        try {
            $config = $this->readConfig();
            $providers = $config['models']['providers'] ?? [];
            
            foreach ($providers as $providerId => $provider) {
                $models = $provider['models'] ?? [];
                if (!empty($models)) {
                    $firstModel = $models[0];
                    return [
                        'model_id' => $firstModel['id'],
                        'model_path' => "{$providerId}/{$firstModel['id']}",
                        'name' => $firstModel['name'] ?? $firstModel['id'],
                        'provider' => $providerId,
                        'base_url' => $provider['baseUrl'] ?? '',
                        'api_key' => $provider['apiKey'] ?? '',
                        'api_type' => $provider['api'] ?? 'openai-completions',
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}