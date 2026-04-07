#!/usr/bin/env python3
"""
测试脚本：模拟 AI 流式聊天响应
用于验证 WebSocket chat_stream 功能是否正常
"""

import asyncio
import json
import websocket
import sys
import time

async def simulate_chat_stream():
    """模拟流式聊天输出"""
    
    # 模拟任务ID
    task_id = f"test_chat_{int(time.time())}"
    
    print(f"🤖 开始模拟 AI 流式聊天，任务ID: {task_id}")
    
    # 模拟的AI回答内容
    ai_response = """你好！我是AI助手。

这是一个流式输出的演示：
1. 首先我会逐字输出
2. 然后展示代码块
3. 最后给出总结

```python
def hello_world():
    print("Hello from AI!")
    return "stream_test_success"
```

现在让我们一步步展示这个过程..."""
    
    # 连接到 WebSocket 服务器（模拟节点客户端）
    try:
        ws_url = "ws://localhost:8282"
        print(f"📡 连接到 WebSocket: {ws_url}")
        
        # 建立连接并鉴权（模拟节点）
        ws = websocket.create_connection(ws_url)
        
        # 发送鉴权消息（使用测试节点key）
        auth_msg = {
            "type": "auth", 
            "node_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"  # 32位测试key
        }
        ws.send(json.dumps(auth_msg))
        print("🔐 发送鉴权消息")
        
        # 接收鉴权响应
        auth_response = ws.recv()
        print(f"✅ 鉴权响应: {auth_response}")
        
        # 逐字符发送流式数据
        print(f"💬 开始发送流式数据...")
        
        chunk_size = 3  # 每次发送3个字符
        for i in range(0, len(ai_response), chunk_size):
            chunk = ai_response[i:i+chunk_size]
            
            stream_msg = {
                "type": "chat_stream",
                "task_id": task_id,
                "content": chunk,
                "status": "processing"
            }
            
            ws.send(json.dumps(stream_msg))
            print(f"📤 发送块 [{i//chunk_size + 1}]: '{chunk}'")
            
            # 模拟AI处理延迟
            await asyncio.sleep(0.2)
        
        # 发送完成消息
        complete_msg = {
            "type": "chat_stream",
            "task_id": task_id,
            "content": "",
            "status": "completed"
        }
        
        ws.send(json.dumps(complete_msg))
        print("✅ 发送完成信号")
        
        # 保持连接5秒，观察响应
        await asyncio.sleep(5)
        
        ws.close()
        print("🔌 WebSocket 连接已关闭")
        
    except Exception as e:
        print(f"❌ 模拟失败: {e}")
        return 1
    
    print("🎉 AI 流式聊天测试完成")
    return 0

if __name__ == "__main__":
    exit_code = asyncio.run(simulate_chat_stream())
    sys.exit(exit_code)