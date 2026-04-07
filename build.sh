#!/bin/bash
# =============================================================
# build.sh - OpenClaw Bridge 打包脚本 (macOS / Linux)
# 输出: dist/OpenClaw-Bridge  (单文件可执行，无需 Python 环境)
# =============================================================
set -e

echo "=============================="
echo " OpenClaw-Bridge 打包工具"
echo "=============================="

# 1. 检查 Python
if ! command -v python3 &>/dev/null; then
    echo "[ERROR] 未找到 python3，请先安装 Python 3.9+"
    exit 1
fi

PYTHON=$(command -v python3)
echo "[INFO] 使用 Python: $PYTHON ($($PYTHON --version))"

# 2. 安装/更新依赖
echo ""
echo "[STEP 1] 安装依赖..."
$PYTHON -m pip install -q -r requirements.txt
$PYTHON -m pip install -q "pyinstaller>=6.0.0"

# 3. 清理旧产物
echo ""
echo "[STEP 2] 清理旧构建..."
rm -rf build dist OpenClaw-Bridge.spec 2>/dev/null || true

# 4. 打包
echo ""
echo "[STEP 3] 开始打包（--onefile 单文件模式）..."
$PYTHON -m PyInstaller \
    --noconfirm \
    --onefile \
    --console \
    --name "OpenClaw-Bridge" \
    bridge.py

# 5. 验证
echo ""
echo "[STEP 4] 验证输出..."
if [ -f "dist/OpenClaw-Bridge" ]; then
    SIZE=$(du -sh dist/OpenClaw-Bridge | cut -f1)
    echo "[OK] 打包成功: dist/OpenClaw-Bridge (${SIZE})"
else
    echo "[ERROR] 打包失败，未找到 dist/OpenClaw-Bridge"
    exit 1
fi

# 6. 提示使用方法
echo ""
echo "=============================="
echo " 部署说明"
echo "=============================="
echo ""
echo "将以下文件/目录复制到目标边缘节点："
echo ""
echo "  dist/OpenClaw-Bridge     <- 可执行文件（必须）"
echo "  config/bridge.json       <- 配置文件（必须）"
echo ""
echo "目标节点目录结构："
echo "  /opt/openclaw-bridge/"
echo "  ├── OpenClaw-Bridge      <- 可执行文件"
echo "  ├── config/"
echo "  │   └── bridge.json      <- 配置文件"
echo "  ├── logs/                <- 自动创建"
echo "  └── data/"
echo "      └── tasks/           <- 自动创建"
echo ""
echo "启动命令："
echo "  chmod +x OpenClaw-Bridge"
echo "  ./OpenClaw-Bridge"
echo ""
echo "后台运行："
echo "  nohup ./OpenClaw-Bridge > /dev/null 2>&1 &"
echo "  # 或使用 systemd（见 deploy/openclaw-bridge.service）"
echo ""
