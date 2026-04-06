<?php
/**
 * WebSocket 服务器配置
 */
return [
    // GatewayWorker 内部通信地址
    'register_address' => '127.0.0.1:1236',

    // Gateway 配置
    'gateway' => [
        'name'            => 'OpenClawGateway',
        'listen'          => 'websocket://0.0.0.0:8282',
        'count'           => 4,
        'ping_interval'   => 15,           // 15s 心跳
        'ping_not_response_limit' => 3,   // 3 次无响应则断开
        'ping_data'       => '{"action": "ping"}',
    ],

    // BusinessWorker 配置
    'business_worker' => [
        'name'          => 'OpenClawBusinessWorker',
        'count'         => 4,
        'event_handler' => \app\worker\Events::class,
    ],

    // Redis 配置 (状态缓存)
    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'db'       => (int) env('REDIS_DB', 0),
    ],
];
