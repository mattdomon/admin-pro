#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
模拟AI API服务器
用于测试流式输出，避免真实API调用的复杂性
"""

from aiohttp import web, web_response
import asyncio
import json

async def mock_chat_completions(request):
    """模拟OpenAI chat/completions接口"""
    
    print("📥 收到AI请求")
    
    try:
        data = await request.json()
        print(f"📋 请求数据: {data}")
        
        # 检查是否为流式请求
        is_stream = data.get('stream', False)
        print(f"🌊 流式模式: {is_stream}")
        
        if not is_stream:
            # 非流式响应
            response_data = {
                "choices": [{
                    "message": {
                        "role": "assistant", 
                        "content": "这是一个非流式响应测试"
                    }
                }],
                "usage": {"total_tokens": 10}
            }
            return web.json_response(response_data)
        
        # 流式响应
        print("🚀 开始流式响应")
        
        response = web_response.StreamResponse()
        response.headers['Content-Type'] = 'text/plain; charset=utf-8'
        response.headers['Transfer-Encoding'] = 'chunked'
        
        await response.prepare(request)
        
        # 模拟流式数据
        test_content = "你好！这是一个测试笑话：为什么程序员喜欢黑暗？因为光明会产生Bug！😄"
        
        print(f"📝 准备发送内容: {test_content}")
        
        # 逐字符发送
        for i, char in enumerate(test_content):
            chunk_data = {
                "choices": [{
                    "delta": {"content": char}
                }]
            }
            
            # 格式化为 Server-Sent Events
            chunk_json = json.dumps(chunk_data, ensure_ascii=False)
            sse_line = f"data: {chunk_json}\\n\\n"
            
            print(f"📤 发送第{i+1}个字符: '{char}'")
            
            await response.write(sse_line.encode('utf-8'))
            await asyncio.sleep(0.1)  # 模拟网络延迟
        
        # 发送结束标记
        await response.write(b"data: [DONE]\\n\\n")
        print("✅ 流式发送完成")
        
        await response.write_eof()
        return response
        
    except Exception as e:
        print(f"❌ 处理请求失败: {e}")
        return web.json_response({"error": str(e)}, status=500)

async def create_mock_server():
    """创建模拟服务器"""
    app = web.Application()
    
    # 添加CORS支持
    app.router.add_route('OPTIONS', '/{path:.*}', lambda r: web.Response())
    
    # AI API端点
    app.router.add_post('/v1/chat/completions', mock_chat_completions)
    
    # 健康检查
    app.router.add_get('/health', lambda r: web.json_response({"status": "ok"}))
    
    return app

async def main():
    """主函数"""
    print("🎭 启动模拟AI API服务器...")
    
    app = await create_mock_server()
    
    runner = web.AppRunner(app)
    await runner.setup()
    
    site = web.TCPSite(runner, 'localhost', 8088)
    await site.start()
    
    print("✅ 模拟API服务器已启动: http://localhost:8088")
    print("📋 可用端点:")
    print("   POST /v1/chat/completions - 模拟聊天接口")
    print("   GET /health - 健康检查")
    print()
    print("🔄 现在你可以运行测试脚本了:")
    print("   python3 test_ai_streaming.py")
    print()
    print("⏹️  按 Ctrl+C 停止服务器")
    
    try:
        # 保持服务运行
        while True:
            await asyncio.sleep(1)
    except KeyboardInterrupt:
        print("\\n🛑 正在停止服务器...")
        await runner.cleanup()

if __name__ == "__main__":
    asyncio.run(main())