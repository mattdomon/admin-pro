<?php
declare(strict_types=1);

namespace app\service;

/**
 * 统一日志服务
 */
class LogService
{
    /**
     * 日志目录
     */
    private const LOG_DIR = 'logs/';
    
    /**
     * 记录Bridge相关日志
     */
    public static function bridge(string $level, string $message, array $context = []): void
    {
        self::writeLog('bridge', $level, $message, $context);
    }
    
    /**
     * 记录任务相关日志  
     */
    public static function task(string $level, string $message, array $context = []): void
    {
        self::writeLog('task', $level, $message, $context);
    }
    
    /**
     * 记录脚本执行日志
     */
    public static function script(string $level, string $message, array $context = []): void
    {
        self::writeLog('script', $level, $message, $context);
    }
    
    /**
     * 记录OpenClaw相关日志
     */
    public static function openclaw(string $level, string $message, array $context = []): void
    {
        self::writeLog('openclaw', $level, $message, $context);
    }
    
    /**
     * 记录错误日志
     */
    public static function error(string $module, string $message, ?\Throwable $exception = null): void
    {
        $context = [
            'exception' => $exception ? [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ] : null
        ];
        
        self::writeLog($module, 'ERROR', $message, $context);
    }
    
    /**
     * 写入日志文件
     */
    private static function writeLog(string $module, string $level, string $message, array $context = []): void
    {
        try {
            $logDir = app()->getRootPath() . self::LOG_DIR;
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . $module . '_' . date('Y-m-d') . '.log';
            
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'pid' => getmypid(),
                'memory' => memory_get_usage(true)
            ];
            
            $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            
            file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            
        } catch (\Exception $e) {
            // 日志记录失败时写入系统日志
            error_log("LogService写入失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取日志文件列表
     */
    public static function getLogFiles(): array
    {
        try {
            $logDir = app()->getRootPath() . self::LOG_DIR;
            
            if (!is_dir($logDir)) {
                return [];
            }
            
            $files = glob($logDir . '*.log');
            $logs = [];
            
            foreach ($files as $file) {
                $logs[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
            
            // 按修改时间倒序
            usort($logs, function($a, $b) {
                return strcmp($b['modified'], $a['modified']);
            });
            
            return $logs;
            
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 清理旧日志文件
     */
    public static function cleanup(int $keepDays = 7): int
    {
        try {
            $logDir = app()->getRootPath() . self::LOG_DIR;
            
            if (!is_dir($logDir)) {
                return 0;
            }
            
            $cutoffTime = time() - ($keepDays * 24 * 3600);
            $files = glob($logDir . '*.log');
            $deletedCount = 0;
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                    $deletedCount++;
                }
            }
            
            return $deletedCount;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
}