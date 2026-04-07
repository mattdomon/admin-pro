<template>
  <div class="task-detail">
    <el-card v-if="task" class="box-card">
      <div slot="header" class="clearfix">
        <span><i class="el-icon-document-copy"></i> 任务详情</span>
        <div style="float: right;">
          <el-button 
            type="text" 
            @click="$router.back()"
          >
            返回
          </el-button>
          <el-button 
            type="text" 
            @click="refresh"
            :loading="loading"
          >
            刷新
          </el-button>
        </div>
      </div>

      <!-- 任务基本信息 -->
      <div class="task-info-section">
        <el-row :gutter="20">
          <el-col :span="12">
            <div class="info-card">
              <h4>基本信息</h4>
              <el-descriptions :column="1" border>
                <el-descriptions-item label="任务ID">
                  <el-tag type="info">{{ task.task_id }}</el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="任务类型">
                  {{ getTaskTypeText(task.type) }}
                </el-descriptions-item>
                <el-descriptions-item label="状态">
                  <el-tag :type="getTaskStatusType(task.status)">
                    {{ getTaskStatusText(task.status) }}
                  </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="目标节点">
                  {{ getNodeName(task.target_node_key) }}
                </el-descriptions-item>
                <el-descriptions-item label="优先级">
                  {{ task.priority || 'normal' }}
                </el-descriptions-item>
              </el-descriptions>
            </div>
          </el-col>
          
          <el-col :span="12">
            <div class="info-card">
              <h4>时间信息</h4>
              <el-descriptions :column="1" border>
                <el-descriptions-item label="创建时间">
                  {{ formatFullTime(task.created_at) }}
                </el-descriptions-item>
                <el-descriptions-item label="开始时间">
                  {{ task.started_at ? formatFullTime(task.started_at) : '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="结束时间">
                  {{ task.finished_at ? formatFullTime(task.finished_at) : '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="执行时长">
                  {{ getExecutionDuration() }}
                </el-descriptions-item>
                <el-descriptions-item label="超时设置">
                  {{ task.execution_timeout || 300 }}秒
                </el-descriptions-item>
              </el-descriptions>
            </div>
          </el-col>
        </el-row>
      </div>

      <!-- 任务载荷 -->
      <div class="task-payload-section">
        <h4>任务载荷</h4>
        <el-card shadow="never" class="payload-card">
          <pre class="payload-content">{{ JSON.stringify(task.payload, null, 2) }}</pre>
        </el-card>
      </div>

      <!-- 执行结果 -->
      <div class="task-result-section" v-if="task.status !== 'pending'">
        <h4>执行结果</h4>
        
        <!-- 成功结果 -->
        <el-card v-if="task.status === 'success'" shadow="never" class="result-success">
          <div class="result-header">
            <i class="el-icon-circle-check"></i>
            <span>执行成功</span>
          </div>
          <div class="result-content" v-if="task.result">
            <pre>{{ typeof task.result === 'string' ? task.result : JSON.stringify(task.result, null, 2) }}</pre>
          </div>
        </el-card>
        
        <!-- 失败结果 -->
        <el-card v-else-if="task.status === 'failed' || task.status === 'timeout'" shadow="never" class="result-error">
          <div class="result-header">
            <i class="el-icon-circle-close"></i>
            <span>执行失败</span>
          </div>
          <div class="result-content" v-if="task.error_message">
            <pre>{{ task.error_message }}</pre>
          </div>
          <div class="error-details" v-if="task.error_details">
            <h5>详细错误信息</h5>
            <pre>{{ JSON.stringify(task.error_details, null, 2) }}</pre>
          </div>
        </el-card>
        
        <!-- 运行中 -->
        <el-card v-else-if="task.status === 'running'" shadow="never" class="result-running">
          <div class="result-header">
            <i class="el-icon-loading"></i>
            <span>正在执行中...</span>
          </div>
          <div class="progress-section">
            <el-progress 
              :percentage="getTaskProgress()" 
              :show-text="true"
              color="#409EFF"
            ></el-progress>
            <p style="margin-top: 10px; color: #666;">
              已运行 {{ getExecutionDuration() }}，预计还需 {{ getEstimatedRemaining() }}
            </p>
          </div>
        </el-card>
      </div>

      <!-- 执行日志 -->
      <div class="task-logs-section" v-if="task.logs && task.logs.length > 0">
        <h4>执行日志</h4>
        <el-card shadow="never" class="logs-card">
          <div class="logs-content">
            <div 
              v-for="(log, index) in task.logs" 
              :key="index"
              class="log-entry"
              :class="getLogEntryClass(log.level)"
            >
              <span class="log-time">{{ formatFullTime(log.timestamp) }}</span>
              <span class="log-level">{{ log.level.toUpperCase() }}</span>
              <span class="log-message">{{ log.message }}</span>
            </div>
          </div>
        </el-card>
      </div>

      <!-- 操作按钮 -->
      <div class="task-actions" v-if="canOperate()">
        <el-button 
          v-if="task.status === 'running'"
          type="danger" 
          @click="cancelTask"
          :loading="cancelling"
        >
          取消任务
        </el-button>
        
        <el-button 
          v-if="task.status === 'failed'"
          type="warning" 
          @click="retryTask"
          :loading="retrying"
        >
          重试任务
        </el-button>
        
        <el-button 
          type="primary" 
          @click="downloadLogs"
        >
          下载日志
        </el-button>
      </div>
    </el-card>
    
    <div v-else-if="loading" class="loading-state">
      <i class="el-icon-loading"></i> 加载任务详情...
    </div>
    
    <el-card v-else class="error-card">
      <div class="error-content">
        <i class="el-icon-warning"></i>
        <h3>任务不存在</h3>
        <p>找不到ID为 {{ $route.params.taskId }} 的任务</p>
        <el-button @click="$router.back()">返回</el-button>
      </div>
    </el-card>
  </div>
</template>

<script>
import { mapState } from 'vuex'

export default {
  name: 'TaskDetail',
  
  data() {
    return {
      task: null,
      loading: false,
      cancelling: false,
      retrying: false,
      autoRefreshTimer: null
    }
  },
  
  computed: {
    ...mapState('nodes', ['nodeKeys']),
    
    taskId() {
      return this.$route.params.taskId
    }
  },
  
  mounted() {
    this.loadTask()
    this.startAutoRefresh()
  },
  
  beforeDestroy() {
    this.stopAutoRefresh()
  },
  
  methods: {
    async loadTask() {
      this.loading = true
      try {
        const res = await this.$axios.get(`/api/openclaw/bridge/tasks/${this.taskId}`)
        if (res.data.code === 200) {
          this.task = res.data.data.task
        } else {
          this.task = null
        }
      } catch (error) {
        console.error('加载任务详情失败:', error)
        this.task = null
      } finally {
        this.loading = false
      }
    },
    
    async cancelTask() {
      this.cancelling = true
      try {
        const res = await this.$axios.post(`/api/openclaw/bridge/tasks/${this.taskId}/cancel`)
        if (res.data.code === 200) {
          this.$message.success('任务已取消')
          this.loadTask()
        } else {
          this.$message.error(res.data.message || '取消失败')
        }
      } catch (error) {
        this.$message.error('取消任务失败')
      } finally {
        this.cancelling = false
      }
    },
    
    async retryTask() {
      this.retrying = true
      try {
        const res = await this.$axios.post(`/api/openclaw/bridge/tasks/${this.taskId}/retry`)
        if (res.data.code === 200) {
          this.$message.success('任务已重新提交')
          this.loadTask()
        } else {
          this.$message.error(res.data.message || '重试失败')
        }
      } catch (error) {
        this.$message.error('重试任务失败')
      } finally {
        this.retrying = false
      }
    },
    
    downloadLogs() {
      // 创建日志文件内容
      let logContent = `任务 ${this.taskId} 执行日志\n`
      logContent += `生成时间: ${new Date().toLocaleString()}\n`
      logContent += `任务状态: ${this.task.status}\n`
      logContent += `任务类型: ${this.task.type}\n`
      logContent += `目标节点: ${this.getNodeName(this.task.target_node_key)}\n`
      logContent += `\n=== 载荷信息 ===\n`
      logContent += JSON.stringify(this.task.payload, null, 2)
      
      if (this.task.logs && this.task.logs.length > 0) {
        logContent += `\n\n=== 执行日志 ===\n`
        this.task.logs.forEach(log => {
          logContent += `[${this.formatFullTime(log.timestamp)}] ${log.level.toUpperCase()}: ${log.message}\n`
        })
      }
      
      if (this.task.result) {
        logContent += `\n\n=== 执行结果 ===\n`
        logContent += typeof this.task.result === 'string' ? this.task.result : JSON.stringify(this.task.result, null, 2)
      }
      
      if (this.task.error_message) {
        logContent += `\n\n=== 错误信息 ===\n`
        logContent += this.task.error_message
      }
      
      // 下载文件
      const blob = new Blob([logContent], { type: 'text/plain;charset=utf-8' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `task_${this.taskId}_logs.txt`
      link.click()
      URL.revokeObjectURL(url)
      
      this.$message.success('日志已下载')
    },
    
    refresh() {
      this.loadTask()
    },
    
    startAutoRefresh() {
      // 如果任务还在运行，每10秒刷新
      if (this.task && this.task.status === 'running') {
        this.autoRefreshTimer = setInterval(() => {
          this.loadTask()
        }, 10000)
      }
    },
    
    stopAutoRefresh() {
      if (this.autoRefreshTimer) {
        clearInterval(this.autoRefreshTimer)
        this.autoRefreshTimer = null
      }
    },
    
    canOperate() {
      return this.task && ['running', 'failed'].includes(this.task.status)
    },
    
    getTaskProgress() {
      if (!this.task || !this.task.started_at) return 0
      const elapsed = Date.now() / 1000 - this.task.started_at
      const total = this.task.execution_timeout || 300
      return Math.min(Math.floor((elapsed / total) * 100), 95)
    },
    
    getExecutionDuration() {
      if (!this.task) return '-'
      
      const start = this.task.started_at
      const end = this.task.finished_at || (Date.now() / 1000)
      
      if (!start) return '-'
      
      const duration = end - start
      if (duration < 60) return `${Math.floor(duration)}秒`
      if (duration < 3600) return `${Math.floor(duration / 60)}分${Math.floor(duration % 60)}秒`
      return `${Math.floor(duration / 3600)}时${Math.floor((duration % 3600) / 60)}分`
    },
    
    getEstimatedRemaining() {
      if (!this.task || !this.task.started_at) return '未知'
      const elapsed = Date.now() / 1000 - this.task.started_at
      const total = this.task.execution_timeout || 300
      const remaining = Math.max(0, total - elapsed)
      
      if (remaining < 60) return `${Math.floor(remaining)}秒`
      return `${Math.floor(remaining / 60)}分${Math.floor(remaining % 60)}秒`
    },
    
    getNodeName(nodeKey) {
      if (!nodeKey) return '未知节点'
      const suffix = nodeKey.slice(-4)
      const node = this.nodeKeys.find(n => 
        n.node_key_masked && n.node_key_masked.endsWith(suffix)
      )
      return node ? (node.node_name || `节点-${suffix}`) : `节点-${suffix}`
    },
    
    getTaskStatusType(status) {
      const statusMap = {
        pending: 'info',
        running: 'warning',
        success: 'success', 
        failed: 'danger',
        timeout: 'danger',
        cancelled: 'info'
      }
      return statusMap[status] || 'info'
    },
    
    getTaskStatusText(status) {
      const statusMap = {
        pending: '等待执行',
        running: '执行中',
        success: '执行成功',
        failed: '执行失败',
        timeout: '执行超时',
        cancelled: '已取消'
      }
      return statusMap[status] || status
    },
    
    getTaskTypeText(type) {
      const typeMap = {
        script: '脚本执行',
        openclaw: 'OpenClaw消息',
        custom: '自定义指令'
      }
      return typeMap[type] || type
    },
    
    getLogEntryClass(level) {
      return {
        'log-debug': level === 'debug',
        'log-info': level === 'info', 
        'log-warning': level === 'warning',
        'log-error': level === 'error'
      }
    },
    
    formatFullTime(timestamp) {
      if (!timestamp) return '-'
      const date = new Date(timestamp * 1000)
      return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit', 
        second: '2-digit'
      })
    }
  },
  
  watch: {
    task: {
      handler(newTask) {
        // 任务状态变化时调整自动刷新
        this.stopAutoRefresh()
        if (newTask && newTask.status === 'running') {
          this.startAutoRefresh()
        }
      },
      deep: true
    }
  }
}
</script>

<style scoped>
.task-detail {
  padding: 20px;
}

.task-info-section, .task-payload-section, 
.task-result-section, .task-logs-section {
  margin-bottom: 30px;
}

.info-card h4, .task-payload-section h4, 
.task-result-section h4, .task-logs-section h4 {
  margin-bottom: 15px;
  color: #409EFF;
  border-bottom: 2px solid #409EFF;
  padding-bottom: 5px;
}

.payload-card, .logs-card {
  background: #fafafa;
}

.payload-content, .result-content pre, .logs-content {
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 13px;
  line-height: 1.5;
  margin: 0;
  max-height: 300px;
  overflow-y: auto;
}

.result-success {
  border-left: 4px solid #67C23A;
  background: #f0f9ff;
}

.result-error {
  border-left: 4px solid #F56C6C;
  background: #fef0f0;
}

.result-running {
  border-left: 4px solid #E6A23C;
  background: #fdf6ec;
}

.result-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: bold;
  margin-bottom: 10px;
}

.result-header i {
  font-size: 18px;
}

.progress-section {
  margin-top: 15px;
}

.error-details {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 1px solid #ebeef5;
}

.error-details h5 {
  margin: 0 0 10px 0;
  color: #F56C6C;
}

.log-entry {
  padding: 5px 10px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 12px;
  display: flex;
  gap: 10px;
}

.log-time {
  color: #999;
  white-space: nowrap;
}

.log-level {
  font-weight: bold;
  min-width: 60px;
}

.log-message {
  flex: 1;
  word-break: break-all;
}

.log-debug .log-level { color: #909399; }
.log-info .log-level { color: #409EFF; }
.log-warning .log-level { color: #E6A23C; }
.log-error .log-level { color: #F56C6C; }

.task-actions {
  text-align: center;
  padding-top: 20px;
  border-top: 1px solid #ebeef5;
}

.loading-state {
  text-align: center;
  padding: 100px;
  color: #999;
}

.error-card .error-content {
  text-align: center;
  padding: 60px;
}

.error-content i {
  font-size: 48px;
  color: #F56C6C;
  margin-bottom: 15px;
}

.error-content h3 {
  margin: 0 0 10px 0;
  color: #333;
}
</style>