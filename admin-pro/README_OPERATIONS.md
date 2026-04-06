# admin-pro 项目管理指南

## 🚀 快速启动

```bash
# 启动所有服务
bash start.sh

# 检查服务状态  
bash status.sh

# 停止所有服务
bash stop.sh

# 健康检查和修复
bash health.sh
```

## 📱 访问地址

- **前端页面**: http://localhost:5173 或 http://localhost:5174
- **后端 API**: http://localhost:8000/api/
- **WebSocket**: ws://localhost:8282

> **注意**: 前端端口可能自动变化（5173→5174），请查看启动日志获取确切端口

## 🔧 常见问题和解决方案

### 1. 端口被占用

**现象**: `ERR_CONNECTION_REFUSED` 或启动失败
**原因**: 端口 8000/8282/5173-5175 被占用
**解决**:
```bash
# 检查端口占用
lsof -i :8000 -i :8282 -i :5173 -i :5174

# 强制停止所有服务
bash stop.sh
killall -9 php node

# 重新启动
bash start.sh
```

### 2. GatewayWorker 启动失败

**现象**: WebSocket 连接失败，端口 1236/8282 冲突
**原因**: Workerman 进程残留
**解决**:
```bash
# 查找并杀死 Workerman 进程
ps aux | grep workerman
kill -9 [PID]

# 或者强制清理
killall -9 php
bash start.sh
```

### 3. 前端无法访问后端 API

**现象**: 前端报 `api/api/task/list` 双重路径错误
**原因**: Vite 代理配置问题
**检查**:
- 确认 `frontend/vite.config.js` 代理配置正确
- 确认 `frontend/.env.development` 设置 `VITE_API_BASE_URL=/api`

### 4. Docker 服务未运行

**现象**: 数据库连接失败
**解决**:
```bash
cd /Users/hc/.openclaw/agents/hc-coding/workspace/admin-pro
docker-compose up -d
```

### 5. 依赖缺失

**后端依赖**:
```bash
cd backend
composer install --no-dev
```

**前端依赖**:
```bash
cd frontend
npm install
```

## 📊 服务监控

### 实时状态检查
```bash
# 完整状态报告
bash status.sh

# 快速端口检查
lsof -i :8000 -i :8282 -i :5173 -i :5174

# API 连通性测试
curl http://localhost:8000/api/test
```

### 日志查看
```bash
# PHP 后端日志
tail -f /tmp/admin_php.log

# GatewayWorker 日志
tail -f /tmp/admin_gateway.log

# 前端 Vite 日志  
tail -f /tmp/admin_frontend.log
```

## 🛠️ 开发模式

### 单独启动服务

**后端**:
```bash
cd backend
php think run --port=8000
```

**GatewayWorker**:
```bash
cd backend
php start_gateway_simple.php start
```

**前端**:
```bash
cd frontend
npm run dev
```

### 构建生产版本

**前端构建**:
```bash
cd frontend
npm run build
# 构建产物在 dist/ 目录
```

**后端部署**:
```bash
cd backend
# 设置 .env 为生产环境
composer install --no-dev --optimize-autoloader
```

## 🔒 安全注意事项

1. **生产环境**必须修改默认密码和密钥
2. 确保 `.env` 文件权限正确 (600)
3. 定期更新依赖包
4. 不要提交敏感配置到版本控制

## 📁 项目结构

```
admin-pro/
├── backend/          # PHP ThinkPHP 后端
│   ├── app/         # 应用代码
│   ├── public/      # Web 根目录
│   └── start_gateway_simple.php  # WebSocket 服务
├── frontend/         # Vue 2.0 前端
│   ├── src/         # 源码
│   ├── dist/        # 构建产物
│   └── vite.config.js
├── database/         # 数据库文件
├── docker-compose.yml # Docker 配置
├── start.sh         # 启动脚本 ⭐
├── stop.sh          # 停止脚本 ⭐  
├── status.sh        # 状态检查 ⭐
└── health.sh        # 健康检查 ⭐
```

## ⚡ 性能优化建议

1. **开发环境**: 使用内置服务器足够
2. **生产环境**: 建议使用 Nginx + PHP-FPM
3. **数据库**: 适当配置 MySQL 连接池
4. **缓存**: Redis 缓存常用数据
5. **前端**: 启用 gzip 压缩和 CDN

## 🆘 紧急故障处理

**完全重置**:
```bash
# 停止所有服务
bash stop.sh
killall -9 php node

# 清理容器（谨慎！）
docker-compose down
docker-compose up -d

# 重新启动
bash health.sh  # 检查并修复问题
bash start.sh   # 启动服务
```

**快速诊断**:
```bash
# 一键健康检查
bash health.sh

# 检查系统资源
df -h     # 磁盘空间
free -h   # 内存使用
top       # CPU 使用
```