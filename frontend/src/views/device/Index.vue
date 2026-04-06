<template>
  <div class="device-panel">
    <el-row :gutter="16" style="margin-bottom: 16px;">
      <el-col :span="6">
        <el-card shadow="hover">
          <div class="stat-card">
            <div class="stat-label">在线节点</div>
            <div class="stat-value online">{{ onlineCount }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover">
          <div class="stat-card">
            <div class="stat-label">运行中任务</div>
            <div class="stat-value running">{{ runningTasks }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover">
          <div class="stat-card">
            <div class="stat-label">今日任务</div>
            <div class="stat-value total">{{ todayTasks }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover">
          <div class="stat-card">
            <div class="stat-label">WS 连接状态</div>
            <div :class="['stat-value', wsConnected ? 'online' : 'offline']">
              {{ wsConnected ? '已连接' : '未连接' }}
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-card>
      <div slot="header" class="clearfix">
        <span>节点管理</span>
        <el-button style="float: right;" type="primary" size="small" @click="refresh">🔄 刷新</el-button>
      </div>
      <el-table :data="devices" border style="width: 100%" v-loading="loading">
        <el-table-column prop="uuid" label="设备 UUID" width="200" show-overflow-tooltip></el-table-column>
        <el-table-column prop="name" label="备注名" width="150"></el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="scope">
            <el-tag :type="statusType(scope.row.status)" size="small">
              {{ statusText(scope.row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="系统信息" min-width="200">
          <template slot-scope="scope">
            <span v-if="scope.row.sys_info">
              CPU: {{ scope.row.sys_info.cpu || '-' }} |
              MEM: {{ scope.row.sys_info.mem || '-' }}
            </span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="最后心跳" width="180">
          <template slot-scope="scope">
            {{ scope.row.last_heartbeat ? formatTime(scope.row.last_heartbeat * 1000) : '-' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template slot-scope="scope">
            <el-button size="mini" type="text" @click="testDevice(scope.row)">🔗 测试</el-button>
            <el-button size="mini" type="text" @click="openTaskDialog(scope.row)">📤 下发任务</el-button>
            <el-button size="mini" type="text" style="color: #F56C6C;" @click="deleteDevice(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 下发任务对话框 -->
    <el-dialog title="📤 下发任务" :visible.sync="taskDialogVisible" width="500px">
      <el-form :model="taskForm" label-width="100px">
        <el-form-item label="目标设备">
          <el-input :value="taskForm.device_uuid" disabled></el-input>
        </el-form-item>
        <el-form-item label="脚本名称" prop="script_name">
          <el-input v-model="taskForm.script_name" placeholder="如: hello.py"></el-input>
        </el-form-item>
        <el-form-item label="目标指令">
          <el-input type="textarea" v-model="taskForm.objective" placeholder="描述任务的目标..."></el-input>
        </el-form-item>
        <el-form-item label="LLM 配置">
          <el-input type="textarea" v-model="taskForm.llmConfigRaw" placeholder="JSON 格式, 可选"></el-input>
        </el-form-item>
      </el-form>
      <span slot="footer" class="dialog-footer">
        <el-button @click="taskDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="dispatching" @click="doDispatch">确定下发</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { listDevices, testDevice, deleteDevice, dispatchTask } from '@/api/device'

export default {
  name: 'DeviceIndex',
  data() {
    return {
      devices: [],
      loading: false,
      dispatching: false,
      taskDialogVisible: false,
      taskForm: {
        device_uuid: '',
        script_name: '',
        objective: '',
        llmConfigRaw: ''
      },
      autoRefreshTimer: null
    }
  },
  computed: {
    wsConnected() {
      return this.$store.state.nodes.connected
    },
    onlineCount() {
      return this.devices.filter(d => d.status === 1 || d.status === 2).length
    },
    runningTasks() {
      return this.$store.state.nodes.tasks.filter(t => t.status === 'running').length
    },
    todayTasks() {
      const today = new Date().toISOString().slice(0, 10)
      return this.$store.state.nodes.tasks.filter(
        t => t.created_at && t.created_at.startsWith(today)
      ).length
    }
  },
  mounted() {
    this.refresh()
    // 初始化 WebSocket 连接
    this.$store.dispatch('nodes/connectWs')
    // 30s 自动刷新
    this.autoRefreshTimer = setInterval(() => {
      this.refresh()
    }, 30000)
  },
  beforeDestroy() {
    if (this.autoRefreshTimer) clearInterval(this.autoRefreshTimer)
  },
  methods: {
    async refresh() {
      this.loading = true
      try {
        const { data } = await listDevices()
        this.devices = data || []
        this.$store.commit('nodes/SET_DEVICES', this.devices)
        this.$store.dispatch('nodes/refreshTasks')
      } catch (e) {
        this.$message.error('获取节点列表失败')
      } finally {
        this.loading = false
      }
    },
    async testDevice(row) {
      try {
        await testDevice(row.uuid)
        this.$message.success('连通性测试指令已发送')
      } catch (e) {
        this.$message.error('测试失败')
      }
    },
    deleteDevice(row) {
      this.$confirm(`确认删除设备 ${row.uuid}？`, '提示', { type: 'warning' })
        .then(async () => {
          await deleteDevice(row.uuid)
          this.$message.success('删除成功')
          this.refresh()
        })
        .catch(() => {})
    },
    openTaskDialog(row) {
      this.taskForm = {
        device_uuid: row.uuid,
        script_name: '',
        objective: '',
        llmConfigRaw: ''
      }
      this.taskDialogVisible = true
    },
    async doDispatch() {
      if (!this.taskForm.script_name) {
        this.$message.warning('请输入脚本名称')
        return
      }
      this.dispatching = true
      try {
        let llm = {}
        if (this.taskForm.llmConfigRaw) {
          llm = JSON.parse(this.taskForm.llmConfigRaw)
        }
        await dispatchTask({
          device_uuid: this.taskForm.device_uuid,
          script_name: this.taskForm.script_name,
          params_json: {
            objective: this.taskForm.objective,
            llm_config: llm
          }
        })
        this.$message.success('任务已下发')
        this.taskDialogVisible = false
        this.refresh()
      } catch (e) {
        this.$message.error('下发失败: ' + e.message)
      } finally {
        this.dispatching = false
      }
    },
    statusType(s) {
      const map = { 0: 'info', 1: 'success', 2: 'warning', 3: 'danger' }
      return map[s] || 'info'
    },
    statusText(s) {
      const map = { 0: '离线', 1: '在线', 2: '运行中', 3: '异常' }
      return map[s] || '未知'
    },
    formatTime(ts) {
      if (!ts) return '-'
      const d = new Date(ts)
      return d.toLocaleString('zh-CN', {
        month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
      })
    }
  }
}
</script>

<style scoped>
.stat-card { padding: 10px 0; }
.stat-label { font-size: 13px; color: #909399; margin-bottom: 8px; }
.stat-value { font-size: 28px; font-weight: bold; }
.stat-value.online { color: #67C23A; }
.stat-value.running { color: #E6A23C; }
.stat-value.total { color: #409EFF; }
.stat-value.offline { color: #F56C6C; }
.device-panel { padding: 16px; }
</style>
