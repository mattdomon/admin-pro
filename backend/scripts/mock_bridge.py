#!/usr/bin/env python3
"""
Mock Bridge.py - 用于测试WebSocket连接和任务处理
"""
import asyncio
import websockets
import json
import uuid
import time
from datetime import datetime

class MockBridge:
    def __init__(self):
        self.node_key = f"test_node_{uuid.uuid4().hex[:16]}"
        self.node_name = "测试节点-模拟器"
        self.gateway_url = "ws://localhost:8282"
        self.ws = None
        self.running = True
        
    async def connect_gateway(self):
        """连接到网关"""
        try:
            print(f"🔗 连接网关: {self.gateway_url}")
            self.ws = await websockets.connect(self.gateway_url)
            
            # 发送节点注册信息
            register_msg = {
                "type": "node_register",
                "node_key": self.node_key,
                "node_name": self.node_name,
                "timestamp": time.time()
            }
            await self.ws.send(json.dumps(register_msg))
            print(f"✅ 节点已注册: {self.node_name} ({self.node_key[:8]}...)")
            
            # 启动消息处理循环
            await self.message_loop()
            
        except websockets.exceptions.ConnectionClosedError:
            print("❌ 连接被关闭")
        except Exception as e:
            print(f"❌ 连接失败: {e}")
    
    async def message_loop(self):
        """消息处理循环"""
        async for message in self.ws:
            try:
                data = json.loads(message)
                await self.handle_message(data)
            except json.JSONDecodeError:
                print(f"⚠️ 无效消息: {message}")
            except Exception as e:
                print(f"❌ 处理消息失败: {e}")
    
    async def handle_message(self, data):
        """处理收到的消息"""
        msg_type = data.get("type", "unknown")
        
        if msg_type == "task":
            await self.handle_task(data)
        elif msg_type == "ping":
            await self.handle_ping()
        elif msg_type == "register_response":
            print(f"📝 注册响应: {data.get('message', 'OK')}")
        else:
            print(f"📨 收到消息: {msg_type}")
    
    async def handle_task(self, data):
        """处理任务"""
        task_id = data.get("task_id", "unknown")
        payload = data.get("payload", {})
        
        print(f"📋 收到任务: {task_id}")
        print(f"载荷: {json.dumps(payload, ensure_ascii=False, indent=2)}")
        
        # 发送任务开始通知
        await self.send_task_update(task_id, "started", "任务开始执行")
        
        # 模拟任务执行
        await self.execute_task(task_id, payload)
    
    async def execute_task(self, task_id, payload):
        """执行任务"""
        task_type = payload.get("type", "unknown")
        
        try:
            if task_type == "script":
                script_path = payload.get("script_path", "")
                print(f"🐍 执行脚本: {script_path}")
                
                # 模拟脚本执行
                for i in range(5):
                    await asyncio.sleep(2)
                    progress = (i + 1) * 20
                    await self.send_task_update(
                        task_id, 
                        "running", 
                        f"脚本执行中... {progress}%"
                    )
                
                # 成功结果
                result = {
                    "script": script_path,
                    "exit_code": 0,
                    "execution_time": "10.2s",
                    "output": "脚本执行成功！\n测试输出内容。"
                }
                await self.send_task_update(task_id, "success", "脚本执行成功", result)
                
            elif task_type == "openclaw":
                agent_id = payload.get("agent_id", "")
                message = payload.get("message", "")
                print(f"🤖 OpenClaw调用: {agent_id} - {message}")
                
                # 模拟OpenClaw调用
                await asyncio.sleep(3)
                result = {
                    "agent_id": agent_id,
                    "response": f"收到消息: {message}",
                    "response_time": "3.1s"
                }
                await self.send_task_update(task_id, "success", "OpenClaw调用成功", result)
            
            else:
                await self.send_task_update(task_id, "failed", f"不支持的任务类型: {task_type}")
                
        except Exception as e:
            await self.send_task_update(task_id, "failed", f"任务执行失败: {str(e)}")
    
    async def send_task_update(self, task_id, status, message, result=None):
        """发送任务状态更新"""
        update = {
            "type": "task_update",
            "task_id": task_id,
            "status": status,
            "message": message,
            "timestamp": time.time()
        }
        
        if result:
            update["result"] = result
        
        await self.ws.send(json.dumps(update))
        print(f"📤 任务更新: {task_id} - {status}")
    
    async def handle_ping(self):
        """处理心跳"""
        pong = {
            "type": "pong", 
            "node_key": self.node_key,
            "timestamp": time.time()
        }
        await self.ws.send(json.dumps(pong))
        print("💓 心跳响应")
    
    async def heartbeat_loop(self):
        """心跳循环"""
        while self.running:
            await asyncio.sleep(30)  # 30秒心跳
            if self.ws:
                try:
                    heartbeat = {
                        "type": "heartbeat",
                        "node_key": self.node_key,
                        "timestamp": time.time()
                    }
                    await self.ws.send(json.dumps(heartbeat))
                    print("💓 发送心跳")
                except:
                    print("❌ 心跳发送失败")
                    break
    
    async def run(self):
        """启动模拟器"""
        print(f"🎭 Mock Bridge 启动")
        print(f"节点: {self.node_name}")
        print(f"Key: {self.node_key}")
        print("按 Ctrl+C 停止")
        
        # 并发运行连接和心跳
        await asyncio.gather(
            self.connect_gateway(),
            self.heartbeat_loop()
        )

async def main():
    bridge = MockBridge()
    try:
        await bridge.run()
    except KeyboardInterrupt:
        print("\n🛑 用户中断，停止运行")
        bridge.running = False

if __name__ == "__main__":
    asyncio.run(main())