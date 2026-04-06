<?php
declare(strict_types=1);

namespace app\service;

/**
 * 通知服务
 * 
 * 支持邮件、Webhook、短信等多种通知方式
 */
class NotificationService
{
    private $config;

    public function __construct()
    {
        // TODO: 从配置文件或数据库读取通知配置
        $this->config = [
            'email' => [
                'enabled' => true,
                'smtp_host' => env('SMTP_HOST', 'smtp.gmail.com'),
                'smtp_port' => env('SMTP_PORT', 587),
                'smtp_user' => env('SMTP_USER', ''),
                'smtp_pass' => env('SMTP_PASS', ''),
                'from_email' => env('FROM_EMAIL', 'alert@openclaw.local'),
                'from_name'  => 'OpenClaw Alert',
                'to_emails'  => explode(',', env('ALERT_EMAILS', 'admin@example.com')),
            ],
            'webhook' => [
                'enabled' => true,
                'urls' => [
                    env('WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK'),
                ],
            ],
            'wechat' => [
                'enabled' => false,
                'webhook_url' => env('WECHAT_WEBHOOK', ''),
            ],
        ];
    }

    /**
     * 发送告警通知
     */
    public function sendAlert(array $alert, array $methods = ['email']): bool
    {
        $success = true;
        
        foreach ($methods as $method) {
            try {
                switch ($method) {
                    case 'email':
                        $this->sendEmailAlert($alert);
                        break;
                    case 'webhook':
                        $this->sendWebhookAlert($alert);
                        break;
                    case 'wechat':
                        $this->sendWeChatAlert($alert);
                        break;
                }
            } catch (\Exception $e) {
                $success = false;
                // 记录错误日志
                error_log("Alert notification failed [{$method}]: " . $e->getMessage());
            }
        }
        
        return $success;
    }

    /**
     * 发送邮件告警
     */
    private function sendEmailAlert(array $alert): void
    {
        if (!$this->config['email']['enabled']) {
            return;
        }

        $subject = $this->generateEmailSubject($alert);
        $body = $this->generateEmailBody($alert);

        // 使用 PHPMailer 或其他邮件库发送
        // 这里使用简单的 mail() 函数示例
        $headers = [
            'From: ' . $this->config['email']['from_email'],
            'Reply-To: ' . $this->config['email']['from_email'],
            'Content-Type: text/html; charset=UTF-8',
        ];

        foreach ($this->config['email']['to_emails'] as $email) {
            $email = trim($email);
            if ($email) {
                mail($email, $subject, $body, implode("\r\n", $headers));
            }
        }
    }

    /**
     * 发送 Webhook 告警（Slack等）
     */
    private function sendWebhookAlert(array $alert): void
    {
        if (!$this->config['webhook']['enabled']) {
            return;
        }

        $payload = [
            'text' => $this->generateSlackMessage($alert),
            'username' => 'OpenClaw',
            'icon_emoji' => $this->getAlertEmoji($alert['level']),
        ];

        foreach ($this->config['webhook']['urls'] as $url) {
            if ($url) {
                $this->sendWebhook($url, $payload);
            }
        }
    }

    /**
     * 发送企业微信告警
     */
    private function sendWeChatAlert(array $alert): void
    {
        if (!$this->config['wechat']['enabled']) {
            return;
        }

        $payload = [
            'msgtype' => 'text',
            'text' => [
                'content' => $this->generateWeChatMessage($alert),
            ],
        ];

        $this->sendWebhook($this->config['wechat']['webhook_url'], $payload);
    }

    /**
     * 发送 Webhook 请求
     */
    private function sendWebhook(string $url, array $payload): void
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 10,
            ],
        ]);

        file_get_contents($url, false, $context);
    }

    // ===== 消息生成方法 =====

    private function generateEmailSubject(array $alert): string
    {
        $levelMap = [
            'info'     => '[信息]',
            'warning'  => '[警告]',
            'critical' => '[严重]',
        ];

        $levelPrefix = $levelMap[$alert['level']] ?? '[告警]';
        return "{$levelPrefix} OpenClaw 系统告警 - " . $alert['message'];
    }

    private function generateEmailBody(array $alert): string
    {
        $context = $alert['context'] ?? [];
        $contextHtml = '';

        foreach ($context as $key => $value) {
            $contextHtml .= "<li><strong>{$key}:</strong> " . htmlspecialchars($value) . "</li>";
        }

        return "
        <html>
        <head><title>OpenClaw 系统告警</title></head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #333; border-bottom: 2px solid #007cba; padding-bottom: 10px;'>
                    🚨 OpenClaw 系统告警
                </h2>
                
                <div style='background: #f8f9fa; padding: 15px; margin: 20px 0; border-left: 4px solid #007cba;'>
                    <h3 style='margin: 0 0 10px 0; color: #333;'>{$alert['message']}</h3>
                    <p><strong>告警级别:</strong> <span style='color: " . $this->getAlertColor($alert['level']) . ";'>" . strtoupper($alert['level']) . "</span></p>
                    <p><strong>告警类型:</strong> {$alert['type']}</p>
                    <p><strong>发生时间:</strong> {$alert['created_at']}</p>
                </div>
                
                " . ($contextHtml ? "<div style='margin: 20px 0;'><h4>详细信息:</h4><ul>{$contextHtml}</ul></div>" : "") . "
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;'>
                    <p>此邮件由 OpenClaw 系统自动发送，请勿回复。</p>
                    <p>如需帮助，请联系系统管理员。</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function generateSlackMessage(array $alert): string
    {
        $emoji = $this->getAlertEmoji($alert['level']);
        $context = $alert['context'] ?? [];
        
        $contextText = '';
        foreach ($context as $key => $value) {
            $contextText .= "\n• *{$key}:* {$value}";
        }

        return "{$emoji} *OpenClaw 告警*\n\n*{$alert['message']}*\n\n" .
               "*级别:* " . strtoupper($alert['level']) . "\n" .
               "*类型:* {$alert['type']}\n" .
               "*时间:* {$alert['created_at']}" .
               ($contextText ? "\n\n*详情:*{$contextText}" : "");
    }

    private function generateWeChatMessage(array $alert): string
    {
        $context = $alert['context'] ?? [];
        $contextText = '';
        
        foreach ($context as $key => $value) {
            $contextText .= "\n{$key}: {$value}";
        }

        return "🚨 OpenClaw 系统告警\n\n" .
               "消息: {$alert['message']}\n" .
               "级别: " . strtoupper($alert['level']) . "\n" .
               "类型: {$alert['type']}\n" .
               "时间: {$alert['created_at']}" .
               ($contextText ? "\n\n详情:{$contextText}" : "");
    }

    // ===== 辅助方法 =====

    private function getAlertColor(string $level): string
    {
        return [
            'info'     => '#17a2b8',
            'warning'  => '#ffc107',
            'critical' => '#dc3545',
        ][$level] ?? '#6c757d';
    }

    private function getAlertEmoji(string $level): string
    {
        return [
            'info'     => ':information_source:',
            'warning'  => ':warning:',
            'critical' => ':rotating_light:',
        ][$level] ?? ':bell:';
    }
}