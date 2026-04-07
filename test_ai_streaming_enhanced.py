#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AI流式输出测试脚本 - 增强版
用于验证 bridge.py → PHP Gateway → Vue 前端 的完整数据链路
"""

import asyncio
import aiohttp
import json
import time

async def test_ai_streaming():
    """测试AI流式输出"""
    
    print("🚀 开始测试AI流式输出...")
    
    # 构建AI任务请求 - 使用本地模拟服务
    task_payload = {
        "type": "ai",
        "payload": {
            "model_path": "gpt-3.5-turbo",  # 简化模型名
            "messages": [
                {"role": "user", "content": "请说一个简短的笑话"}
            ],
            "base_url": "http://localhost:8088",  # 本地模拟服务
            "api_key": "test-key-123",
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
                        print(f"📋 任务状态: {task_info.get('status', 'unknown')}")
                        if task_info.get('result'):
                            content = task_info['result'].get('content', '')
                            print(f"📝 AI回答: {content[:100]}{'...' if len(content) > 100 else ''}")
                        if task_info.get('error'):
                            print(f"❌ 错误信息: {task_info['error']}")
                    else:
                        print(f"❌ 获取状态失败: {result}")
                else:
                    text = await response.text()
                    print(f"❌ HTTP错误 {response.status}: {text}")
                    
    except Exception as e:
        print(f"❌ 检查状态失败: {e}")

async def check_services():
    """检查服务状态"""
    print("🔍 检查服务状态...")
    
    services = [
        ("模拟AI API", "http://localhost:8088/health"),
        ("Bridge API", "http://localhost:9999/api/health"),
    ]
    
    async with aiohttp.ClientSession() as session:
        for name, url in services:
            try:
                async with session.get(url, timeout=aiohttp.ClientTimeout(total=3)) as resp:
                    if resp.status == 200:
                        print(f"✅ {name}: 正常运行")
                    else:
                        print(f"⚠️ {name}: 返回状态 {resp.status}")
            except Exception as e:
                print(f"❌ {name}: 无法连接 - {e}")

async def main():
    """主函数"""
    
    print("="*60)
    print("🧪 AI流式输出数据链路测试 - 增强版")
    print("="*60)
    
    print("\\n📋 测试步骤:")
    print("1. 检查所需服务状态")
    print("2. 向 bridge.py API 发送 AI 任务")
    print("3. 检查任务处理进度")
    print("4. 观察终端输出中的调试日志")
    print()
    
    # 步骤1: 检查服务状态
    await check_services()
    
    print("\\n请确保以下服务正在运行:")
    print("- 模拟AI API: python3 mock_ai_api.py (端口 8088)")
    print("- bridge.py: python3 bridge.py (端口 9999)")
    print("- PHP WebSocket Gateway: php backend/start_gateway.php start (端口 8282)")
    print()
    
    input("按 Enter 继续测试...")
    
    # 步骤2: 发送AI任务
    task_id = await test_ai_streaming()
    
    if task_id:
        print("\\n📊 现在观察bridge.py终端输出，你应该看到:")
        print("1. 🚀 发送AI请求: http://localhost:8088/v1/chat/completions")
        print("2. 📊 HTTP状态: 200")
        print("3. 🗨️ Starting stream processing for task...")
        print("4. 📏 Line X: Y bytes")
        print("5. Raw line: 'data: {\"choices\":[{\"delta\":{\"content\":\"X\"}}]}'")
        print("6. Parsed chunk: 'X' (len=1)")
        print("7. 📡 Actually sending to WebSocket: {...}")
        print()
        
        # 步骤3: 等待并检查状态
        for i in range(3):
            await asyncio.sleep(5)
            print(f"\\n⏰ 第{i+1}次检查任务状态...")
            await check_task_status(task_id)
    
    print("\\n📊 测试完成！")
    print("\\n🔍 如果没有看到预期的调试输出，可能的原因:")
    print("1. 模拟API服务未启动 (python3 mock_ai_api.py)")
    print("2. bridge.py 未启动或端口被占用")
    print("3. WebSocket 连接失败")
    print("4. 权限或防火墙问题")
    
    print("\\n💡 下一步:")
    print("- 如果Python端日志正常，检查PHP Gateway端日志")
    print("- 如果Gateway端日志正常，检查前端WebSocket连接")

if __name__ == "__main__":
    asyncio.run(main())