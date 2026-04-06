#!/usr/bin/env python3
"""
测试 Bridge WebSocket 连接
"""
import sys
import os

# 添加当前目录到路径
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from bridge import BridgeService, BridgeAPI
import asyncio

async def test_bridge():
    print("🚀 正在启动 Bridge 服务测试...")
    
    bridge = BridgeService()
    api = BridgeAPI(bridge)
    
    try:
        print("📡 启动 WebSocket 客户端...")
        # 只启动 WebSocket 连接进行测试
        await bridge._start_websocket_client()
    except KeyboardInterrupt:
        print("🛑 收到中断信号")
    except Exception as e:
        print(f"❌ 错误: {e}")
    finally:
        bridge.running = False
        print("✅ 测试完成")

if __name__ == "__main__":
    asyncio.run(test_bridge())