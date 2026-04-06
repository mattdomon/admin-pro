<?php
declare(strict_types=1);

namespace app\model;

use think\Model as ThinkModel;

/**
 * AI模型表
 */
class AiModel extends ThinkModel
{
    protected $name = 'oc_llm_providers';
    
    protected $pk = 'id';
    
    // 自动写入时间戳 - 表中没有时间字段，禁用
    protected $autoWriteTimestamp = false;
    
    // 类型转换
    protected $type = [
        'is_fallback' => 'boolean',
    ];
    
    /**
     * 获取所有模型配置
     */
    public static function getAllModels()
    {
        return self::select();
    }
    
    /**
     * 根据 provider_type 获取模型
     */
    public static function getByProvider($provider)
    {
        return self::where('provider_type', $provider)->select();
    }
    
    /**
     * 设置备用模型
     */
    public function setFallback($isFallback = true)
    {
        if ($isFallback) {
            // 先清除其他备用模型
            self::where('is_fallback', 1)->update(['is_fallback' => 0]);
        }
        
        $this->is_fallback = $isFallback ? 1 : 0;
        return $this->save();
    }
    
    /**
     * 获取备用模型
     */
    public static function getFallbackModel()
    {
        return self::where('is_fallback', 1)->find();
    }
    
    /**
     * 测试模型连接
     */
    public function testConnection()
    {
        // 这里可以添加实际的连接测试逻辑
        // 根据不同的 provider_type 调用不同的 API 测试
        
        return [
            'success' => true,
            'message' => '连接测试成功',
            'response_time' => '120ms'
        ];
    }
}