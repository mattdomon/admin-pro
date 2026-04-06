<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 任务模板模型
 * 
 * 用于保存常用任务配置，提高任务创建效率
 */
class TaskTemplate extends Model
{
    protected $table = 'oc_task_templates';

    protected $type = [
        'default_params' => 'json',
    ];

    /**
     * 获取预置模板
     * 
     * 系统预置一些常用模板
     */
    public static function getPresetTemplates(): array
    {
        return [
            [
                'name'            => '系统信息检查',
                'description'     => '获取节点系统信息（CPU、内存、磁盘）',
                'script_name'     => 'system_info.py',
                'default_params'  => ['check_disk' => true, 'format' => 'json'],
                'priority'        => 3,
            ],
            [
                'name'            => 'OpenClaw 健康检查',
                'description'     => '检查OpenClaw服务状态和配置',
                'script_name'     => 'openclaw_health.py',
                'default_params'  => ['check_config' => true, 'check_models' => true],
                'priority'        => 5,
            ],
            [
                'name'            => '日志收集',
                'description'     => '收集系统和应用日志',
                'script_name'     => 'collect_logs.py',
                'default_params'  => ['days' => 7, 'compress' => true],
                'priority'        => 2,
            ],
            [
                'name'            => '批量文件处理',
                'description'     => '批量处理指定目录的文件',
                'script_name'     => 'batch_process.py',
                'default_params'  => ['input_dir' => '/tmp', 'output_dir' => '/tmp/output'],
                'priority'        => 4,
            ],
        ];
    }

    /**
     * 初始化预置模板
     */
    public static function initPresetTemplates(): int
    {
        $presets = self::getPresetTemplates();
        $created = 0;

        foreach ($presets as $preset) {
            // 检查是否已存在
            if (!self::where('name', $preset['name'])->find()) {
                self::create($preset + [
                    'created_by' => 'system',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
        }

        return $created;
    }
}