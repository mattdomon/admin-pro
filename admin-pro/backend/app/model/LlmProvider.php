<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 大模型配置模型
 */
class LlmProvider extends Model
{
    protected $name = 'oc_llm_providers';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'is_fallback' => 'bool',
    ];
}
