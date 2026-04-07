# 🐛 AI 流式输出数据链路调试指南

## 我已注入的调试日志

### 📍 Python bridge.py 端（3个调试点）

1. **原始SSE数据解析**
   ```python
   print(f"Raw line: '{line_str}'")
   print(f"Data string after prefix removal: '{data_str}'") 
   print(f"Raw chunk_data: {chunk_data}")
   ```

2. **流式内容提取**
   ```python
   if delta_text:
       print(f"Parsed chunk: '{delta_text}' (len={len(delta_text)})")
   ```

3. **WebSocket发送状态**
   ```python
   print(f"Sending WebSocket message: {stream_message}")
   print(f"WebSocket status: connected={self.ws_connected}")
   print(f"📡 Actually sending to WebSocket: {json_message}")
   print(f"✅ WebSocket send successful")  # 或 ❌ 失败信息
   ```

### 📍 PHP Gateway 端（Events.php）

4. **消息接收确认**
   ```php
   echo "Gateway Received: " . $type . "\n";
   echo "Full message: " . $message . "\n";
   ```

5. **chat_stream 处理状态**
   ```php
   echo "💬 处理 chat_stream: task_id={$taskId}, status={$status}, content_len=" . strlen($content) . "\n";
   echo "📡 转发 chat_stream 给用户 {$userId} 的 Web 前端\n";
   ```

### 📍 Vue 前端端

6. **已在 taskNotifier.js 和脚本管理器中添加监听**
   - 监听 `chat_stream` 事件
   - 处理增量内容和完成状态

## 🔍 测试步骤

### 第一步：启动所有服务

```bash
# 启动 PHP WebSocket Gateway
cd /Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro
php backend/start_gateway.php start

# 启动 bridge.py（另一个终端）
python3 bridge.py

# 启动前端（第三个终端）
cd frontend && npm run dev
```

### 第二步：运行测试脚本

```bash
cd /Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro
python3 test_ai_streaming.py
```

### 第三步：观察日志输出

**如果 Python 端正常，你应该看到：**
```
🗨️ Starting stream processing for task ai_1234567890
Raw line: 'data: {"choices":[{"delta":{"content":"你"}}]}'
Data string after prefix removal: '{"choices":[{"delta":{"content":"你"}}]}'
Raw chunk_data: {'choices': [{'delta': {'content': '你'}}]}
Parsed chunk: '你' (len=1)
Sending WebSocket message: {'type': 'chat_stream', 'task_id': 'ai_1234567890', 'content': '你', 'status': 'processing'}
WebSocket status: connected=True, client=True
📡 Actually sending to WebSocket: {"type":"chat_stream","task_id":"ai_1234567890","content":"你","status":"processing"}
✅ WebSocket send successful
```

**如果 PHP Gateway 端正常，你应该看到：**
```
Gateway Received: chat_stream
Full message: {"type":"chat_stream","task_id":"ai_1234567890","content":"你","status":"processing"}
💬 处理 chat_stream: task_id=ai_1234567890, status=processing, content_len=1
📡 转发 chat_stream 给用户 1 的 Web 前端
```

**如果前端正常，你应该在浏览器 F12 WebSocket 面板看到：**
```json
{"type":"chat_stream","task_id":"ai_1234567890","content":"你","status":"processing"}
```

## ❌ 故障排除

### 如果 Python 端没有输出：
- 检查 AI API 配置是否正确
- 检查 stream=True 是否生效
- 检查网络连接

### 如果 WebSocket 发送失败：
- 检查 bridge.py 是否连接到了正确的 Gateway (8282端口)
- 检查 node_key 鉴权是否成功

### 如果 PHP Gateway 没有接收到：
- 检查 Gateway 是否在 8282 端口运行
- 检查防火墙/端口占用

### 如果前端没有收到：
- 检查 Vue 应用是否连接了 WebSocket
- 检查 taskNotifier.js 的事件监听
- 检查 user_id 路由是否正确

## 🎯 定位断点

通过日志输出，你可以精确定位数据在哪一步中断了：

1. **Python 解析失败** → AI API 问题
2. **Python 发送失败** → WebSocket 连接问题  
3. **PHP 未接收到** → Gateway 服务问题
4. **前端未显示** → 前端监听或路由问题

现在运行测试脚本，告诉我你看到了什么日志输出！