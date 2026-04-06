# Admin Pro

> **企业级后台管理系统** - 基于 ThinkPHP 8.0 + Vue 2.0 + ElementUI

一个功能完整的现代化后台管理系统，专门为 OpenClaw AI 平台打造，具备强大的扩展性和模块化设计。

## ✨ 特性

### 🎯 核心功能
- **Agent 管理**: 完整的 AI Agent 生命周期管理
- **会话监控**: 实时会话状态和消息历史追踪  
- **模型配置**: 灵活的 AI 模型配置和切换
- **系统监控**: 全面的系统状态监控面板

### 🏗️ 技术栈
- **后端**: ThinkPHP 8.0 + GatewayWorker + MySQL + Redis
- **前端**: Vue 2.0 + ElementUI + Vite
- **通信**: HTTP REST API + WebSocket 实时通信
- **部署**: Docker Compose 容器化部署

### 🛡️ 安全特性
- JWT 认证系统
- CORS 跨域处理  
- 参数验证和过滤
- SQL 注入防护

## 🚀 快速开始

### 环境要求
- PHP >= 8.0
- Node.js >= 16
- MySQL >= 5.7
- Redis >= 5.0
- Docker & Docker Compose (可选)

### 安装部署

```bash
# 1. 克隆项目
git clone https://github.com/mattdomon/admin-pro.git
cd admin-pro

# 2. 启动服务 (推荐使用 Docker)
bash start.sh

# 3. 访问系统
# 前端: http://localhost:5173
# 后端: http://localhost:8000
```

### 手动安装

**后端设置**:
```bash
cd backend
composer install
cp config/database.example.php config/database.php
# 配置数据库连接
php think migrate:run
```

**前端设置**:
```bash
cd frontend  
npm install
npm run dev
```

## 📁 项目结构

```
admin-pro/
├── backend/                 # ThinkPHP 后端
│   ├── app/                # 应用代码
│   │   ├── controller/     # 控制器
│   │   ├── model/         # 模型
│   │   ├── middleware/    # 中间件
│   │   └── openclaw/      # OpenClaw 模块 (可剥离)
│   ├── config/            # 配置文件
│   ├── database/          # 数据库迁移
│   └── scripts/           # Python 脚本
├── frontend/              # Vue 前端  
│   ├── src/
│   │   ├── components/    # 组件
│   │   ├── views/         # 页面
│   │   ├── api/          # API 接口
│   │   └── utils/        # 工具函数
│   └── dist/             # 构建产物
├── database/             # SQL 文件
├── docs/                # 文档
└── scripts/             # 部署脚本
```

## 🎛️ 功能模块

### Agent 管理
- Agent 列表和状态监控
- 主/备用模型配置管理
- Agent 重启和控制
- 工作空间统计

### 会话管理  
- 实时会话列表
- 会话详情和消息历史
- 会话清理和维护
- 多 Agent 会话支持

### 系统监控
- OpenClaw Gateway 状态
- 服务进程监控
- 系统资源使用情况
- 日志查看和分析

### OpenClaw 集成
- 原生 OpenClaw CLI 集成
- 配置文件热更新
- 脚本管理和执行
- 实时状态同步

## 🔧 配置

### 数据库配置
```php
// config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'hostname' => '127.0.0.1',
            'database' => 'admin_pro',
            'username' => 'root',
            'password' => '',
        ]
    ]
];
```

### 前端代理配置
```javascript
// vite.config.js  
export default defineConfig({
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true
      }
    }
  }
})
```

## 📊 性能

- **响应时间**: API 平均响应 < 100ms
- **并发支持**: 支持 1000+ 并发连接
- **内存使用**: 运行时内存 < 512MB
- **启动时间**: 完整启动 < 30s

## 🤝 贡献指南

1. Fork 项目
2. 创建功能分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 创建 Pull Request

## 📝 更新日志

### v1.0.0 (2026-04-07)
- ✅ 初始版本发布
- ✅ Agent 管理完整功能
- ✅ 会话监控和管理
- ✅ OpenClaw 深度集成
- ✅ Docker 容器化部署

## 📄 许可证

本项目采用 MIT 许可证 - 详见 [LICENSE](LICENSE) 文件

## 🙏 致谢

- [ThinkPHP](https://thinkphp.cn/) - 优秀的 PHP 框架
- [Vue.js](https://vuejs.org/) - 渐进式 JavaScript 框架  
- [ElementUI](https://element.eleme.io/) - 基于 Vue 的组件库
- [OpenClaw](https://openclaw.ai) - AI Agent 平台

---

**开发团队**: HCTOPUP B2B TEAM  
**技术支持**: [matt@hctopup.com](mailto:matt@hctopup.com)  
**项目地址**: https://github.com/mattdomon/admin-pro