<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 节点凭证模型
 */
class NodeKey extends Model
{
    protected $name = 'node_keys';
    protected $pk   = 'id';
    protected $autoWriteTimestamp = false;

    /**
     * 状态常量
     */
    const STATUS_PENDING = 'pending';
    const STATUS_ONLINE  = 'online';
    const STATUS_OFFLINE = 'offline';

    /**
     * 生成唯一 32 位 node_key
     */
    public static function generateKey(): string
    {
        do {
            $key = bin2hex(random_bytes(16)); // 32位小写十六进制
        } while (self::where('node_key', $key)->count() > 0);

        return $key;
    }
}
