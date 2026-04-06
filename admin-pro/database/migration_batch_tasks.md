# 批量任务调度 - 数据库迁移

## 1. 为 oc_tasks 表添加新字段

```sql
-- 添加批次ID和优先级字段
ALTER TABLE oc_tasks 
ADD COLUMN batch_id VARCHAR(64) DEFAULT NULL COMMENT '批次ID，用于批量任务管理',
ADD COLUMN priority INT DEFAULT 5 COMMENT '优先级 1-10，数字越大优先级越高',
ADD INDEX idx_batch_id (batch_id),
ADD INDEX idx_priority (priority);
```

## 2. 创建任务队列表

```sql
-- 任务队列表
CREATE TABLE oc_task_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id VARCHAR(64) NOT NULL COMMENT '关联任务ID',
    batch_id VARCHAR(64) DEFAULT NULL COMMENT '批次ID',
    device_uuid VARCHAR(64) NOT NULL COMMENT '目标设备UUID',
    priority INT DEFAULT 5 COMMENT '优先级 1-10',
    status ENUM('queued', 'dispatched', 'failed') DEFAULT 'queued' COMMENT '队列状态',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    
    INDEX idx_task_id (task_id),
    INDEX idx_batch_id (batch_id),
    INDEX idx_device_uuid (device_uuid),
    INDEX idx_status (status),
    INDEX idx_priority_created (priority DESC, created_at ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务队列表';
```

## 3. 创建任务模板表

```sql
-- 任务模板表
CREATE TABLE oc_task_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL COMMENT '模板名称',
    description TEXT COMMENT '模板描述',
    script_name VARCHAR(255) NOT NULL COMMENT '脚本名',
    default_params JSON COMMENT '默认参数',
    priority INT DEFAULT 5 COMMENT '默认优先级',
    created_by VARCHAR(64) DEFAULT NULL COMMENT '创建者',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_name (name),
    INDEX idx_script_name (script_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务模板表';
```

## 4. 执行迁移

```bash
# 连接到数据库并执行上述SQL
mysql -u root -p openclaw_admin < migration_batch_tasks.sql
```