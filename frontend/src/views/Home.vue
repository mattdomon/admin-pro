<template>
  <el-container>
    <el-header>
      <div class="header-left">
        <span class="logo">⚡ Admin Pro</span>
      </div>
      <div class="header-right">
        <span>欢迎，{{ username }}</span>
        <el-dropdown @command="handleCommand" style="margin-left: 15px; color: #fff; cursor: pointer;">
          <span class="el-dropdown-link">
            <i class="el-icon-setting" style="font-size: 18px;"></i>
          </span>
          <el-dropdown-menu slot="dropdown">
            <el-dropdown-item command="info">个人信息</el-dropdown-item>
            <el-dropdown-item command="logout">退出登录</el-dropdown-item>
          </el-dropdown-menu>
        </el-dropdown>
      </div>
    </el-header>
    <el-container style="height: calc(100vh - 60px);">
      <el-aside width="220px" class="sidebar">
        <el-menu 
          :default-active="$route.path" 
          router
          :default-openeds="['/dashboard', '/openclaw', '/system']"
          background-color="#304156"
          text-color="#bfcbd9"
          active-text-color="#409EFF">
          
          <!-- 仪表盘 -->
          <el-submenu index="/dashboard">
            <template slot="title">
              <i class="el-icon-s-data"></i>
              <span>仪表盘</span>
            </template>
            <el-menu-item index="/dashboard">
              <i class="el-icon-pie-chart"></i>
              <span slot="title">总览</span>
            </el-menu-item>
            <el-menu-item index="/realtime">
              <i class="el-icon-view"></i>
              <span slot="title">实时监控</span>
            </el-menu-item>
          </el-submenu>
          
          <!-- OpenClaw 管理 -->
          <el-submenu index="/openclaw">
            <template slot="title">
              <i class="el-icon-coin"></i>
              <span>OpenClaw</span>
            </template>
            <el-menu-item index="/openclaw-control">
              <i class="el-icon-s-platform"></i>
              <span slot="title">控制中心</span>
            </el-menu-item>
            <el-menu-item index="/openclaw">
              <i class="el-icon-s-tools"></i>
              <span slot="title">配置管理</span>
            </el-menu-item>
            <el-menu-item index="/ai/models">
              <i class="el-icon-connection"></i>
              <span slot="title">AI模型</span>
            </el-menu-item>
            <el-menu-item index="/ai/chat">
              <i class="el-icon-chat-line-round"></i>
              <span slot="title">AI会话</span>
            </el-menu-item>
            <el-menu-item index="/openclaw/sessions">
              <i class="el-icon-message"></i>
              <span slot="title">会话管理</span>
            </el-menu-item>
            <el-menu-item index="/agents">
              <i class="el-icon-s-custom"></i>
              <span slot="title">Agent管理</span>
            </el-menu-item>
          </el-submenu>
          
          <!-- 脚本与任务 -->
          <el-submenu index="/task">
            <template slot="title">
              <i class="el-icon-s-operation"></i>
              <span>任务管理</span>
            </template>
            <el-menu-item index="/task/dispatch">
              <i class="el-icon-s-promotion"></i>
              <span slot="title">任务下发</span>
            </el-menu-item>
            <el-menu-item index="/scripts">
              <i class="el-icon-tickets"></i>
              <span slot="title">脚本管理</span>
            </el-menu-item>
            <el-menu-item index="/batch">
              <i class="el-icon-s-cooperation"></i>
              <span slot="title">批量调度</span>
            </el-menu-item>
            <el-menu-item index="/terminal">
              <i class="el-icon-monitor"></i>
              <span slot="title">终端日志</span>
            </el-menu-item>
          </el-submenu>
          
          <!-- Bridge 测试 -->
          <el-submenu index="/bridge">
            <template slot="title">
              <i class="el-icon-connection"></i>
              <span>Bridge 测试</span>
            </template>
            <el-menu-item index="/bridge/test">
              <i class="el-icon-cpu"></i>
              <span slot="title">测试面板</span>
            </el-menu-item>
          </el-submenu>
          
          <!-- 系统管理 -->
          <el-submenu index="/system">
            <template slot="title">
              <i class="el-icon-setting"></i>
              <span>系统管理</span>
            </template>
            <el-menu-item index="/nodes">
              <i class="el-icon-s-grid"></i>
              <span slot="title">节点管理</span>
            </el-menu-item>
            <el-menu-item index="/device">
              <i class="el-icon-mobile-phone"></i>
              <span slot="title">设备管理</span>
            </el-menu-item>
            <el-menu-item index="/monitor">
              <i class="el-icon-warning-outline"></i>
              <span slot="title">系统监控</span>
            </el-menu-item>
          </el-submenu>
        </el-menu>
      </el-aside>
      <el-main style="background: #f0f2f5; padding: 0;">
        <div class="main-content">
          <router-view />
        </div>
      </el-main>
    </el-container>
  </el-container>
</template>

<script>
import { logout, getInfo } from '@/api/auth'

export default {
  name: 'Home',
  data() {
    return { username: 'Admin' }
  },
  mounted() {
    getInfo().then(res => {
      this.username = res.data.nickname || res.data.username
    }).catch(() => {})
  },
  methods: {
    handleCommand(cmd) {
      if (cmd === 'logout') {
        this.$confirm('确认退出登录？', '提示', {
          confirmButtonText: '确定',
          cancelButtonText: '取消',
          type: 'warning'
        }).then(() => {
          logout().finally(() => {
            localStorage.removeItem('token')
            this.$router.push('/login')
          })
        })
      } else if (cmd === 'info') {
        this.$message('用户: ' + this.username)
      }
    }
  }
}
</script>

<style scoped>
.el-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 20px;
}
.header-left .logo {
  font-size: 20px;
  font-weight: bold;
}
.header-right {
  display: flex;
  align-items: center;
}
.el-aside {
  background: #304156;
  overflow: hidden;
  box-shadow: 2px 0 6px rgba(0,21,41,.35);
}
.sidebar .el-menu {
  border-right: none;
}
.sidebar .el-submenu__title {
  font-weight: bold;
  font-size: 14px;
}
.sidebar .el-menu-item {
  font-size: 13px;
  padding-left: 45px !important;
}
.sidebar .el-submenu .el-menu-item {
  background-color: #1e2b3b !important;
}
.sidebar .el-submenu .el-menu-item:hover {
  background-color: #253446 !important;
}
.main-content {
  padding: 20px;
}
</style>
