<template>
  <div class="realtime-dashboard">
    <!-- WebSocket 连接状态 -->
    <el-alert
      v-if="!wsConnected"
      :title="wsReconnecting ? '重新连接中...' : 'WebSocket 未连接'"
      :type="wsReconnecting ? 'warning' : 'error'"
      :closable="false"
      show-icon
      style="margin-bottom: 20px"
    />
    
    <!-- 系统统计卡片 -->
    <el-row :gutter="20" style="margin-bottom: 20px">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-value">{{ systemStats.onlineNodes }}/{{ systemStats.totalNodes }}</div>
            <div class="stat-label">在线节点</div>
            <i class="el-icon-monitor stat-icon"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-value">{{ systemStats.runningTasks }}</div>
            <div class="stat-label">运行任务</div>
            <i class="el-icon-loading stat-icon running"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-value">{{ nodeUtilization }}%</div>
            <div class="stat-label">节点利用率</div>
            <i class="el-icon-pie-chart stat-icon"></i>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-value">{{ systemStats.todayTasks }}</div>
            <div class="stat-label">今日任务</div>
            <i class="el-icon-document stat-icon"></i>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 实时节点状态 -->
    <el-row :gutter="20" style="margin-bottom: 20px">
      <el-col :span="12">
        <el-card shadow="hover">
          <div slot="header">
            <span>🔗 实时节点状态</span>
            <el-tag v-if="wsConnected" type="success" size="mini" style="float: right">
              <i class="el-icon-wifi"></i> 已连接
            </el-tag>
          </div>
          
          <div v-if="onlineNodes.length === 0" class="empty-state">
            <i class="el-icon-monitor"></i>
            <p>暂无在线节点</p>
          </div>
          
          <div v-else>
            <div 
              v-for="node in onlineNodes" 
              :key="node.uuid" 
              class="node-item"
            >
              <div class="node-info">
                <el-tag :type="getNodeStatusType(node.status)" size="mini">
                  {{ getNodeStatusText(node.status) }}
                </el-tag>
                <span class="node-name">{{ node.uuid }}</span>
              </div>
              <div class="node-stats" v-if="node.sysInfo">
                <el-progress
                  :percentage="parseFloat(node.sysInfo.cpu)"
                  :color="getCpuColor(parseFloat(node.sysInfo.cpu))"
                  :show-text="false"
                  :stroke-width="6"
                  style="width: 60px; margin-right: 10px"
                />
                <span class="stat-text">CPU: {{ node.sysInfo.cpu }}</span>
                <span class="stat-text">内存: {{ node.sysInfo.mem }}</span>
              </div>
              <div class="node-time">
                {{ formatTime(node.lastHeartbeat) }}
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="12">
        <el-card shadow="hover">
          <div slot="header">
            <span>⚡ 运行中任务</span>
            <el-tag type="primary" size="mini" style="float: right">
              {{ runningTasks.length }} 个
            </el-tag>
          </div>
          
          <div v-if="runningTasks.length === 0" class="empty-state">
            <i class="el-icon-document"></i>
            <p>暂无运行任务</p>
          </div>
          
          <div v-else>
            <div
              v-for="task in runningTasks"
              :key="task.taskId"
              class="task-item"
            >
              <div class="task-info">
                <el-tag type="success" size="mini">运行中</el-tag>
                <span class="task-id">{{ task.taskId }}</span>
              </div>
              <div class="task-node">
                节点: {{ task.deviceUuid }}
              </div>
              <div class="task-progress" v-if="task.progress">
                <el-progress
                  :percentage="task.progress"
                  :stroke-width="6"
                  style="width: 100px"
                />
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 实时日志流（如果有选中任务） -->
    <el-card v-if="selectedTaskId" shadow="hover">
      <div slot="header">
        <span>📋 任务日志流: {{ selectedTaskId }}</span>
        <el-button 
          size="mini" 
          icon="el-icon-close"
          style="float: right"
          @click="selectedTaskId = null"
        />
      </div>
      
      <div class="log-console" ref="logConsole">
        <div
          v-for="(log, index) in taskLogs[selectedTaskId] || []"
          :key="index"
          :class="['log-line', `log-${log.level.toLowerCase()}`]"
        >
          <span class="log-time">{{ formatLogTime(log.ts) }}</span>
          <span class="log-level">[{{ log.level }}]</span>
          <span class="log-message">{{ log.msg }}</span>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script>
import { mapState, mapGetters, mapActions } from 'vuex'

export default {
  name: 'RealtimeDashboard',
  
  data() {
    return {
      selectedTaskId: null
    }
  },
  
  computed: {
    ...mapState('realtime', [
      'wsConnected',
      'wsReconnecting', 
      'wsError',
      'systemStats',
      'taskLogs'
    ]),
    
    ...mapGetters('realtime', [
      'onlineNodes',
      'runningTasks', 
      'nodeUtilization'
    ])
  },
  
  methods: {
    ...mapActions('realtime', ['initWebSocket']),
    
    getNodeStatusType(status) {
      const map = { 0: 'info', 1: 'success', 2: 'warning', 3: 'danger' }
      return map[status] || 'info'
    },
    
    getNodeStatusText(status) {
      const map = { 0: '离线', 1: '在线', 2: '运行中', 3: '异常' }
      return map[status] || '未知'
    },
    
    getCpuColor(cpu) {
      if (cpu < 50) return '#67C23A'
      if (cpu < 80) return '#E6A23C'
      return '#F56C6C'
    },
    
    formatTime(timestamp) {
      if (!timestamp) return '-'
      const now = Date.now() / 1000
      const diff = now - timestamp
      
      if (diff < 60) return '刚刚'
      if (diff < 3600) return `${Math.floor(diff / 60)}分钟前`
      return `${Math.floor(diff / 3600)}小时前`
    },
    
    formatLogTime(timestamp) {
      return new Date(timestamp * 1000).toLocaleTimeString()
    },
    
    selectTask(taskId) {
      this.selectedTaskId = taskId
      this.$nextTick(() => {
        this.scrollLogToBottom()
      })
    },
    
    scrollLogToBottom() {
      const logConsole = this.$refs.logConsole
      if (logConsole) {
        logConsole.scrollTop = logConsole.scrollHeight
      }
    }
  },
  
  mounted() {
    // 初始化 WebSocket 连接
    this.initWebSocket()
  },
  
  watch: {
    // 监听日志更新，自动滚动到底部
    'taskLogs': {
      deep: true,
      handler() {
        if (this.selectedTaskId) {
          this.$nextTick(() => {
            this.scrollLogToBottom()
          })
        }
      }
    }
  }
}
</script>

<style scoped>
.realtime-dashboard {
  padding: 20px;
}

/* 统计卡片样式 */
.stat-card {
  border-radius: 8px;
}

.stat-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
}

.stat-value {
  font-size: 32px;
  font-weight: bold;
  color: #409EFF;
  line-height: 1;
}

.stat-label {
  font-size: 14px;
  color: #606266;
  margin-top: 8px;
}

.stat-icon {
  position: absolute;
  top: 0;
  right: 0;
  font-size: 20px;
  color: #C0C4CC;
}

.stat-icon.running {
  animation: spin 2s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* 空状态样式 */
.empty-state {
  text-align: center;
  color: #909399;
  padding: 40px 0;
}

.empty-state i {
  font-size: 48px;
  margin-bottom: 16px;
  display: block;
}

/* 节点项目样式 */
.node-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid #EBEEF5;
}

.node-item:last-child {
  border-bottom: none;
}

.node-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.node-name {
  font-weight: 500;
}

.node-stats {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stat-text {
  font-size: 12px;
  color: #909399;
}

.node-time {
  font-size: 12px;
  color: #C0C4CC;
}

/* 任务项目样式 */
.task-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 0;
  border-bottom: 1px solid #EBEEF5;
}

.task-item:last-child {
  border-bottom: none;
}

.task-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.task-id {
  font-weight: 500;
  font-size: 14px;
}

.task-node {
  font-size: 12px;
  color: #909399;
}

/* 日志控制台样式 */
.log-console {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 16px;
  border-radius: 4px;
  font-family: 'Monaco', 'Consolas', monospace;
  font-size: 12px;
  height: 300px;
  overflow-y: auto;
  line-height: 1.5;
}

.log-line {
  display: flex;
  margin-bottom: 2px;
}

.log-time {
  color: #608b4e;
  margin-right: 8px;
  min-width: 80px;
}

.log-level {
  margin-right: 8px;
  min-width: 50px;
  font-weight: bold;
}

.log-info .log-level {
  color: #4fc3f7;
}

.log-warning .log-level {
  color: #ffb74d;
}

.log-error .log-level {
  color: #f48fb1;
}

.log-message {
  flex: 1;
}
</style>