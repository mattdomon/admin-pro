#!/usr/bin/env python3
"""
测试 WebSocket 连接
"""
import asyncio
import websockets
import json

async def test_connection():
    try:
        print("🔗 正在连接到 WebSocket 服务器 ws://localhost:8282...")
        
        async with websockets.connect("ws://localhost:8282") as websocket:
            print("✅ 连接成功!")
            
            # 发送测试消息
            test_msg = {"action": "test", "data": "Hello from bridge test"}
            await websocket.send(json.dumps(test_msg))
            print(f"📤 已发送: {test_msg}")
            
            # 接收响应
            response = await websocket.recv()
            print(f"📥 收到响应: {response}")
            
            print("🎉 WebSocket 连接测试成功!")
            
    except Exception as e:
        print(f"❌ 连接失败: {e}")

if __name__ == "__main__":
    asyncio.run(test_connection())