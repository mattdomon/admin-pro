#!/bin/bash
cd /Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro
pkill -f "python3 bridge.py" 2>/dev/null
sleep 1
python3 bridge.py > logs/bridge_stage3.log 2>&1 &
echo $! > bridge_stage3.pid
echo "Bridge PID: $!"
