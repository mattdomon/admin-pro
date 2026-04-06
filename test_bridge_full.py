#!/usr/bin/env python3
import asyncio
from bridge import BridgeService

async def test_bridge_connection():
    print("🚀 启动 Bridge WebSocket 测试...")
    
    bridge = BridgeService()
    bridge.running = True
    
    # 创建连接任务
    connect_task = asyncio.create_task(bridge._connect_websocket())
    
    try:
        # 等待 5 秒看连接状态
        await asyncio.wait_for(asyncio.sleep(5), timeout=5)
    except:
        pass
    finally:
        bridge.running = False
        connect_task.cancel()
        print(f"📊 连接状态: {bridge.ws_connected}")
        print(f"🆔 节点 ID: {bridge.node_id}")

if __name__ == "__main__":
    asyncio.run(test_bridge_connection())