#!/bin/bash

# Admin Pro 守护进程脚本 - 保持服务持续运行
PROJECT_DIR="/Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro"
BACKEND_DIR="$PROJECT_DIR/backend"
FRONTEND_DIR="$PROJECT_DIR/frontend"
LOG_FILE="$PROJECT_DIR/daemon.log"
PID_FILE="$PROJECT_DIR/.daemon.pid"

# 颜色输出
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() {
    echo -e "[$(date +'%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

check_and_start_service() {
    local service_name=$1
    local port=$2
    local start_command=$3
    local pid_key=$4
    
    if ! lsof -i :$port >/dev/null 2>&1; then
        log "${RED}⚠️ $service_name 服务断开 (端口 $port)，正在重启...${NC}"
        
        # 清理可能的残留进程
        pkill -f "$start_command" 2>/dev/null || true
        
        # 启动服务
        if [[ $service_name == "前端" ]]; then
            cd "$FRONTEND_DIR"
        else
            cd "$BACKEND_DIR"
        fi
        
        $start_command &
        local pid=$!
        echo $pid > "$PROJECT_DIR/.$pid_key.pid"
        
        # 等待启动
        sleep 5
        
        if lsof -i :$port >/dev/null 2>&1; then
            log "${GREEN}✅ $service_name 服务重启成功 (PID: $pid, 端口: $port)${NC}"
        else
            log "${RED}❌ $service_name 服务重启失败${NC}"
        fi
    fi
}

# 主守护循环
daemon_loop() {
    log "${BLUE}🚀 Admin Pro 守护进程启动${NC}"
    
    while true; do
        # 检查后端服务 (端口 8000)
        check_and_start_service "后端" 8000 "php think run -p 8000" "backend"
        
        # 检查前端服务 (端口 5173)  
        check_and_start_service "前端" 5173 "npm run dev" "frontend"
        
        # 每30秒检查一次
        sleep 30
    done
}

# 信号处理
cleanup() {
    log "${YELLOW}📋 收到终止信号，正在清理...${NC}"
    
    # 杀死子服务
    [ -f "$PROJECT_DIR/.backend.pid" ] && kill $(cat "$PROJECT_DIR/.backend.pid") 2>/dev/null || true
    [ -f "$PROJECT_DIR/.frontend.pid" ] && kill $(cat "$PROJECT_DIR/.frontend.pid") 2>/dev/null || true
    
    # 清理端口
    lsof -ti :8000 | xargs kill -9 2>/dev/null || true
    lsof -ti :5173 | xargs kill -9 2>/dev/null || true
    
    # 删除 PID 文件
    rm -f "$PROJECT_DIR"/.*.pid "$PID_FILE"
    
    log "${GREEN}✅ 守护进程已停止${NC}"
    exit 0
}

# 检查是否已在运行
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        log "${YELLOW}⚠️ 守护进程已在运行 (PID: $OLD_PID)${NC}"
        exit 1
    else
        log "${YELLOW}⚠️ 清理旧的 PID 文件${NC}"
        rm -f "$PID_FILE"
    fi
fi

# 记录当前 PID
echo $$ > "$PID_FILE"

# 设置信号处理
trap cleanup SIGINT SIGTERM

# 初始启动
cd "$PROJECT_DIR"
./stop.sh >/dev/null 2>&1
sleep 2

log "${BLUE}🚀 初始启动服务...${NC}"
check_and_start_service "后端" 8000 "php think run -p 8000" "backend"
check_and_start_service "前端" 5173 "npm run dev" "frontend"

# 等待服务稳定
sleep 10

# 验证服务
if curl -s http://localhost:5173/api/auth/info | grep -q '"code":200'; then
    log "${GREEN}🎉 所有服务启动完成并验证通过！${NC}"
    log "${GREEN}前端: http://localhost:5173/${NC}"
    log "${GREEN}后端: http://localhost:8000/${NC}"
else
    log "${RED}❌ 服务验证失败${NC}"
    cleanup
    exit 1
fi

# 进入守护循环
daemon_loop