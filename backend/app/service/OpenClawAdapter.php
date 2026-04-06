<?php
declare(strict_types=1);

namespace app\service;

use app\common\contract\AutomatorInterface;

/**
 * OpenClaw 适配器 - AutomatorInterface 的专属实现
 *
 * 负责将标准化的任务调度指令转换为 OpenClaw 专属协议，
 * 通过 GatewayWorker 向指定节点推送。
 */
class OpenClawAdapter implements AutomatorInterface
{
    /**
     * 下发任务到指定设备节点
     *
     * 组 OpenClaw 专属协议并通过 Gateway 向设备推送
     */
    public function dispatchTask(string $deviceUuid, string $taskId, string $scriptName, array $params): bool
    {
        $payload = json_encode([
            'action' => 'execute',
            'task_id' => $taskId,
            'payload' => [
                'target_script' => $scriptName,
                'llm_config' => $params['llm_config'] ?? $params['llm'] ?? [],
                'objective' => $params['objective'] ?? '',
            ],
        ]);

        if (!class_exists('\GatewayWorker\Lib\Gateway')) {
            // GatewayWorker 不可用时降级到日志
            trace("OpenClawAdapter: Gateway 不可用, 记录指令: {$payload}", 'debug');
            return true;
        }

        try {
            return \GatewayWorker\Lib\Gateway::sendToUid($deviceUuid, $payload);
        } catch (\Throwable $e) {
            trace("OpenClawAdapter dispatchTask 失败: {$e->getMessage()}", 'error');
            return false;
        }
    }

    /**
     * 终止指定设备节点上的运行任务
     *
     * 通过 Gateway 向设备发送 kill 指令
     */
    public function killTask(string $deviceUuid, string $taskId): bool
    {
        $payload = json_encode([
            'action' => 'kill',
            'task_id' => $taskId,
        ]);

        if (!class_exists('\GatewayWorker\Lib\Gateway')) {
            trace("OpenClawAdapter: Gateway 不可用, 记录指令: {$payload}", 'debug');
            return true;
        }

        try {
            return \GatewayWorker\Lib\Gateway::sendToUid($deviceUuid, $payload);
        } catch (\Throwable $e) {
            trace("OpenClawAdapter killTask 失败: {$e->getMessage()}", 'error');
            return false;
        }
    }
}
