# Bridge Service 使用说明

## 启动服务

```bash
# 直接启动
python bridge.py

# 后台运行
nohup python bridge.py > bridge.log 2>&1 &
```

## API 接口

### 1. 提交任务
```bash
# OpenClaw 任务
curl -X POST http://localhost:9999/api/tasks \
  -H "Content-Type: application/json" \
  -d '{
    "type": "openclaw",
    "payload": {
      "action": "send_message",
      "session_key": "agent:hc-coding:telegram:group:-5299845288",
      "message": "Hello from Bridge!"
    }
  }'

# AI 任务
curl -X POST http://localhost:9999/api/tasks \
  -H "Content-Type: application/json" \
  -d '{
    "type": "ai",
    "payload": {
      "model": "claude-3-5-haiku-latest",
      "prompt": "分析这段代码的性能问题",
      "context": "def slow_function()..."
    }
  }'

# 脚本任务
curl -X POST http://localhost:9999/api/tasks \
  -H "Content-Type: application/json" \
  -d '{
    "type": "script",
    "payload": {
      "script_path": "./scripts/data_sync.py",
      "args": ["--env", "production"]
    }
  }'

# Webhook 任务
curl -X POST http://localhost:9999/api/tasks \
  -H "Content-Type: application/json" \
  -d '{
    "type": "webhook",
    "payload": {
      "url": "https://api.example.com/webhook",
      "method": "POST",
      "data": {"key": "value"}
    }
  }'
```

### 2. 查询任务状态
```bash
curl http://localhost:9999/api/tasks/{task_id}
```

### 3. 获取统计信息
```bash
curl http://localhost:9999/api/stats
```

### 4. 健康检查
```bash
curl http://localhost:9999/api/health
```

## 常用场景

### 1. 定时任务调度
```python
import asyncio
import aiohttp

async def schedule_daily_report():
    async with aiohttp.ClientSession() as session:
        async with session.post('http://localhost:9999/api/tasks', json={
            "type": "script",
            "payload": {
                "script_path": "./scripts/generate_report.py",
                "args": ["--date", "today"]
            }
        }) as resp:
            result = await resp.json()
            print(f"任务ID: {result['task_id']}")
```

### 2. OpenClaw 消息发送
```python
async def send_notification(session_key: str, message: str):
    async with aiohttp.ClientSession() as session:
        async with session.post('http://localhost:9999/api/tasks', json={
            "type": "openclaw",
            "payload": {
                "action": "send_message",
                "session_key": session_key,
                "message": message
            }
        }) as resp:
            return await resp.json()
```

### 3. 批量处理
```python
async def batch_process_files(file_list):
    tasks = []
    
    for file_path in file_list:
        async with aiohttp.ClientSession() as session:
            async with session.post('http://localhost:9999/api/tasks', json={
                "type": "script",
                "payload": {
                    "script_path": "./scripts/process_file.py",
                    "args": [file_path]
                }
            }) as resp:
                result = await resp.json()
                tasks.append(result['task_id'])
    
    return tasks
```

## 监控和运维

### 查看服务状态
```bash
# 检查进程
ps aux | grep bridge.py

# 查看日志
tail -f logs/bridge.log

# 检查API状态
curl http://localhost:9999/api/health
```

### 配置文件
编辑 `config/bridge.json` 可以调整：
- 各类型任务的并发限制
- 超时时间设置
- 重试策略
- API端口配置

### 故障排除
1. **端口冲突**: 修改 `config/bridge.json` 中的 port
2. **任务卡住**: 检查 timeout 设置和资源限制
3. **内存不足**: 调整 max_concurrent 参数
4. **磁盘空间**: Bridge 会自动清理24小时前的任务记录