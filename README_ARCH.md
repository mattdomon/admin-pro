# README_ARCH.md - Admin Pro 架构文档

> **重要**: 每次重大修改必须更新此文件！新对话时阅读此文件可快速恢复项目状态。

## 🏗️ 四层架构总览

```
Vue 2.0 Frontend (5173)
     ↕ HTTP API / WebSocket  
ThinkPHP 8.0 Backend (8000)
     ↕ JSON 文件 / WebSocket通信
Python bridge.py (8282)
     ↕ 脚本执行 / 文件存储
Python Scripts (9999)
```

### 端口分配

| 端口 | 服务 | 技术栈 | 职责 |
|------|------|--------|------|
| **5173** | 前端界面 | Vue 2.0 + ElementUI + Vite | 用户交互、实时状态展示 |
| **8000** | 后端API | ThinkPHP 8.0 + MySQL + RESTful | 业务逻辑、数据管理 |
| **8282** | 任务调度器 | Python + asyncio + WebSocket | 异步任务调度、脚本执行 |
| **9999** | 脚本端口 | Python Scripts | 具体业务脚本运行环境 |

## 📡 通信协议

### 1. HTTP API (前端 ↔ 后端)

**基础路径**: `http://localhost:8000/api/`

**核心接口**:
```php
// 脚本管理
POST /api/openclaw/bridge/execute    // 执行脚本
GET  /api/openclaw/bridge/tasks      // 查询任务列表
GET  /api/openclaw/bridge/scripts    // 获取脚本列表

// 系统监控  
GET  /api/openclaw/status           // 系统状态
GET  /api/openclaw/config           // 配置信息
```

### 2. WebSocket (后端 ↔ bridge.py)

**连接地址**: `ws://localhost:8282/bridge`

**消息格式**:
```json
// 执行脚本请求
{
  "action": "execute_script",
  "task_id": "task_20241207_090001_abc123",  
  "script_path": "demo_script.py",
  "params": {"key": "value"}
}

// 执行结果响应
{
  "action": "script_result", 
  "task_id": "task_20241207_090001_abc123",
  "success": true,
  "output": "脚本执行成功",
  "error": null,
  "execution_time": 2.5
}

// 错误推送
{
  "action": "script_error",
  "task_id": "task_20241207_090001_abc123", 
  "error": "Python script failed: ModuleNotFoundError"
}

// 心跳机制
{
  "action": "ping"
}
// 响应: {"action": "pong"}
```

## 📋 Task 协议格式

### Task ID 生成规则
```
task_{YYYYMMDD}_{HHMMSS}_{random6}
示例: task_20241207_090001_abc123
```

### 任务状态生命周期
```
pending → running → completed/failed
```

### Task JSON 文件结构

**存储位置**: `data/tasks/{task_id}.json`

```json
{
  "task_id": "task_20241207_090001_abc123",
  "script_path": "demo_script.py", 
  "status": "completed",
  "created_at": "2024-12-07 09:00:01",
  "started_at": "2024-12-07 09:00:02", 
  "completed_at": "2024-12-07 09:00:05",
  "execution_time": 2.5,
  "params": {
    "key": "value"
  },
  "result": {
    "success": true,
    "output": "脚本执行成功\nStep 1: 初始化完成\n...",
    "error": null,
    "return_code": 0
  },
  "metadata": {
    "executor": "bridge.py",
    "pid": 12345,
    "memory_usage": "15.2MB"
  }
}
```

### 任务状态枚举
```python
TASK_STATUS = {
    'pending': '等待执行',
    'running': '正在执行', 
    'completed': '执行成功',
    'failed': '执行失败',
    'timeout': '执行超时',
    'cancelled': '已取消'
}
```

## 💾 持久化逻辑

### 1. 文件存储结构
```
data/
├── tasks/                    # 任务结果持久化
│   ├── task_20241207_090001_abc123.json
│   ├── task_20241207_090002_def456.json 
│   └── ...
├── logs/                     # 日志文件
│   ├── bridge_20241207.log
│   └── error_20241207.log
└── cache/                    # 缓存数据
    └── script_metadata.json
```

### 2. 任务持久化流程

```python
# bridge.py 中的持久化逻辑
async def save_task_result(task_id, result):
    """保存任务结果到文件"""
    task_file = f"data/tasks/{task_id}.json"
    
    # 原子写入防止文件损坏
    temp_file = f"{task_file}.tmp"
    with open(temp_file, 'w', encoding='utf-8') as f:
        json.dump(result, f, indent=2, ensure_ascii=False)
    
    # 原子替换
    os.rename(temp_file, task_file)
    
    # 触发 WebSocket 通知前端
    await notify_task_update(task_id, result)
```

### 3. 数据库 vs 文件存储

| 数据类型 | 存储方式 | 理由 |
|----------|----------|------|
| 任务结果 | JSON文件 | 简单、可视化、便于调试 |  
| 用户数据 | MySQL | 结构化、事务支持 |
| 日志记录 | 文件 | 性能、大数据量 |
| 配置信息 | JSON文件 | 便于编辑、版本控制 |

## 🔧 核心组件

### 1. BridgeCtrl.php (后端控制器)
- **路径**: `backend/app/controller/BridgeCtrl.php`  
- **行数**: 385行
- **职责**: HTTP API处理、WebSocket通信、任务管理

**核心方法**:
```php
public function execute()      // 执行脚本
public function getTasks()     // 查询任务列表  
public function getScripts()   // 获取脚本列表
private function sendToWebSocket()  // WebSocket通信
```

### 2. bridge.py (任务调度器)
- **路径**: `bridge.py`
- **行数**: 776行  
- **职责**: 异步任务调度、脚本执行、WebSocket服务

**核心功能**:
```python
class TaskScheduler:
    async def execute_script()     # 脚本执行
    async def handle_websocket()   # WebSocket处理
    async def save_task_result()   # 结果持久化
    async def cleanup_old_tasks()  # 任务清理
```

### 3. Manager.vue (前端管理界面)
- **路径**: `frontend/src/views/openclaw/Manager.vue`
- **行数**: 558行
- **职责**: 脚本管理UI、实时状态展示、错误处理

**核心功能**:
- 脚本列表展示和执行  
- 任务状态实时更新 (30秒轮询)
- WebSocket错误弹窗提示
- 任务历史查询和分页

### 4. taskNotifier.js (WebSocket客户端)
- **路径**: `frontend/src/utils/taskNotifier.js`
- **行数**: 171行
- **职责**: WebSocket连接管理、错误推送、自动重连

**核心特性**:
- 指数退避重连机制
- 心跳保活 (30秒间隔)
- 错误消息推送到ElementUI通知

## 🚀 启动流程

### 标准启动命令
```bash
# 快速启动 (推荐)
bash quick-start.sh

# 或分步启动
bash start.sh        # 启动后端+前端
bash start_bridge.sh # 启动bridge.py
```

### 服务检查
```bash
bash status.sh       # 检查所有服务状态  
bash health.sh       # 健康检查和修复
```

### 停止服务
```bash
bash stop.sh         # 停止所有服务
```

## 📊 监控和日志

### 日志文件位置
```
logs/bridge_YYYYMMDD.log     # bridge.py 日志
backend.log                  # ThinkPHP 后端日志  
frontend.log                 # Vite 前端日志
workerman.log               # WebSocket 服务日志
```

### 关键监控指标
- **任务执行成功率**: completed / (completed + failed)
- **平均执行时间**: sum(execution_time) / task_count  
- **WebSocket连接状态**: 连接数、重连次数
- **错误率**: failed_tasks / total_tasks

## 🔄 版本历史

### v1.0 (2024-12-07)
- ✅ 完整四层架构实现
- ✅ Task协议格式定义  
- ✅ 持久化任务存储
- ✅ WebSocket实时通信
- ✅ 错误处理和弹窗
- ✅ 自动重连机制

### 下一版本计划
- [ ] 任务队列优先级  
- [ ] 分布式任务执行
- [ ] 更丰富的监控面板
- [ ] 任务执行历史统计

---

## 📝 维护说明

**重大修改后必须更新的部分**:
1. 端口变更 → 更新端口分配表
2. 新增接口 → 更新通信协议部分  
3. Task格式变化 → 更新Task协议格式
4. 新增组件 → 更新核心组件说明
5. 架构调整 → 更新四层架构总览

**新对话恢复状态流程**:
1. 执行 `cd /Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro`
2. 阅读此文件 `cat README_ARCH.md`  
3. 检查服务状态 `bash status.sh`
4. 根据需要启动服务或继续开发

**最后更新**: 2024-12-07 by HC Coding
**项目状态**: ✅ 生产就绪，四层架构全链路验证完成