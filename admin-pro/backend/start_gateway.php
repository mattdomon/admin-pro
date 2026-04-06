#!/usr/bin/env php
<?php
/**
 * GatewayWorker 启动入口
 *
 * 启动命令: php start_gateway.php start -d
 * 调试命令: php start_gateway.php start
 */
ini_set('display_errors', 'on');

if (strpos(strtolower(PHP_OS), 'win') === 0) {
    exit("此脚本只能在 Linux/Unix 上运行\n");
}

require_once __DIR__ . '/vendor/autoload.php';

// 初始化 ThinkPHP 框架上下文，使 config() 等辅助函数可用
$app = new \think\App(__DIR__);
$app->initialize();

$workerConfig = config('worker_server') ?: [];
$gatewayConfig = $workerConfig['gateway'] ?? [];
$businessConfig = $workerConfig['business_worker'] ?? [];
$registerAddress = $workerConfig['register_address'] ?? '127.0.0.1:1236';

// Register
$register = new \GatewayWorker\Register();
$register->name = 'OpenClawRegister';

// Gateway
$gateway = new \GatewayWorker\Gateway($gatewayConfig['listen'] ?? 'websocket://0.0.0.0:8282');
$gateway->name                = $gatewayConfig['name'] ?? 'OpenClawGateway';
$gateway->count               = $gatewayConfig['count'] ?? 4;
$gateway->lanIp               = '127.0.0.1';
$gateway->startPort           = 2900;
$gateway->registerAddress     = $registerAddress;
$gateway->pingInterval        = $gatewayConfig['ping_interval'] ?? 15;
$gateway->pingNotResponseLimit = $gatewayConfig['ping_not_response_limit'] ?? 3;
$gateway->pingData             = $gatewayConfig['ping_data'] ?? '{"action":"ping"}';

// BusinessWorker
$businessWorker               = new \GatewayWorker\BusinessWorker();
$businessWorker->name         = $businessConfig['name'] ?? 'OpenClawBusinessWorker';
$businessWorker->count        = $businessConfig['count'] ?? 4;
$businessWorker->registerAddress = $registerAddress;
$businessWorker->eventHandler = $businessConfig['event_handler'] ?? \app\worker\Events::class;

\Workerman\Worker::runAll();
