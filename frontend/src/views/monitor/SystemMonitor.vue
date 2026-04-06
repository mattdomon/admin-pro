<template>
  <div class="system-monitor">
    <div class="page-header">
      <h2>系统监控中心</h2>
      <p>实时监控系统运行状态，及时发现和处理异常问题</p>
      <div class="header-actions">
        <el-button type="primary" @click="runHealthCheck" :loading="checkLoading">
          <i class="el-icon-refresh"></i>
          手动检查
        </el-button>
      </div>
    </div>

    <!-- 系统概览 -->
    <el-row :gutter="20" class="overview-cards">
      <el-col :span="6">
        <el-card class="metric-card">
          <div class="metric-content">
            <div class="metric-value">{{ overview.devices?.total || 0 }}</div>
            <div class="metric-label">总节点数</div>
            <div class="metric-trend" :class="overview.devices?.health_rate >= 80 ? 'trend-up' : 'trend-down'">
              {{ overview.devices?.online || 0 }} 在线
            </div>
          </div>
          <div class="metric-icon" style="background: #67c23a;">
            <i class="el-icon-monitor"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="metric-card">
          <div class="metric-content">
            <div class="metric-value">{{ overview.tasks?.running || 0 }}</div>
            <div class="metric-label">运行任务</div>
            <div class="metric-trend trend-neutral">
              今日 {{ overview.tasks?.today || 0 }} 个
            </div>
          </div>
          <div class="metric-icon" style="background: #409eff;">
            <i class="el-icon-cpu"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="metric-card">
          <div class="metric-content">
            <div class="metric-value">{{ overview.tasks?.success_rate || 0 }}%</div>
            <div class="metric-label">成功率</div>
            <div class="metric-trend" :class="overview.tasks?.success_rate >= 90 ? 'trend-up' : 'trend-down'">
              总计 {{ overview.tasks?.total || 0 }} 个
            </div>
          </div>
          <div class="metric-icon" style="background: #e6a23c;">
            <i class="el-icon-success"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="metric-card">
          <div class="metric-content">
            <div class="metric-value">{{ overview.queue?.queued_count || 0 }}</div>
            <div class="metric-label">队列待处理</div>
            <div class="metric-trend" :class="overview.queue?.queued_count <= 10 ? 'trend-up' : 'trend-down'">
              总队列 {{ overview.queue?.total_queue || 0 }}
            </div>
          </div>
          <div class="metric-icon" style="background: #f56c6c;">
            <i class="el-icon-timer"></i>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 主要内容区域 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <!-- 节点健康状态 -->
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>节点健康状态</span>
            <el-button style="float: right; padding: 3px 0" type="text" @click="refreshDeviceHealth">
              刷新
            </el-button>
          </div>
          
          <!-- 健康度分布 -->
          <div class="health-summary">
            <div class="health-item healthy">
              <span class="health-count">{{ deviceHealth.healthy?.length || 0 }}</span>
              <span class="health-label">健康</span>
            </div>
            <div class="health-item warning">
              <span class="health-count">{{ deviceHealth.warning?.length || 0 }}</span>
              <span class="health-label">警告</span>
            </div>
            <div class="health-item critical">
              <span class="health-count">{{ deviceHealth.critical?.length || 0 }}</span>
              <span class="health-label">严重</span>
            </div>
          </div>
          
          <!-- 设备列表 -->
          <div class="device-health-list">
            <!-- 严重问题设备 -->
            <div v-if="deviceHealth.critical && deviceHealth.critical.length > 0">
              <h4 style="color: #f56c6c; margin: 15px 0 10px 0;">🚨 严重问题</h4>
              <div 
                v-for="device in deviceHealth.critical"
                :key="device.uuid"
                class="device-health-item critical"
              >
                <div class="device-info">
                  <div class="device-name">{{ device.name }}</div>
                  <div class="device-status">
                    健康度: {{ device.health_score }}% | 
                    {{ device.online ? '在线' : '离线' }} |
                    运行: {{ device.running_tasks }} 个任务
                  </div>
                </div>
                <div class="device-metrics">
                  <span v-if="device.sys_info.cpu_percent">CPU: {{ device.sys_info.cpu_percent }}%</span>
                  <span v-if="device.sys_info.mem_percent">MEM: {{ device.sys_info.mem_percent }}%</span>
                </div>
              </div>
            </div>
            
            <!-- 警告设备 -->
            <div v-if="deviceHealth.warning && deviceHealth.warning.length > 0">
              <h4 style="color: #e6a23c; margin: 15px 0 10px 0;">⚠️ 需要关注</h4>
              <div 
                v-for="device in deviceHealth.warning"
                :key="device.uuid"
                class="device-health-item warning"
              >
                <div class="device-info">
                  <div class="device-name">{{ device.name }}</div>
                  <div class="device-status">
                    健康度: {{ device.health_score }}% | 
                    {{ device.online ? '在线' : '离线' }} |
                    运行: {{ device.running_tasks }} 个任务
                  </div>
                </div>
                <div class="device-metrics">
                  <span v-if="device.sys_info.cpu_percent">CPU: {{ device.sys_info.cpu_percent }}%</span>
                  <span v-if="device.sys_info.mem_percent">MEM: {{ device.sys_info.mem_percent }}%</span>
                </div>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
      
      <!-- 最近告警 -->
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>最近告警</span>
            <el-button style="float: right; padding: 3px 0" type="text" @click="refreshAlerts">
              查看全部
            </el-button>
          </div>
          
          <div class="alert-list">
            <div 
              v-for="alert in overview.recent_alerts"
              :key="alert.id || Math.random()"
              class="alert-item"
              :class="alert.level"
            >
              <div class="alert-icon">
                <i :class="getAlertIcon(alert.level)"></i>
              </div>
              <div class="alert-content">
                <div class="alert-message">{{ alert.message }}</div>
                <div class="alert-time">{{ formatTime(alert.created_at) }}</div>
              </div>
            </div>
            
            <div v-if="!overview.recent_alerts || overview.recent_alerts.length === 0" class="no-alerts">
              <i class="el-icon-check" style="color: #67c23a; font-size: 24px;"></i>
              <p>暂无告警，系统运行正常</p>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 告警规则配置 -->
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>告警规则配置</span>
      </div>
      
      <el-table :data="alertRules" style="width: 100%">
        <el-table-column prop="name" label="规则名称" />
        <el-table-column prop="condition" label="触发条件" />
        <el-table-column prop="notify_methods" label="通知方式">
          <template slot-scope="scope">
            <el-tag 
              v-for="method in scope.row.notify_methods" 
              :key="method"
              size="mini"
              style="margin-right: 5px;"
            >
              {{ getNotifyMethodName(method) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="enabled" label="状态" width="100">
          <template slot-scope="scope">
            <el-switch
              v-model="scope.row.enabled"
              @change="toggleAlertRule(scope.row)"
            />
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import {
  getSystemOverview,
  getDeviceHealth,
  getAlertRules,
  runSystemCheck
} from '@/api/monitor'

export default {
  name: 'SystemMonitor',
  data() {
    return {
      overview: {},
      deviceHealth: {
        healthy: [],
        warning: [],
        critical: []
      },
      alertRules: [],
      checkLoading: false,
      refreshTimer: null
    }
  },
  
  mounted() {
    this.loadData()
    this.startAutoRefresh()
  },
  
  beforeDestroy() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer)
    }
  },
  
  methods: {
    async loadData() {
      await Promise.all([
        this.loadSystemOverview(),
        this.loadDeviceHealth(),
        this.loadAlertRules()
      ])
    },
    
    async loadSystemOverview() {
      try {
        const response = await getSystemOverview()
        this.overview = response.data
      } catch (error) {
        this.$message.error('获取系统概览失败')
      }
    },
    
    async loadDeviceHealth() {
      try {
        const response = await getDeviceHealth()
        this.deviceHealth = response.data
      } catch (error) {
        this.$message.error('获取设备健康状态失败')
      }
    },
    
    async loadAlertRules() {
      try {
        const response = await getAlertRules()
        this.alertRules = response.data
      } catch (error) {
        this.$message.error('获取告警规则失败')
      }
    },
    
    async runHealthCheck() {
      this.checkLoading = true
      try {
        const response = await runSystemCheck()
        const result = response.data
        
        if (result.alerts_count > 0) {
          this.$message.warning(`发现 ${result.alerts_count} 个新告警`)
        } else {
          this.$message.success('系统检查完成，一切正常')
        }
        
        // 刷新数据
        this.loadData()
      } catch (error) {
        this.$message.error('健康检查失败')
      } finally {
        this.checkLoading = false
      }
    },
    
    refreshDeviceHealth() {
      this.loadDeviceHealth()
    },
    
    refreshAlerts() {
      this.loadSystemOverview()
    },
    
    toggleAlertRule(rule) {
      // TODO: 实现告警规则开关
      this.$message.info(`${rule.enabled ? '启用' : '禁用'}告警规则: ${rule.name}`)
    },
    
    startAutoRefresh() {
      // 每30秒自动刷新
      this.refreshTimer = setInterval(() => {
        this.loadSystemOverview()
      }, 30000)
    },
    
    getAlertIcon(level) {
      const icons = {
        info: 'el-icon-info',
        warning: 'el-icon-warning',
        critical: 'el-icon-error'
      }
      return icons[level] || 'el-icon-bell'
    },
    
    getNotifyMethodName(method) {
      const names = {
        email: '邮件',
        webhook: 'Webhook',
        wechat: '企业微信'
      }
      return names[method] || method
    },
    
    formatTime(timeStr) {
      if (!timeStr) return ''
      const time = new Date(timeStr)
      const now = new Date()
      const diff = now - time
      
      if (diff < 60000) return '刚刚'
      if (diff < 3600000) return `${Math.floor(diff / 60000)} 分钟前`
      if (diff < 86400000) return `${Math.floor(diff / 3600000)} 小时前`
      return time.toLocaleDateString()
    }
  }
}
</script>

<style scoped>
.system-monitor {
  padding: 20px;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.page-header h2 {
  margin: 0 0 8px 0;
  color: #303133;
}

.page-header p {
  margin: 0;
  color: #909399;
  font-size: 14px;
}

.overview-cards {
  margin-bottom: 20px;
}

.metric-card {
  position: relative;
  overflow: hidden;
}

.metric-content {
  padding: 10px 0;
}

.metric-value {
  font-size: 36px;
  font-weight: bold;
  color: #303133;
  line-height: 1;
  margin-bottom: 8px;
}

.metric-label {
  font-size: 14px;
  color: #606266;
  margin-bottom: 4px;
}

.metric-trend {
  font-size: 12px;
}

.trend-up { color: #67c23a; }
.trend-down { color: #f56c6c; }
.trend-neutral { color: #909399; }

.metric-icon {
  position: absolute;
  top: 20px;
  right: 20px;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 24px;
  opacity: 0.8;
}

.health-summary {
  display: flex;
  justify-content: space-around;
  margin-bottom: 20px;
  text-align: center;
}

.health-item .health-count {
  display: block;
  font-size: 24px;
  font-weight: bold;
}

.health-item .health-label {
  font-size: 12px;
  color: #606266;
}

.health-item.healthy .health-count { color: #67c23a; }
.health-item.warning .health-count { color: #e6a23c; }
.health-item.critical .health-count { color: #f56c6c; }

.device-health-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  margin-bottom: 8px;
  border-radius: 4px;
  border-left: 4px solid;
}

.device-health-item.critical {
  border-left-color: #f56c6c;
  background-color: #fef0f0;
}

.device-health-item.warning {
  border-left-color: #e6a23c;
  background-color: #fdf6ec;
}

.device-name {
  font-weight: bold;
  margin-bottom: 4px;
}

.device-status {
  font-size: 12px;
  color: #606266;
}

.device-metrics {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  font-size: 12px;
  color: #909399;
}

.device-metrics span {
  margin-bottom: 2px;
}

.alert-list {
  max-height: 400px;
  overflow-y: auto;
}

.alert-item {
  display: flex;
  align-items: center;
  padding: 12px;
  margin-bottom: 8px;
  border-radius: 4px;
  border-left: 4px solid;
}

.alert-item.info {
  border-left-color: #409eff;
  background-color: #ecf5ff;
}

.alert-item.warning {
  border-left-color: #e6a23c;
  background-color: #fdf6ec;
}

.alert-item.critical {
  border-left-color: #f56c6c;
  background-color: #fef0f0;
}

.alert-icon {
  margin-right: 12px;
  font-size: 16px;
}

.alert-item.info .alert-icon { color: #409eff; }
.alert-item.warning .alert-icon { color: #e6a23c; }
.alert-item.critical .alert-icon { color: #f56c6c; }

.alert-message {
  font-weight: 500;
  margin-bottom: 2px;
}

.alert-time {
  font-size: 12px;
  color: #909399;
}

.no-alerts {
  text-align: center;
  padding: 40px 20px;
  color: #909399;
}

.no-alerts p {
  margin: 10px 0 0 0;
}
</style>