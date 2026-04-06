#!/bin/bash

# admin-pro 服务状态检查脚本
# 使用方法: bash status.sh

echo "🔍 admin-pro 服务状态检查"
echo "=========================="

# 检查 Docker 服务
echo ""
echo "📦 Docker 服务:"
if docker ps | grep -q "mysql"; then
    MYSQL_STATUS="✅ MySQL 运行中"
else
    MYSQL_STATUS="❌ MySQL 未运行"
fi

if docker ps | grep -q "redis"; then
    REDIS_STATUS="✅ Redis 运行中"
else
    REDIS_STATUS="❌ Redis 未运行"
fi

echo "   $MYSQL_STATUS"
echo "   $REDIS_STATUS"

# 检查各个端口服务
echo ""
echo "🌐 应用服务:"

# PHP 后端 (8000)
if lsof -i :8000 >/dev/null 2>&1; then
    PHP_PID=$(lsof -t -i:8000)
    echo "   ✅ PHP 后端: http://localhost:8000 (PID: $PHP_PID)"
    
    # 测试 API 连通性
    if curl -s -m 5 http://localhost:8000/api/test >/dev/null 2>&1; then
        echo "      🔗 API 连接正常"
    else
        echo "      ❌ API 连接失败"
    fi
else
    echo "   ❌ PHP 后端: 未运行"
fi

# GatewayWorker (8282)
if lsof -i :8282 >/dev/null 2>&1; then
    GATEWAY_PID=$(lsof -t -i:8282 | head -1)
    echo "   ✅ GatewayWorker: ws://localhost:8282 (PID: $GATEWAY_PID)"
else
    echo "   ❌ GatewayWorker: 未运行"
fi

# 前端 Vite 服务
FRONTEND_FOUND=false
for port in 5173 5174 5175; do
    if lsof -i :$port >/dev/null 2>&1; then
        FRONTEND_PID=$(lsof -t -i:$port)
        echo "   ✅ 前端 Vite: http://localhost:$port (PID: $FRONTEND_PID)"
        FRONTEND_FOUND=true
        
        # 测试前端连通性
        if curl -s -m 5 http://localhost:$port >/dev/null 2>&1; then
            echo "      🔗 前端页面正常"
        else
            echo "      ⚠️  前端响应异常"
        fi
        break
    fi
done

if [ "$FRONTEND_FOUND" = false ]; then
    echo "   ❌ 前端 Vite: 未运行"
fi

# 检查日志文件
echo ""
echo "📝 日志文件:"
for log in "/tmp/admin_php.log" "/tmp/admin_gateway.log" "/tmp/admin_frontend.log"; do
    if [ -f "$log" ]; then
        SIZE=$(ls -lh "$log" | awk '{print $5}')
        MTIME=$(ls -l "$log" | awk '{print $6, $7, $8}')
        echo "   ✅ $(basename "$log"): $SIZE (修改: $MTIME)"
    else
        echo "   ❌ $(basename "$log"): 不存在"
    fi
done

# 检查 PIDs 文件
echo ""
echo "🆔 进程 ID:"
if [ -f "/tmp/admin_pids.txt" ]; then
    PIDS=$(cat /tmp/admin_pids.txt)
    echo "   📝 保存的 PIDs: $PIDS"
    
    # 验证 PIDs 是否还在运行
    RUNNING_COUNT=0
    for pid in $PIDS; do
        if kill -0 "$pid" 2>/dev/null; then
            RUNNING_COUNT=$((RUNNING_COUNT + 1))
        fi
    done
    echo "   🏃 运行中的进程: $RUNNING_COUNT/$(echo $PIDS | wc -w | tr -d ' ')"
else
    echo "   ❌ PIDs 文件不存在"
fi

echo ""
echo "=========================="

# 总结状态
MYSQL_OK=$(docker ps | grep -q "mysql" && echo "true" || echo "false")
REDIS_OK=$(docker ps | grep -q "redis" && echo "true" || echo "false")
PHP_OK=$(lsof -i :8000 >/dev/null 2>&1 && echo "true" || echo "false")
GATEWAY_OK=$(lsof -i :8282 >/dev/null 2>&1 && echo "true" || echo "false")

if [ "$MYSQL_OK" = true ] && [ "$REDIS_OK" = true ] && [ "$PHP_OK" = true ] && [ "$GATEWAY_OK" = true ] && [ "$FRONTEND_FOUND" = true ]; then
    echo "🎉 系统状态: 全部正常"
elif [ "$PHP_OK" = true ] && [ "$FRONTEND_FOUND" = true ]; then
    echo "⚠️  系统状态: 基本功能正常，但有服务异常"
else
    echo "❌ 系统状态: 关键服务异常"
fi