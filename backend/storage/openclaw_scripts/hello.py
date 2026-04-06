"""
示例 Python 脚本 - Hello World
测试节点连通性
"""
import sys
import time

print("[INFO] Hello from OpenClaw client node!")
print(f"[INFO] Python version: {sys.version}")
print("[INFO] Objective: 连通性测试")

for i in range(5):
    print(f"[INFO] 心跳 {i+1}/5...")
    time.sleep(0.5)

print("[INFO] ✅ 连通性测试完成!")
