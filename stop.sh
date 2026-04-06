#!/bin/bash

# Admin Pro 服务停止脚本
PROJECT_DIR="/Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro"

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

# 停止服务
stop_services() {
    log "停止 Admin Pro 服务..."
    
    # 从 PID 文件停止
    if [ -f "$PROJECT_DIR/.backend.pid" ]; then
        BACKEND_PID=$(cat "$PROJECT_DIR/.backend.pid")
        if kill $BACKEND_PID 2>/dev/null; then
            success "后端服务已停止 (PID: $BACKEND_PID)"
        fi
        rm -f "$PROJECT_DIR/.backend.pid"
    fi
    
    if [ -f "$PROJECT_DIR/.frontend.pid" ]; then
        FRONTEND_PID=$(cat "$PROJECT_DIR/.frontend.pid")
        if kill $FRONTEND_PID 2>/dev/null; then
            success "前端服务已停止 (PID: $FRONTEND_PID)"
        fi
        rm -f "$PROJECT_DIR/.frontend.pid"
    fi
    
    # 强制清理残留进程
    pkill -f "php.*think.*run.*8000" 2>/dev/null && success "清理 PHP 进程"
    pkill -f "vite.*5173" 2>/dev/null && success "清理 Vite 进程"
    pkill -f "npm.*run.*dev" 2>/dev/null && success "清理 NPM 进程"
    
    # 强制释放端口
    if lsof -ti :8000 >/dev/null 2>&1; then
        lsof -ti :8000 | xargs kill -9 2>/dev/null
        success "释放端口 8000"
    fi
    
    if lsof -ti :5173 >/dev/null 2>&1; then
        lsof -ti :5173 | xargs kill -9 2>/dev/null
        success "释放端口 5173"
    fi
    
    success "🛑 所有服务已停止"
}

stop_services