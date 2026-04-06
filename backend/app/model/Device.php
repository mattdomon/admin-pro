<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 节点设备模型
 */
class Device extends Model
{
    protected $name = 'oc_devices';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'sys_info' => 'json',
        'last_heartbeat' => 'int',
        'status' => 'int',
    ];
}
