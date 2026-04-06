<template>
  <div class="dashboard">
    <h2 style="margin-bottom: 20px;">📊 Admin Pro - 多节点管理系统</h2>
    
    <!-- 系统概览卡片 -->
    <el-row :gutter="20" class="overview-cards">
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover" @click.native="$router.push('/device')">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="el-icon-monitor"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value">{{ deviceStats.total }}</div>
            <div class="stat-label">注册节点</div>
            <div class="stat-sublabel">在线: {{ deviceStats.online }} | 离线: {{ deviceStats.offline }}</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover" @click.native="$router.push('/task')">
          <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
            <i class="el-icon-s-operation"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value">{{ taskStats.total }}</div>
            <div class="stat-label">总任务数</div>
            <div class="stat-sublabel">运行: {{ taskStats.running }} | 成功: {{ taskStats.success }}</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover" @click.native="$router.push('/realtime')">
          <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <i class="el-icon-view"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value">{{ wsConnections }}</div>
            <div class="stat-label">WebSocket 连接</div>
            <div class="stat-sublabel">端口: 8282</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <i class="el-icon-s-data"></i>
          </div>
          <div class="stat-content">
            <div class="stat-value">{{ systemStatus.uptime }}</div>
            <div class="stat-label">系统运行时间</div>
            <div class="stat-sublabel">状态正常</div>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 项目信息与系统状态 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>📦 项目信息</span>
          </div>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="项目名称">Admin Pro - 多节点管理系统</el-descriptions-item>
            <el-descriptions-item label="技术栈">ThinkPHP 8.0 + Vue 2.0 + ElementUI</el-descriptions-item>
            <el-descriptions-item label="OpenClaw 版本">
              <el-tag :type="openclawStatus.online ? 'success' : 'danger'" size="small">
                {{ openclawStatus.version }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="后端服务">http://localhost:8000</el-descriptions-item>
            <el-descriptions-item label="前端服务">http://localhost:5175</el-descriptions-item>
            <el-descriptions-item label="WebSocket">ws://localhost:8282</el-descriptions-item>
            <el-descriptions-item label="完成度">
              <el-tag type="success" size="small">
                {{ projectStats.completion }}% 完成
              </el-tag>
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
      
      <!-- 功能模块状态 -->
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>🚀 功能模块状态</span>
          </div>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="实时面板">
              <el-tag type="success" size="mini">✅ 正常</el-tag>
              <el-button type="text" size="mini" @click="$router.push('/realtime')">访问</el-button>
            </el-descriptions-item>
            <el-descriptions-item label="节点管理">
              <el-tag type="success" size="mini">✅ 正常</el-tag>
              <el-button type="text" size="mini" @click="$router.push('/device')">访问</el-button>
            </el-descriptions-item>
            <el-descriptions-item label="批量调度">
              <el-tag type="success" size="mini">✅ 正常</el-tag>
              <el-button type="text" size="mini" @click="$router.push('/batch')">访问</el-button>
            </el-descriptions-item>
            <el-descriptions-item label="脚本管理">
              <el-tag type="success" size="mini">✅ 正常</el-tag>
              <el-button type="text" size="mini" @click="$router.push('/scripts')">访问</el-button>
            </el-descriptions-item>
            <el-descriptions-item label="系统监控">
              <el-tag type="success" size="mini">✅ 正常</el-tag>
              <el-button type="text" size="mini" @click="$router.push('/monitor')">访问</el-button>
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 快速操作 -->
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>⚡ 快速操作</span>
      </div>
      <el-row :gutter="20">
        <el-col :span="4">
          <el-button type="primary" style="width: 100%; height: 60px;" @click="$router.push('/realtime')">
            <i class="el-icon-view"></i><br>实时面板
          </el-button>
        </el-col>
        <el-col :span="4">
          <el-button type="success" style="width: 100%; height: 60px;" @click="$router.push('/device')">
            <i class="el-icon-monitor"></i><br>节点管理
          </el-button>
        </el-col>
        <el-col :span="4">
          <el-button type="warning" style="width: 100%; height: 60px;" @click="$router.push('/batch')">
            <i class="el-icon-s-operation"></i><br>批量调度
          </el-button>
        </el-col>
        <el-col :span="4">
          <el-button type="info" style="width: 100%; height: 60px;" @click="$router.push('/scripts')">
            <i class="el-icon-document"></i><br>脚本管理
          </el-button>
        </el-col>
        <el-col :span="4">
          <el-button type="danger" style="width: 100%; height: 60px;" @click="$router.push('/monitor')">
            <i class="el-icon-s-data"></i><br>系统监控
          </el-button>
        </el-col>
        <el-col :span="4">
          <el-button type="primary" style="width: 100%; height: 60px;" @click="$router.push('/terminal')">
            <i class="el-icon-monitor"></i><br>日志控制台
          </el-button>
        </el-col>
      </el-row>
    </el-card>
    
    <!-- 最近活动 -->
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>📈 系统活动</span>
        <el-button style="float: right;" type="text" @click="refreshData">刷新</el-button>
      </div>
      <el-table :data="recentActivities" style="width: 100%" size="small">
        <el-table-column prop="time" label="时间" width="150"></el-table-column>
        <el-table-column prop="type" label="类型" width="100">
          <template slot-scope="scope">
            <el-tag :type="scope.row.type === 'success' ? 'success' : (scope.row.type === 'warning' ? 'warning' : 'info')" size="mini">
              {{ scope.row.typeText }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="description" label="描述"></el-table-column>
        <el-table-column prop="node" label="节点" width="120"></el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { getDeviceList, listTasks } from '@/api/device'
import { getStatus } from '@/api/openclaw'

export default {
  name: 'Dashboard',
  data() {
    return {
      deviceStats: {
        total: 0,
        online: 0,
        offline: 0
      },
      taskStats: {
        total: 0,
        running: 0,
        success: 0,
        failed: 0
      },
      systemStatus: {
        uptime: '0天'
      },
      wsConnections: 0,
      projectStats: {
        completion: 100
      },
      openclawStatus: {
        online: false,
        version: '加载中...',
        gateway: {
          latency: 0
        }
      },
      recentActivities: [
        {
          time: new Date().toLocaleString(),
          type: 'success',
          typeText: '系统启动',
          description: 'Admin Pro 多节点管理系统启动成功',
          node: 'System'
        }
      ]
    }
  },
  mounted() {
    this.fetchData()
    this.startTimer()
  },
  beforeDestroy() {
    if (this.timer) {
      clearInterval(this.timer)
    }
  },
  methods: {
    async fetchData() {
      await this.fetchDevices()
      await this.fetchTasks()
      await this.fetchOpenClawStatus()
      this.checkWebSocket()
    },
    
    async fetchOpenClawStatus() {
      try {
        const res = await getStatus()
        if (res.code === 200) {
          this.openclawStatus = {
            online: res.data.online || false,
            version: res.data.version || 'Unknown',
            gateway: res.data.gateway || { latency: 0 }
          }
        }
      } catch (error) {
        console.log('获取 OpenClaw 状态失败:', error)
        this.openclawStatus = {
          online: false,
          version: 'Offline',
          gateway: { latency: 0 }
        }
        // 显示错误提示
        this.$message.error('获取系统状态失败')
      }
    },
    
    async fetchDevices() {
      try {
        const res = await getDeviceList()
        if (res.code === 200) {
          const devices = res.data || []
          this.deviceStats.total = devices.length
          this.deviceStats.online = devices.filter(d => d.status === 1).length
          this.deviceStats.offline = devices.filter(d => d.status === 0).length
        }
      } catch (e) {
        console.log('获取设备信息失败:', e)
      }
    },
    
    async fetchTasks() {
      try {
        const res = await listTasks()
        if (res.code === 200) {
          const tasks = res.data || []
          this.taskStats.total = tasks.length
          this.taskStats.running = tasks.filter(t => t.status === 'running').length
          this.taskStats.success = tasks.filter(t => t.status === 'success').length
          this.taskStats.failed = tasks.filter(t => t.status === 'failed').length
        }
      } catch (e) {
        console.log('获取任务信息失败:', e)
      }
    },
    
    checkWebSocket() {
      // 检查 WebSocket 连接状态
      try {
        const ws = new WebSocket('ws://localhost:8282')
        ws.onopen = () => {
          this.wsConnections = 1
          ws.close()
        }
        ws.onerror = () => {
          this.wsConnections = 0
        }
      } catch (e) {
        this.wsConnections = 0
      }
    },
    
    startTimer() {
      // 更新运行时间
      const startTime = new Date()
      this.timer = setInterval(() => {
        const now = new Date()
        const diff = Math.floor((now - startTime) / 1000)
        const days = Math.floor(diff / 86400)
        const hours = Math.floor((diff % 86400) / 3600)
        const minutes = Math.floor((diff % 3600) / 60)
        this.systemStatus.uptime = `${days}天${hours}小时${minutes}分`
      }, 60000)
    },
    
    refreshData() {
      this.fetchData()
      this.$message.success('数据已刷新')
    }
  }
}
</script>

<style scoped>
.dashboard {
  padding: 20px;
}
.overview-cards {
  margin-bottom: 20px;
}
.stat-card {
  cursor: pointer;
  min-height: 100px;
}
.stat-card .el-card__body {
  display: flex;
  align-items: center;
  padding: 20px;
}
.stat-card.el-card {
  transition: all 0.3s ease;
}
.stat-card:hover.el-card {
  box-shadow: 0 2px 12px rgba(0,0,0,0.15);
  transform: translateY(-2px);
}
.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: white;
  margin-right: 20px;
}
.stat-content {
  flex: 1;
}
.stat-value {
  font-size: 32px;
  font-weight: bold;
  color: #303133;
  line-height: 1;
}
.stat-label {
  font-size: 16px;
  color: #606266;
  margin: 8px 0 4px 0;
}
.stat-sublabel {
  font-size: 12px;
  color: #909399;
}
</style>