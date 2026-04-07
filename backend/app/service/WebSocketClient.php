<?php
declare(strict_types=1);

namespace app\service;

class WebSocketClient
{
    private string $host;
    private int $port;
    private $socket;
    private bool $connected = false;
    private int $maxRetries = 3;
    private int $connectTimeout = 10; // 连接超时秒数

    public function __construct(string $host = 'localhost', int $port = 8282)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * 连接到 WebSocket 服务器（带重连机制）
     */
    public function connect(): bool
    {
        $attempts = 0;
        while ($attempts < $this->maxRetries) {
            if ($this->attemptConnect()) {
                $this->connected = true;
                return true;
            }
            $attempts++;
            if ($attempts < $this->maxRetries) {
                $this->logError("连接失败，等待重试... ({$attempts}/{$this->maxRetries})");
                sleep(1); // 等待1秒后重试
            }
        }
        
        $this->logError("达到最大重试次数，连接失败");
        return false;
    }

    /**
     * 单次连接尝试
     */
    private function attemptConnect(): bool
    {
        try {
            // 创建 socket
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$this->socket) {
                throw new \Exception('无法创建 socket: ' . socket_strerror(socket_last_error()));
            }

            // 设置连接超时
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, 
                ['sec' => $this->connectTimeout, 'usec' => 0]);
            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, 
                ['sec' => $this->connectTimeout, 'usec' => 0]);

            // 尝试连接
            $result = socket_connect($this->socket, $this->host, $this->port);
            if (!$result) {
                $error = socket_strerror(socket_last_error($this->socket));
                throw new \Exception("无法连接到 {$this->host}:{$this->port} - {$error}");
            }

            // 执行 WebSocket 握手
            if (!$this->sendHandshake()) {
                throw new \Exception('WebSocket 握手失败');
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logError("连接失败: " . $e->getMessage());
            if ($this->socket) {
                socket_close($this->socket);
                $this->socket = null;
            }
            return false;
        }
    }

    /**
     * 发送消息到 WebSocket 服务器
     */
    public function sendMessage(array $data): bool
    {
        // 检查连接状态，如果断开则尝试重连
        if (!$this->connected && !$this->connect()) {
            $this->logError("无法建立 WebSocket 连接");
            return false;
        }

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \Exception('JSON 编码失败');
            }
            
            $frame = $this->createFrame($json);
            
            $result = socket_write($this->socket, $frame, strlen($frame));
            if ($result === false) {
                $error = socket_strerror(socket_last_error($this->socket));
                throw new \Exception("发送数据失败: {$error}");
            }
            
            $this->logInfo("成功发送消息: " . substr($json, 0, 100) . (strlen($json) > 100 ? '...' : ''));
            return true;
            
        } catch (\Exception $e) {
            $this->logError("发送消息失败: " . $e->getMessage());
            // 连接可能断开，标记为未连接状态
            $this->connected = false;
            return false;
        }
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->socket) {
            // 发送关闭帧
            $closeFrame = chr(0x88) . chr(0x80) . pack('N', random_int(0, 0xFFFFFFFF));
            socket_write($this->socket, $closeFrame, strlen($closeFrame));
            
            socket_close($this->socket);
            $this->socket = null;
            $this->connected = false;
            $this->logInfo("WebSocket 连接已关闭");
        }
    }

    /**
     * 加固后的握手方法，增加校验
     */
    private function sendHandshake(): bool
    {
        try {
            $key = base64_encode(random_bytes(16));
            $handshake = "GET / HTTP/1.1\r\n" .
                        "Host: {$this->host}:{$this->port}\r\n" .
                        "Upgrade: websocket\r\n" .
                        "Connection: Upgrade\r\n" .
                        "Sec-WebSocket-Key: {$key}\r\n" .
                        "Sec-WebSocket-Version: 13\r\n" .
                        "User-Agent: AdminPro-WebSocketClient/1.0\r\n" .
                        "\r\n";
            
            // 发送握手请求
            $result = socket_write($this->socket, $handshake, strlen($handshake));
            if ($result === false) {
                $this->logError("握手请求发送失败");
                return false;
            }
            
            // 读取握手响应（增加超时保护）
            $response = socket_read($this->socket, 2048);
            if ($response === false) {
                $this->logError("读取握手响应失败");
                return false;
            }
            
            // 校验响应状态码
            if (strpos($response, '101') === false) {
                $this->logError("WebSocket 握手失败，服务器响应: " . trim($response));
                return false;
            }
            
            // 校验 Upgrade 头
            if (stripos($response, 'upgrade: websocket') === false) {
                $this->logError("握手响应缺少 Upgrade: websocket 头");
                return false;
            }
            
            // 校验 Connection 头
            if (stripos($response, 'connection: upgrade') === false) {
                $this->logError("握手响应缺少 Connection: Upgrade 头");
                return false;
            }
            
            // 可选：校验 Sec-WebSocket-Accept（更严格的验证）
            $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (strpos($response, "sec-websocket-accept: {$expectedAccept}") === false) {
                $this->logError("握手响应 Sec-WebSocket-Accept 校验失败");
                // 注意：某些服务器可能不严格遵循此校验，可以根据实际情况选择是否返回false
            }
            
            $this->logInfo("WebSocket 握手成功完成");
            return true;
            
        } catch (\Exception $e) {
            $this->logError("握手过程异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建 WebSocket 数据帧
     */
    private function createFrame(string $data): string
    {
        $length = strlen($data);
        $header = chr(0x81); // FIN=1, Text Frame
        
        // 处理不同长度的数据
        if ($length < 126) {
            $header .= chr($length | 0x80); // MASK=1
        } elseif ($length < 65536) {
            $header .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $header .= chr(127 | 0x80) . pack('J', $length);
        }
        
        // 生成随机掩码
        $mask = random_bytes(4);
        $header .= $mask;
        
        // 对数据应用掩码
        $masked_data = '';
        for ($i = 0; $i < $length; $i++) {
            $masked_data .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }
        
        return $header . $masked_data;
    }

    /**
     * 检查连接状态
     */
    public function isConnected(): bool
    {
        if (!$this->connected || !$this->socket) {
            return false;
        }
        
        // 发送 ping 帧检测连接
        try {
            $pingFrame = chr(0x89) . chr(0x80) . pack('N', random_int(0, 0xFFFFFFFF));
            $result = socket_write($this->socket, $pingFrame, strlen($pingFrame));
            return $result !== false;
        } catch (\Exception $e) {
            $this->connected = false;
            return false;
        }
    }

    private function logInfo(string $message): void
    {
        error_log("[WebSocketClient][INFO] " . $message);
    }

    private function logError(string $message): void
    {
        error_log("[WebSocketClient][ERROR] " . $message);
    }

    /**
     * 析构函数，确保连接被正确关闭
     */
    public function __destruct()
    {
        $this->close();
    }
}