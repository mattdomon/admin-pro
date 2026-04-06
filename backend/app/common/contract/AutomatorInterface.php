<?php
declare(strict_types=1);

namespace app\common\contract;

/**
 * 自动化工具引擎解耦标准接口
 *
 * 所有底层自动化实现必须实现此接口，确保底层引擎（OpenClaw 或其他）
 * 可随时被替换而不影响上层业务逻辑。
 */
interface AutomatorInterface
{
    /**
     * 下发任务到指定设备节点
     *
     * @param string $deviceUuid 设备唯一标识
     * @param string $taskId 全局唯一任务ID
     * @param string $scriptName Python脚本名称
     * @param array $params 动态参数（含LLM配置、目标指令等）
     * @return bool 是否成功推送到节点
     */
    public function dispatchTask(string $deviceUuid, string $taskId, string $scriptName, array $params): bool;

    /**
     * 终止指定设备节点上的运行任务
     *
     * @param string $deviceUuid 设备唯一标识
     * @param string $taskId 全局唯一任务ID
     * @return bool 是否成功发送终止指令
     */
    public function killTask(string $deviceUuid, string $taskId): bool;
}
