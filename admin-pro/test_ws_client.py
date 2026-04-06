#!/usr/bin/env python3
"""
测试 GatewayWorker 连接认证流程的客户端
"""

import asyncio
import json
import logging
import uuid
import websockets

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

NODE_UUID = f"TEST_NODE_{uuid.uuid4().hex[:8]}"
NODE_TOKEN = "test_token_123"
WS_URL = "ws://127.0.0.1:8282"

async def test_client():
    logger.info(f"🔗 测试连接: {WS_URL} (UUID: {NODE_UUID})")
    
    try:
        async with websockets.connect(WS_URL) as ws:
            logger.info("✅ WebSocket 连接成功")
            
            # 发送认证消息
            auth_msg = {
                "action": "auth",
                "uuid": NODE_UUID,
                "token": NODE_TOKEN,
                "device_name": "测试节点"
            }
            await ws.send(json.dumps(auth_msg))
            logger.info(f"📤 发送认证: {auth_msg}")
            
            # 等待认证响应
            response = await asyncio.wait_for(ws.recv(), timeout=5)
            logger.info(f"📥 收到响应: {response}")
            
            # 发送心跳测试
            ping_msg = {
                "action": "ping",
                "uuid": NODE_UUID,
                "cpu": "15.2%",
                "mem": "512MB"
            }
            await ws.send(json.dumps(ping_msg))
            logger.info(f"💓 发送心跳: {ping_msg}")
            
            # 等待更多消息
            try:
                while True:
                    msg = await asyncio.wait_for(ws.recv(), timeout=2)
                    logger.info(f"📥 收到消息: {msg}")
            except asyncio.TimeoutError:
                logger.info("⏰ 测试完成 (超时)")
                
    except Exception as e:
        logger.error(f"❌ 测试失败: {e}", exc_info=True)

if __name__ == "__main__":
    asyncio.run(test_client())