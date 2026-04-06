<template>
  <div class="log-console">
    <el-card>
      <div slot="header" class="clearfix">
        <span>任务日志控制台</span>
        <el-select v-model="selectedTask" placeholder="选择任务..." style="float: right; width: 300px;" @change="onTaskChange">
          <el-option v-for="t in runningTasks" :key="t.id" :label="`${t.script_name} (${t.id})`" :value="t.id"></el-option>
        </el-select>
      </div>
      
      <div class="console-area" ref="consoleArea">
        <div v-if="!selectedTask" class="empty-hint">
          <i class="el-icon-document"></i>
          <p>请从右上角选择一个正在运行的任务</p>
        </div>
        <template v-else>
          <div v-for="(line, i) in logLines" :key="i" :class="['log-line', levelClass(line.level)]">
            <span class="log-time">{{ formatTime(line.ts) }}</span>
            <el-tag :type="levelTagType(line.level)" size="mini" style="margin: 0 6px;">{{ line.level }}</el-tag>
            <span class="log-msg">{{ line.msg }}</span>
          </div>
          <div v-if="loading" class="log-line info">
            <i class="el-icon-loading"></i> 等待更多日志...
          </div>
        </template>
      </div>
    </el-card>

    <!-- 任务详情 -->
    <el-card v-if="taskDetail" style="margin-top: 16px;">
      <div slot="header">任务详情</div>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="任务 ID">{{ taskDetail.id }}</el-descriptions-item>
        <el-descriptions-item label="设备 UUID">{{ taskDetail.device_uuid }}</el-descriptions-item>
        <el-descriptions-item label="脚本">{{ taskDetail.script_name }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="taskStatusType(taskDetail.status)" size="small">{{ taskStatusText(taskDetail.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ taskDetail.created_at }}</el-descriptions-item>
        <el-descriptions-item label="完成时间">{{ taskDetail.finished_at || '-' }}</el-descriptions-item>
      </el-descriptions>
      <div v-if="taskDetail.error_traceback" style="margin-top: 12px;">
        <h4>错误栈</h4>
        <pre class="error-stack">{{ taskDetail.error_traceback }}</pre>
      </div>
      <el-button v-if="taskDetail.status === 'running'" style="margin-top: 12px;" type="danger" size="small" @click="killSelectedTask" :loading="killing">🛑 终止任务</el-button>
    </el-card>
  </div>
</template>

<script>
import { listTasks, getTaskDetail, killTask } from '@/api/device'

export default {
  name: 'LogConsole',
  data() {
    return {
      selectedTask: '',
      logLines: [],
      taskDetail: null,
      loading: false,
      killing: false,
      refreshTimer: null
    }
  },
  computed: {
    runningTasks() {
      return this.$store.state.nodes.tasks.filter(t => t.status === 'running')
    }
  },
  mounted() {
    this.$store.dispatch('nodes/refreshTasks')
    this.refreshTimer = setInterval(() => {
      this.$store.dispatch('nodes/refreshTasks')
      if (this.selectedTask) this.loadTaskDetail()
    }, 5000)
  },
  beforeDestroy() {
    if (this.refreshTimer) clearInterval(this.refreshTimer)
  },
  methods: {
    onTaskChange(taskId) {
      this.selectedTask = taskId
      this.logLines = []
      if (taskId) this.loadTaskDetail()
    },
    async loadTaskDetail() {
      try {
        const { data } = await getTaskDetail(this.selectedTask)
        this.taskDetail = data
        // 从 error_traceback 解析日志行
        if (data.error_traceback) {
          this.logLines = this.parseLogs(data.error_traceback)
        }
      } catch (e) {}
    },
    parseLogs(text) {
      const lines = text.split('\n').filter(l => l.trim())
      return lines.map(line => {
        const match = line.match(/^\[(.*?)\]\s*(.*)$/)
        return {
          ts: Date.now() / 1000,
          level: match ? match[1] : 'INFO',
          msg: match ? match[2] : line
        }
      })
    },
    async killSelectedTask() {
      this.killing = true
      try {
        await killTask({ task_id: this.selectedTask })
        this.$message.success('终止指令已发送')
        setTimeout(() => this.loadTaskDetail(), 2000)
      } catch (e) {
        this.$message.error('终止失败')
      } finally {
        this.killing = false
      }
    },
    levelClass(level) {
      return level ? level.toLowerCase() : 'info'
    },
    levelTagType(level) {
      const map = { INFO: '', WARNING: 'warning', ERROR: 'danger', COT: 'success' }
      return map[level] || 'info'
    },
    taskStatusType(s) {
      const map = { pending: 'info', running: 'warning', success: 'success', failed: 'danger', killed: 'info' }
      return map[s] || 'info'
    },
    taskStatusText(s) {
      const map = { pending: '待执行', running: '运行中', success: '成功', failed: '失败', killed: '已终止' }
      return map[s] || s
    },
    formatTime(ts) {
      if (!ts) return '-'
      const d = new Date(ts * 1000)
      return d.toLocaleTimeString('zh-CN')
    }
  }
}
</script>

<style scoped>
.log-console { padding: 16px; }
.console-area {
  background: #1e1e1e;
  color: #d4d4d4;
  font-family: 'Courier New', monospace;
  font-size: 13px;
  line-height: 1.6;
  padding: 12px;
  height: 500px;
  overflow-y: auto;
  border-radius: 4px;
}
.log-line {
  margin: 2px 0;
  display: flex;
  align-items: flex-start;
}
.log-time {
  color: #6a9955;
  white-space: nowrap;
  margin-right: 8px;
}
.log-msg {
  flex: 1;
  word-break: break-all;
}
.log-line.error .log-msg { color: #f44747; }
.log-line.warning .log-msg { color: #dcdcaa; }
.log-line.info .log-msg { color: #d4d4d4; }
.log-line.cot .log-msg { color: #569cd6; }
.empty-hint {
  color: #6a6a6a;
  text-align: center;
  padding: 80px 0;
  font-size: 16px;
}
.empty-hint i {
  font-size: 48px;
  display: block;
  margin-bottom: 12px;
}
.error-stack {
  background: #2d2d2d;
  color: #f44747;
  padding: 12px;
  border-radius: 4px;
  max-height: 200px;
  overflow-y: auto;
  font-size: 12px;
}
</style>
