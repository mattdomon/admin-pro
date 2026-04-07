@echo off
REM =============================================================
REM build.bat - OpenClaw Bridge 打包脚本 (Windows)
REM 输出: dist\OpenClaw-Bridge.exe (单文件可执行，无需 Python 环境)
REM =============================================================
setlocal enabledelayedexpansion

echo ==============================
echo  OpenClaw-Bridge 打包工具
echo ==============================

REM 1. 检查 Python
python --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] 未找到 python，请先安装 Python 3.9+
    exit /b 1
)

for /f "tokens=*" %%i in ('python --version') do echo [INFO] 使用 %%i

REM 2. 安装依赖
echo.
echo [STEP 1] 安装依赖...
python -m pip install -q -r requirements.txt
python -m pip install -q "pyinstaller>=6.0.0"

REM 3. 清理旧产物
echo.
echo [STEP 2] 清理旧构建...
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist
if exist OpenClaw-Bridge.spec del OpenClaw-Bridge.spec

REM 4. 打包
echo.
echo [STEP 3] 开始打包（--onefile 单文件模式）...
python -m PyInstaller ^
    --noconfirm ^
    --onefile ^
    --console ^
    --name "OpenClaw-Bridge" ^
    bridge.py

REM 5. 验证
echo.
echo [STEP 4] 验证输出...
if exist "dist\OpenClaw-Bridge.exe" (
    echo [OK] 打包成功: dist\OpenClaw-Bridge.exe
) else (
    echo [ERROR] 打包失败，未找到 dist\OpenClaw-Bridge.exe
    exit /b 1
)

echo.
echo ==============================
echo  部署说明
echo ==============================
echo.
echo 将以下文件复制到目标边缘节点：
echo.
echo   dist\OpenClaw-Bridge.exe    ^<- 可执行文件（必须）
echo   config\bridge.json          ^<- 配置文件（必须）
echo.
echo 启动命令：
echo   OpenClaw-Bridge.exe
echo.
pause
