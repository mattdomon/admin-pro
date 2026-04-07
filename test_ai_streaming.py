#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AI流式输出测试脚本
用于验证 bridge.py → PHP Gateway → Vue 前端 的完整数据链路
"""

import asyncio
import aiohttp
import json
import time

async def test_ai_streaming():
    """测试AI流式输出"""
    
    print("🚀 开始测试AI流式输出...")
    
    # 构建AI任务请求
    task_payload = {
        "type": "ai",
        "payload": {
            "model_path": "openai/gpt-3.5-turbo",
            "messages": [
                {"role": "user", "content": "请说一个简短的笑话"}
            ],
            "base_url": "https://api.openai.com",
            "api_key": "sk-test-key-this-will-fail",  # 故意错误，测试错误处理
            "api_type": "openai"
        }
    }
    
    try:
        # 发送任务到 bridge API
        async with aiohttp.ClientSession() as session:
            print("📤 向 bridge 发送AI任务...")
            async with session.post(
                'http://localhost:9999/api/tasks',
                json=task_payload,
                timeout=aiohttp.ClientTimeout(total=10)
            ) as response:
                
                if response.status == 200:
                    result = await response.json()
                    if result.get('success'):
                        task_id = result.get('task_id')
                        print(f"✅ 任务已提交: {task_id}")
                        return task_id
                    else:
                        print(f"❌ 任务提交失败: {result}")
                        return None
                else:
                    text = await response.text()
                    print(f"❌ HTTP错误 {response.status}: {text}")
                    return None
                    
    except Exception as e:
        print(f"❌ 发送任务失败: {e}")
        return None

async def check_task_status(task_id):
    """检查任务状态"""
    if not task_id:
        return
        
    print(f"🔍 检查任务状态: {task_id}")
    
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(
                f'http://localhost:9999/api/tasks/{task_id}',
                timeout=aiohttp.ClientTimeout(total=5)
            ) as response:
                
                if response.status == 200:
                    result = await response.json()
                    if result.get('success'):
                        task_info = result.get('task')
                        print(f"📋 任务状态: {task_info}")
                    else:
                        print(f"❌ 获取状态失败: {result}")
                else:
                    text = await response.text()
                    print(f"❌ HTTP错误 {response.status}: {text}")
                    
    except Exception as e:
        print(f"❌ 检查状态失败: {e}")

async def main():
    """主函数"""
    
    print("="*60)
    print("🧪 AI流式输出数据链路测试")
    print("="*60)
    
    print("\n📋 测试步骤:")
    print("1. 向 bridge.py API 发送 AI 任务")
    print("2. 检查任务是否被正确处理")
    print("3. 观察终端输出中的调试日志")
    print("\n请确保以下服务正在运行:")
    print("- bridge.py (端口 9999)")
    print("- PHP WebSocket Gateway (端口 8282)")
    print()
    
    # 测试步骤1: 发送AI任务
    task_id = await test_ai_streaming()
    
    if task_id:
        # 测试步骤2: 等待一段时间后检查状态
        print("\n⏰ 等待5秒后检查任务状态...")
        await asyncio.sleep(5)
        await check_task_status(task_id)
        
        print("\n⏰ 再等待5秒后检查最终状态...")
        await asyncio.sleep(5)
        await check_task_status(task_id)
    
    print("\n📊 测试完成！请检查以下内容:")
    print("1. bridge.py 终端中的调试日志:")
    print("   - 应该看到 'Raw chunk_data'、'Parsed chunk'、'Sending WebSocket message' 等")
    print("2. PHP Gateway 终端中的调试日志:")
    print("   - 应该看到 'Gateway Received: chat_stream'")
    print("3. 浏览器 F12 WebSocket 面板:")
    print("   - 应该看到 chat_stream 类型的消息")
    
    print("\n🔍 如果没有看到预期的日志，说明数据在某个环节中断了！")

if __name__ == "__main__":
    asyncio.run(main())