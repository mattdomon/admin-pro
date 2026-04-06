#!/bin/bash

# Admin Pro 服务启动脚本
PROJECT_DIR="/Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro"
BACKEND_DIR="$PROJECT_DIR/backend"
FRONTEND_DIR="$PROJECT_DIR/frontend"

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

error() {
    echo -e "${RED}❌ $1${NC}"
}

warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

# 检查端口占用
check_port() {
    local port=$1
    if lsof -i :$port >/dev/null 2>&1; then
        return 0  # 端口被占用
    else
        return 1  # 端口空闲
    fi
}

# 杀死已有进程
kill_existing_services() {
    log "检查并清理现有服务..."
    
    # 杀死 PHP 开发服务器
    pkill -f "php.*think.*run.*8000" 2>/dev/null
    
    # 杀死 Vite 前端服务
    pkill -f "vite.*5173" 2>/dev/null
    pkill -f "npm.*run.*dev" 2>/dev/null
    
    sleep 2
    
    if check_port 8000; then
        warning "端口 8000 仍被占用，强制杀死进程"
        lsof -ti :8000 | xargs kill -9 2>/dev/null || true
    fi
    
    if check_port 5173; then
        warning "端口 5173 仍被占用，强制杀死进程"
        lsof -ti :5173 | xargs kill -9 2>/dev/null || true
    fi
    
    sleep 1
}

# 启动后端服务
start_backend() {
    log "启动后端服务..."
    cd "$BACKEND_DIR"
    
    if ! check_port 8000; then
        php think run -p 8000 &
        BACKEND_PID=$!
        echo $BACKEND_PID > "$PROJECT_DIR/.backend.pid"
        
        # 等待后端启动
        for i in {1..10}; do
            if curl -s http://localhost:8000 >/dev/null 2>&1; then
                success "后端服务启动成功 (PID: $BACKEND_PID, 端口: 8000)"
                return 0
            fi
            sleep 1
        done
        
        error "后端服务启动失败"
        return 1
    else
        warning "端口 8000 已被占用"
        return 1
    fi
}

# 启动前端服务
start_frontend() {
    log "启动前端服务..."
    cd "$FRONTEND_DIR"
    
    if ! check_port 5173; then
        npm run dev &
        FRONTEND_PID=$!
        echo $FRONTEND_PID > "$PROJECT_DIR/.frontend.pid"
        
        # 等待前端启动
        for i in {1..15}; do
            if curl -s http://localhost:5173 >/dev/null 2>&1; then
                success "前端服务启动成功 (PID: $FRONTEND_PID, 端口: 5173)"
                return 0
            fi
            sleep 1
        done
        
        error "前端服务启动失败"
        return 1
    else
        warning "端口 5173 已被占用"
        return 1
    fi
}

# 测试服务
test_services() {
    log "测试服务连通性..."
    
    # 测试后端
    if curl -s http://localhost:8000 | grep -q "Admin Pro"; then
        success "后端 API 正常"
    else
        error "后端 API 异常"
        return 1
    fi
    
    # 测试前端代理
    if curl -s http://localhost:5173/api/auth/info | grep -q '"code":200'; then
        success "前端代理正常"
    else
        error "前端代理异常"
        return 1
    fi
    
    # 测试 OpenClaw 连接
    if curl -s http://localhost:5173/api/openclaw/status | grep -q '"online":true'; then
        success "OpenClaw 连接正常"
    else
        warning "OpenClaw 连接异常，但不影响基础功能"
    fi
}

# 主启动流程
main() {
    echo -e "${BLUE}🚀 启动 Admin Pro 服务${NC}"
    
    kill_existing_services
    
    if start_backend && start_frontend; then
        sleep 3
        test_services
        
        echo ""
        success "🎉 Admin Pro 启动完成！"
        echo -e "${GREEN}前端地址: http://localhost:5173/${NC}"
        echo -e "${GREEN}后端地址: http://localhost:8000/${NC}"
        echo ""
        echo -e "${YELLOW}按 Ctrl+C 停止服务${NC}"
        
        # 等待用户中断
        trap 'kill_existing_services; echo -e "\n${GREEN}✅ 服务已停止${NC}"; exit 0' INT
        
        while true; do
            sleep 5
            # 检查服务健康状态
            if ! check_port 8000 || ! check_port 5173; then
                error "服务异常，正在重启..."
                main
                break
            fi
        done
    else
        error "服务启动失败"
        exit 1
    fi
}

main "$@"