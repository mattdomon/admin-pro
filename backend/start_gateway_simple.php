#!/usr/bin/env php
<?php
/**
 * 修复版 GatewayWorker 启动脚本
 * 
 * 简化配置，修复 WebSocket 握手问题
 */

ini_set('display_errors', 'on');
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use GatewayWorker\Gateway;
use GatewayWorker\BusinessWorker;
use GatewayWorker\Register;

// 防止重复启动
if (!defined('GLOBAL_START')) {
    define('GLOBAL_START', 1);
}

// ====== 1. Register 服务 ======
$register = new Register('text://0.0.0.0:1236');

// ====== 2. Gateway 服务 ======  
$gateway = new Gateway("websocket://0.0.0.0:8282");
$gateway->name                 = 'Gateway';
$gateway->count                = 1;  // 简化为 1 个进程
$gateway->lanIp                = '127.0.0.1';
$gateway->startPort            = 2900;
$gateway->registerAddress      = '127.0.0.1:1236';

// 心跳配置
$gateway->pingInterval         = 15;
$gateway->pingNotResponseLimit = 3;
$gateway->pingData             = '{"action":"ping"}';

// 加载事件处理器
require_once __DIR__ . '/Events.php';

// ====== 3. BusinessWorker 服务 ======
$businessWorker = new BusinessWorker();
$businessWorker->name          = 'BusinessWorker';
$businessWorker->count         = 1;  // 简化为 1 个进程
$businessWorker->registerAddress = '127.0.0.1:1236';
$businessWorker->eventHandler  = 'Events';

// 开始运行
Worker::runAll();