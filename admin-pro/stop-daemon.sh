#!/bin/bash

# 停止 Admin Pro 守护进程
PROJECT_DIR="/Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro"
PID_FILE="$PROJECT_DIR/.daemon.pid"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

if [ -f "$PID_FILE" ]; then
    DAEMON_PID=$(cat "$PID_FILE")
    
    if kill -0 "$DAEMON_PID" 2>/dev/null; then
        echo -e "${YELLOW}正在停止守护进程 (PID: $DAEMON_PID)...${NC}"
        kill -TERM "$DAEMON_PID"
        
        # 等待优雅退出
        for i in {1..10}; do
            if ! kill -0 "$DAEMON_PID" 2>/dev/null; then
                echo -e "${GREEN}✅ 守护进程已停止${NC}"
                exit 0
            fi
            sleep 1
        done
        
        # 强制杀死
        echo -e "${YELLOW}强制停止守护进程...${NC}"
        kill -KILL "$DAEMON_PID" 2>/dev/null || true
        echo -e "${GREEN}✅ 守护进程已强制停止${NC}"
    else
        echo -e "${YELLOW}⚠️ 守护进程不在运行，清理 PID 文件${NC}"
        rm -f "$PID_FILE"
    fi
else
    echo -e "${YELLOW}⚠️ 没有找到守护进程 PID 文件${NC}"
fi

# 确保清理所有相关进程
echo -e "${YELLOW}清理相关进程...${NC}"
cd "$PROJECT_DIR"
./stop.sh

echo -e "${GREEN}✅ 所有服务已停止${NC}"