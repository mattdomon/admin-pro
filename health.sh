#!/bin/bash

# admin-pro 健康检查和自动修复脚本
# 使用方法: bash health.sh

echo "🩺 admin-pro 健康检查和修复"
echo "================================"

PROJECT_DIR="/Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro"
ISSUES_FOUND=false

# 检查 Docker 依赖
echo "1. 检查 Docker 依赖..."
if ! command -v docker >/dev/null 2>&1; then
    echo "   ❌ Docker 未安装"
    ISSUES_FOUND=true
elif ! docker ps >/dev/null 2>&1; then
    echo "   ❌ Docker 未运行"
    ISSUES_FOUND=true
else
    echo "   ✅ Docker 正常"
    
    # 检查必要容器
    if ! docker ps | grep -q "mysql"; then
        echo "   ⚠️  MySQL 容器未运行，尝试启动..."
        cd "$PROJECT_DIR"
        docker-compose up -d mysql
        sleep 3
        if docker ps | grep -q "mysql"; then
            echo "   ✅ MySQL 容器已启动"
        else
            echo "   ❌ MySQL 容器启动失败"
            ISSUES_FOUND=true
        fi
    fi
    
    if ! docker ps | grep -q "redis"; then
        echo "   ⚠️  Redis 容器未运行，尝试启动..."
        cd "$PROJECT_DIR"
        docker-compose up -d redis
        sleep 2
        if docker ps | grep -q "redis"; then
            echo "   ✅ Redis 容器已启动"
        else
            echo "   ❌ Redis 容器启动失败"
            ISSUES_FOUND=true
        fi
    fi
fi

# 检查 PHP 环境
echo ""
echo "2. 检查 PHP 环境..."
if ! command -v php >/dev/null 2>&1; then
    echo "   ❌ PHP 未安装"
    ISSUES_FOUND=true
else
    PHP_VERSION=$(php -v | head -1 | awk '{print $2}')
    echo "   ✅ PHP $PHP_VERSION"
    
    # 检查 ThinkPHP 依赖
    if [ ! -f "$PROJECT_DIR/backend/composer.json" ]; then
        echo "   ❌ 后端 composer.json 不存在"
        ISSUES_FOUND=true
    elif [ ! -d "$PROJECT_DIR/backend/vendor" ]; then
        echo "   ⚠️  后端依赖未安装，尝试安装..."
        cd "$PROJECT_DIR/backend"
        composer install --no-dev
        if [ $? -eq 0 ]; then
            echo "   ✅ 后端依赖安装成功"
        else
            echo "   ❌ 后端依赖安装失败"
            ISSUES_FOUND=true
        fi
    else
        echo "   ✅ 后端依赖正常"
    fi
fi

# 检查 Node.js 环境
echo ""
echo "3. 检查 Node.js 环境..."
if ! command -v node >/dev/null 2>&1; then
    echo "   ❌ Node.js 未安装"
    ISSUES_FOUND=true
else
    NODE_VERSION=$(node -v)
    echo "   ✅ Node.js $NODE_VERSION"
    
    # 检查前端依赖
    if [ ! -f "$PROJECT_DIR/frontend/package.json" ]; then
        echo "   ❌ 前端 package.json 不存在"
        ISSUES_FOUND=true
    elif [ ! -d "$PROJECT_DIR/frontend/node_modules" ]; then
        echo "   ⚠️  前端依赖未安装，尝试安装..."
        cd "$PROJECT_DIR/frontend"
        npm install
        if [ $? -eq 0 ]; then
            echo "   ✅ 前端依赖安装成功"
        else
            echo "   ❌ 前端依赖安装失败"
            ISSUES_FOUND=true
        fi
    else
        echo "   ✅ 前端依赖正常"
    fi
fi

# 检查配置文件
echo ""
echo "4. 检查配置文件..."

# 后端配置
if [ ! -f "$PROJECT_DIR/backend/.env" ]; then
    echo "   ⚠️  后端 .env 不存在，尝试从示例创建..."
    if [ -f "$PROJECT_DIR/backend/.env.example" ]; then
        cp "$PROJECT_DIR/backend/.env.example" "$PROJECT_DIR/backend/.env"
        echo "   ✅ 后端 .env 已创建"
    else
        echo "   ❌ 后端 .env.example 也不存在"
        ISSUES_FOUND=true
    fi
else
    echo "   ✅ 后端 .env 存在"
fi

# 前端配置
if [ ! -f "$PROJECT_DIR/frontend/.env.development" ]; then
    echo "   ⚠️  前端 .env.development 不存在，创建默认配置..."
    echo "VITE_API_BASE_URL=/api" > "$PROJECT_DIR/frontend/.env.development"
    echo "   ✅ 前端 .env.development 已创建"
else
    echo "   ✅ 前端 .env.development 存在"
fi

# 检查端口冲突
echo ""
echo "5. 检查端口冲突..."
PORT_CONFLICTS=false

for port in 8000 8282 5173 5174 5175; do
    if lsof -i :$port >/dev/null 2>&1; then
        PROCESS=$(lsof -i :$port | grep LISTEN | awk '{print $1, $2}' | head -1)
        echo "   ⚠️  端口 $port 被占用: $PROCESS"
        
        # 如果是我们自己的服务，跳过
        if echo "$PROCESS" | grep -q "php\|node"; then
            echo "      (可能是 admin-pro 的服务)"
        else
            PORT_CONFLICTS=true
        fi
    fi
done

if [ "$PORT_CONFLICTS" = false ]; then
    echo "   ✅ 无端口冲突"
fi

# 检查磁盘空间
echo ""
echo "6. 检查磁盘空间..."
DISK_USAGE=$(df -h "$PROJECT_DIR" | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 90 ]; then
    echo "   ⚠️  磁盘使用率较高: ${DISK_USAGE}%"
    ISSUES_FOUND=true
else
    echo "   ✅ 磁盘空间充足: ${DISK_USAGE}%"
fi

# 清理临时文件
echo ""
echo "7. 清理临时文件..."
CLEANED_COUNT=0

# 清理过期日志（7天前）
find /tmp -name "admin_*.log" -mtime +7 -delete 2>/dev/null && CLEANED_COUNT=$((CLEANED_COUNT + 1))

# 清理 PIDs 文件如果进程已不存在
if [ -f "/tmp/admin_pids.txt" ]; then
    PIDS=$(cat /tmp/admin_pids.txt)
    ALL_DEAD=true
    for pid in $PIDS; do
        if kill -0 "$pid" 2>/dev/null; then
            ALL_DEAD=false
            break
        fi
    done
    
    if [ "$ALL_DEAD" = true ]; then
        rm -f /tmp/admin_pids.txt
        CLEANED_COUNT=$((CLEANED_COUNT + 1))
        echo "   🧹 清理了孤儿 PIDs 文件"
    fi
fi

if [ "$CLEANED_COUNT" -gt 0 ]; then
    echo "   ✅ 清理了 $CLEANED_COUNT 个临时文件"
else
    echo "   ✅ 无需清理"
fi

# 总结报告
echo ""
echo "================================"
if [ "$ISSUES_FOUND" = true ]; then
    echo "⚠️  发现问题，建议检查上述错误并手动修复"
    echo ""
    echo "🔧 常用修复命令："
    echo "   Docker 问题: docker-compose up -d"
    echo "   后端依赖: cd backend && composer install"
    echo "   前端依赖: cd frontend && npm install"
    echo "   强制重启: bash stop.sh && sleep 3 && bash start.sh"
else
    echo "🎉 系统健康，无发现问题！"
fi

echo ""
echo "📋 下一步操作建议："
echo "   查看状态: bash status.sh"
echo "   启动服务: bash start.sh" 
echo "   停止服务: bash stop.sh"