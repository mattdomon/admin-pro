<?php
/**
 * 简化版 GatewayWorker 事件处理器
 * 
 * 专注于基础 WebSocket 功能，避免复杂依赖
 */

use GatewayWorker\Lib\Gateway;

class Events
{
    /**
     * 客户端连接时触发
     */
    public static function onConnect($clientId)
    {
        echo "[Connect] 客户端连接: {$clientId}\n";
        
        // 发送欢迎消息
        Gateway::sendToClient($clientId, json_encode([
            'action' => 'welcome',
            'message' => 'Connected to OpenClaw Admin Gateway',
            'clientId' => $clientId
        ]));
    }

    /**
     * 收到客户端消息时触发
     */
    public static function onMessage($clientId, $message)
    {
        echo "[Message] {$clientId}: {$message}\n";
        
        $data = json_decode($message, true);
        if (!$data) {
            Gateway::sendToClient($clientId, json_encode(['error' => 'Invalid JSON']));
            return;
        }

        $action = $data['action'] ?? '';

        switch ($action) {
            case 'auth':
                self::handleAuth($clientId, $data);
                break;
                
            case 'ping':
                self::handlePing($clientId, $data);
                break;
                
            default:
                echo "[Warning] 未知 action: {$action}\n";
                Gateway::sendToClient($clientId, json_encode([
                    'action' => 'error',
                    'message' => "Unknown action: {$action}"
                ]));
        }
    }

    /**
     * 客户端断开连接时触发
     */
    public static function onClose($clientId)
    {
        echo "[Close] 客户端断开: {$clientId}\n";
        
        // 解绑 UID（如果有绑定的话）
        $uid = Gateway::getUidByClientId($clientId);
        if ($uid) {
            Gateway::unbindUid($clientId);
            echo "[Close] 解绑 UID: {$uid}\n";
        }
    }

    /**
     * 处理认证
     */
    private static function handleAuth($clientId, $data)
    {
        $uuid = $data['uuid'] ?? '';
        $token = $data['token'] ?? '';
        
        echo "[Auth] 认证请求: UUID={$uuid}, Token={$token}\n";

        if (empty($uuid)) {
            Gateway::sendToClient($clientId, json_encode([
                'action' => 'auth_failed', 
                'reason' => 'UUID required'
            ]));
            return;
        }

        // 简化认证：直接绑定 UID
        Gateway::bindUid($clientId, $uuid);
        
        Gateway::sendToClient($clientId, json_encode([
            'action' => 'auth_success',
            'message' => 'Authentication successful',
            'uuid' => $uuid
        ]));
        
        echo "[Auth] 认证成功: {$uuid} -> {$clientId}\n";
    }

    /**
     * 处理心跳
     */
    private static function handlePing($clientId, $data)
    {
        $uuid = $data['uuid'] ?? '';
        $cpu = $data['cpu'] ?? '';
        $mem = $data['mem'] ?? '';
        
        echo "[Ping] 心跳: UUID={$uuid}, CPU={$cpu}, MEM={$mem}\n";
        
        // 发送 pong 响应
        Gateway::sendToClient($clientId, json_encode([
            'action' => 'pong',
            'message' => 'Heartbeat received',
            'timestamp' => time()
        ]));
    }

    /**
     * Worker 启动时触发
     */
    public static function onWorkerStart($worker)
    {
        echo "[Worker] BusinessWorker {$worker->id} 启动完成\n";
    }
}