<template>
  <div class="recent-tasks">
    <div class="section-header">
      <h4><i class="el-icon-time"></i> 最近任务</h4>
      <div class="header-actions">
        <el-button type="text" @click="refresh" :loading="loading">
          <i class="el-icon-refresh"></i> 刷新
        </el-button>
        <el-button type="text" @click="clearCompleted">
          <i class="el-icon-delete"></i> 清理完成
        </el-button>
      </div>
    </div>

    <div v-if="loading" class="loading-state">
      <i class="el-icon-loading"></i> 加载任务列表...
    </div>

    <div v-else-if="tasks.length === 0" class="empty-state">
      <i class="el-icon-document-copy"></i>
      <p>暂无执行任务</p>
    </div>

    <div v-else class="tasks-list">
      <div 
        v-for="task in tasks" 
        :key="task.task_id"
        class="task-item"
        :class="getTaskItemClass(task.status)"
        @click="$emit('task-clicked', task)"
      >
        <div class="task-header">
          <div class="task-info">
            <el-tag size="mini" :type="getTaskStatusType(task.status)">
              {{ getTaskStatusText(task.status) }}
            </el-tag>
            <span class="task-type">{{ getTaskTypeText(task.type) }}</span>
            <span class="task-id">{{ task.task_id.substr(-8) }}</span>
          </div>
          <div class="task-time">
            {{ formatTime(task.created_at) }}
          </div>
        </div>
        
        <div class="task-content">
          <div class="task-target">
            <i class="el-icon-s-grid"></i>
            节点: {{ getNodeName(task.target_node_key) }}
          </div>
          
          <div class="task-payload" v-if="task.payload">
            <!-- 脚本任务 -->
            <template v-if="task.type === 'script'">
              <i class="el-icon-document"></i>
              {{ task.payload.script_path }}
            </template>
            
            <!-- OpenClaw任务 -->
            <template v-else-if="task.type === 'openclaw'">
              <i class="el-icon-chat-dot-round"></i>
              {{ task.payload.agent_id }}: {{ truncateText(task.payload.message, 50) }}
            </template>
            
            <!-- 自定义任务 -->
            <template v-else-if="task.type === 'custom'">
              <i class="el-icon-magic-stick"></i>
              {{ truncateText(task.payload.command, 50) }}
            </template>
          </div>
        </div>
        
        <!-- 进度条（运行中的任务） -->
        <div v-if="task.status === 'running'" class="task-progress">
          <el-progress 
            :percentage="getTaskProgress(task)"
            :show-text="false"
            color="#409EFF"
            stroke-width="4"
          ></el-progress>
        </div>
        
        <!-- 错误信息（失败的任务） -->
        <div v-if="task.status === 'failed' && task.error_message" class="task-error">
          <i class="el-icon-warning"></i>
          {{ truncateText(task.error_message, 100) }}
        </div>
        
        <!-- 结果预览（成功的任务） -->
        <div v-if="task.status === 'success' && task.result" class="task-result">
          <i class="el-icon-check"></i>
          {{ truncateText(task.result, 100) }}
        </div>
      </div>
    </div>

    <!-- 分页 -->
    <el-pagination
      v-if="tasks.length > 0"
      @current-change="handlePageChange"
      :current-page="pagination.page"
      :page-size="pagination.size"
      :total="pagination.total"
      layout="prev, pager, next, total"
      style="margin-top: 15px; text-align: center;"
      small
    >
    </el-pagination>
  </div>
</template>

<script>
import { mapState } from 'vuex'

export default {
  name: 'RecentTasks',
  
  data() {
    return {
      tasks: [],
      loading: false,
      autoRefreshTimer: null,
      
      pagination: {
        page: 1,
        size: 10,
        total: 0
      }
    }
  },
  
  computed: {
    ...mapState('nodes', ['nodeKeys'])
  },
  
  mounted() {
    this.loadTasks()
    this.startAutoRefresh()
  },
  
  beforeDestroy() {
    this.stopAutoRefresh()
  },
  
  methods: {
    async loadTasks() {
      this.loading = true
      try {
        const res = await this.$axios.get('/api/openclaw/bridge/tasks', {
          params: {
            page: this.pagination.page,
            size: this.pagination.size,
            status: '' // 全部状态
          }
        })
        
        if (res.data.code === 200) {
          this.tasks = res.data.data.tasks || []
          this.pagination.total = res.data.data.total || 0
        }
      } catch (error) {
        console.error('加载任务列表失败:', error)
        // 静默失败，不弹错误
      } finally {
        this.loading = false
      }
    },
    
    async clearCompleted() {
      try {
        const res = await this.$axios.delete('/api/openclaw/bridge/tasks/completed')
        if (res.data.code === 200) {
          this.$message.success(`已清理 ${res.data.data.count} 个完成任务`)
          this.loadTasks()
        }
      } catch (error) {
        this.$message.error('清理失败: ' + error.message)
      }
    },
    
    refresh() {
      this.loadTasks()
    },
    
    handlePageChange(page) {
      this.pagination.page = page
      this.loadTasks()
    },
    
    startAutoRefresh() {
      // 每15秒自动刷新一次
      this.autoRefreshTimer = setInterval(() => {
        this.loadTasks()
      }, 15000)
    },
    
    stopAutoRefresh() {
      if (this.autoRefreshTimer) {
        clearInterval(this.autoRefreshTimer)
        this.autoRefreshTimer = null
      }
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
        pending: '等待中',
        running: '执行中',
        success: '已完成',
        failed: '失败',
        timeout: '超时',
        cancelled: '已取消'
      }
      return statusMap[status] || status
    },
    
    getTaskTypeText(type) {
      const typeMap = {
        script: '脚本',
        openclaw: 'OpenClaw',
        custom: '自定义'
      }
      return typeMap[type] || type
    },
    
    getTaskItemClass(status) {
      return {
        'task-pending': status === 'pending',
        'task-running': status === 'running',
        'task-success': status === 'success',
        'task-failed': status === 'failed' || status === 'timeout',
        'task-cancelled': status === 'cancelled'
      }
    },
    
    getNodeName(nodeKey) {
      if (!nodeKey) return '未知节点'
      
      // 通过脱敏Key后缀匹配节点
      const suffix = nodeKey.slice(-4)
      const node = this.nodeKeys.find(n => 
        n.node_key_masked && n.node_key_masked.endsWith(suffix)
      )
      
      return node ? (node.node_name || `节点-${suffix}`) : `节点-${suffix}`
    },
    
    getTaskProgress(task) {
      // 简单的进度估算：根据运行时间
      if (task.started_at) {
        const elapsed = Date.now() / 1000 - task.started_at
        const estimated = task.execution_timeout || 300 // 默认5分钟
        return Math.min(Math.floor((elapsed / estimated) * 100), 95)
      }
      return 10
    },
    
    truncateText(text, maxLength) {
      if (!text) return ''
      if (text.length <= maxLength) return text
      return text.substring(0, maxLength) + '...'
    },
    
    formatTime(timestamp) {
      if (!timestamp) return '-'
      const date = new Date(timestamp * 1000)
      const now = new Date()
      const diff = now - date
      
      if (diff < 60000) return '刚刚'
      if (diff < 3600000) return `${Math.floor(diff / 60000)}分钟前`
      if (diff < 86400000) return `${Math.floor(diff / 3600000)}小时前`
      return date.toLocaleDateString()
    }
  }
}
</script>

<style scoped>
.recent-tasks {
  margin-top: 30px;
  border-top: 1px solid #ebeef5;
  padding-top: 20px;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.section-header h4 {
  margin: 0;
  color: #409EFF;
  font-size: 16px;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.loading-state, .empty-state {
  text-align: center;
  padding: 40px;
  color: #999;
}

.empty-state i {
  font-size: 48px;
  margin-bottom: 10px;
}

.tasks-list {
  max-height: 500px;
  overflow-y: auto;
}

.task-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 12px 15px;
  margin-bottom: 10px;
  cursor: pointer;
  transition: all 0.3s ease;
  background: white;
}

.task-item:hover {
  border-color: #409EFF;
  box-shadow: 0 2px 8px rgba(64, 158, 255, 0.2);
}

.task-item.task-running {
  border-left: 4px solid #E6A23C;
  background: #fdf6ec;
}

.task-item.task-success {
  border-left: 4px solid #67C23A;
}

.task-item.task-failed {
  border-left: 4px solid #F56C6C;
  background: #fef0f0;
}

.task-item.task-pending {
  border-left: 4px solid #909399;
}

.task-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.task-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.task-type {
  font-size: 12px;
  color: #666;
  background: #f0f0f0;
  padding: 2px 6px;
  border-radius: 3px;
}

.task-id {
  font-family: monospace;
  font-size: 11px;
  color: #999;
}

.task-time {
  font-size: 12px;
  color: #999;
}

.task-content {
  font-size: 13px;
  line-height: 1.4;
}

.task-target, .task-payload {
  margin-bottom: 4px;
  color: #666;
}

.task-target i, .task-payload i {
  margin-right: 4px;
  color: #409EFF;
}

.task-progress {
  margin-top: 8px;
}

.task-error, .task-result {
  margin-top: 8px;
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.3;
}

.task-error {
  background: #fef0f0;
  color: #F56C6C;
  border: 1px solid #fbc4c4;
}

.task-error i {
  margin-right: 5px;
}

.task-result {
  background: #f0f9ff;
  color: #67C23A;
  border: 1px solid #c6e2ff;
}

.task-result i {
  margin-right: 5px;
}
</style>