#!/usr/bin/env php
<?php
/**
 * 简化的 WebSocket 测试服务器
 * 用于验证 WebSocket 基础功能
 */

require_once __DIR__ . '/backend/vendor/autoload.php';

use Workerman\Worker;

echo "🚀 启动简化 WebSocket 服务器 (端口 8282)...\n";

// 创建简单的 WebSocket 服务器
$worker = new Worker('websocket://0.0.0.0:8282');

$worker->onConnect = function($connection) {
    echo "🔗 客户端连接: {$connection->id}\n";
};

$worker->onMessage = function($connection, $data) {
    echo "📥 收到消息: {$data}\n";
    
    $msg = json_decode($data, true);
    if ($msg && isset($msg['action'])) {
        $response = [
            'action' => $msg['action'] . '_response',
            'status' => 'ok',
            'message' => 'Received: ' . $msg['action']
        ];
        $connection->send(json_encode($response));
        echo "📤 发送响应: " . json_encode($response) . "\n";
    }
};

$worker->onClose = function($connection) {
    echo "🔌 客户端断开: {$connection->id}\n";
};

$worker->onError = function($connection, $code, $msg) {
    echo "❌ 连接错误: {$code} - {$msg}\n";
};

Worker::runAll();