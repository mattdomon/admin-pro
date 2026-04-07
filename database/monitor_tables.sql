-- 系统监控日志表
CREATE TABLE IF NOT EXISTS `monitor_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `timestamp` int(11) NOT NULL COMMENT '时间戳',
    `cpu_percent` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'CPU使用率(%)', 
    `memory_percent` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '内存使用率(%)',
    `disk_percent` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '磁盘使用率(%)',
    `network_sent_per_sec` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '网络发送速率(字节/秒)',
    `network_recv_per_sec` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '网络接收速率(字节/秒)',
    `tasks_total` int(11) NOT NULL DEFAULT '0' COMMENT '总任务数',
    `tasks_running` int(11) NOT NULL DEFAULT '0' COMMENT '运行中任务数',
    `tasks_failed` int(11) NOT NULL DEFAULT '0' COMMENT '失败任务数',
    `created_at` datetime NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统监控日志表';

-- 监控告警配置表 (为后续告警功能预留)
CREATE TABLE IF NOT EXISTS `monitor_alerts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `alert_name` varchar(100) NOT NULL COMMENT '告警名称',
    `metric_type` enum('cpu','memory','disk','network','tasks') NOT NULL COMMENT '监控指标类型',
    `threshold_value` decimal(10,2) NOT NULL COMMENT '阈值',
    `operator` enum('gt','gte','lt','lte','eq') NOT NULL DEFAULT 'gt' COMMENT '比较操作符',
    `duration_minutes` int(11) NOT NULL DEFAULT '5' COMMENT '持续时间(分钟)',
    `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
    `notification_channels` json DEFAULT NULL COMMENT '通知渠道配置',
    `created_at` datetime NOT NULL,
    `updated_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_metric_enabled` (`metric_type`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='监控告警配置表';

-- 告警历史记录表 (为后续告警功能预留)  
CREATE TABLE IF NOT EXISTS `monitor_alert_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `alert_id` int(11) NOT NULL COMMENT '告警规则ID',
    `alert_name` varchar(100) NOT NULL COMMENT '告警名称',
    `metric_value` decimal(10,2) NOT NULL COMMENT '触发时的指标值',
    `threshold_value` decimal(10,2) NOT NULL COMMENT '阈值',
    `message` text COMMENT '告警消息',
    `is_resolved` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已解决',
    `triggered_at` datetime NOT NULL COMMENT '触发时间',
    `resolved_at` datetime DEFAULT NULL COMMENT '解决时间',
    `notification_sent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已发送通知',
    PRIMARY KEY (`id`),
    KEY `idx_alert_id` (`alert_id`),
    KEY `idx_triggered_at` (`triggered_at`),
    KEY `idx_resolved` (`is_resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='告警历史记录表';