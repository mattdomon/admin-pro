#!/bin/bash

echo "🚀 Quick Start Admin Pro"
echo "========================"

# 停止现有服务
pkill -f "php.*think.*run" 2>/dev/null || true
pkill -f "vite" 2>/dev/null || true
sleep 2

# 启动后端 (后台)
echo "启动后端..."
cd backend
nohup php think run --port=8000 > ../backend.log 2>&1 &
BACKEND_PID=$!
echo "后端 PID: $BACKEND_PID"

# 等待后端启动
sleep 3
if curl -s http://localhost:8000/api/auth/info > /dev/null; then
    echo "✅ 后端启动成功"
else
    echo "❌ 后端启动失败"
    exit 1
fi

# 启动前端 (后台)
echo "启动前端..."
cd ../frontend
nohup npm run dev > ../frontend.log 2>&1 &
FRONTEND_PID=$!
echo "前端 PID: $FRONTEND_PID"

# 保存PID
echo "$BACKEND_PID" > ../backend.pid
echo "$FRONTEND_PID" > ../frontend.pid

# 等待前端启动
sleep 5
if curl -s http://localhost:5173/ > /dev/null; then
    echo "✅ 前端启动成功"
else
    echo "❌ 前端启动失败"
    exit 1
fi

echo "✅ 服务启动完成！"
echo "前端: http://localhost:5173/"
echo "后端: http://localhost:8000/"
echo ""
echo "查看日志:"
echo "  tail -f backend.log"
echo "  tail -f frontend.log"
echo ""
echo "停止服务:"
echo "  kill \$(cat backend.pid frontend.pid)"