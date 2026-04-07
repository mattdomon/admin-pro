<template>
  <div class="openclaw-control">
    <h2 style="margin-bottom: 20px;">🚀 OpenClaw 控制中心</h2>
    
    <!-- 系统状态卡片 -->
    <el-row :gutter="20" class="status-cards">
      <el-col :span="6">
        <el-card class="status-card" shadow="hover">
          <div class="status-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="el-icon-cpu"></i>
          </div>
          <div class="status-content">
            <div class="status-value">{{ systemStatus.online ? 'ONLINE' : 'OFFLINE' }}</div>
            <div class="status-label">系统状态</div>
            <div class="status-sublabel">{{ systemStatus.version }}</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="status-card" shadow="hover">
          <div class="status-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
            <i class="el-icon-connection"></i>
          </div>
          <div class="status-content">
            <div class="status-value">{{ systemStatus.gateway.latency }}ms</div>
            <div class="status-label">Gateway 延迟</div>
            <div class="status-sublabel">PID: {{ systemStatus.gateway.pid || 'N/A' }}</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="status-card" shadow="hover">
          <div class="status-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <i class="el-icon-s-data"></i>
          </div>
          <div class="status-content">
            <div class="status-value">{{ modelStats.total }}</div>
            <div class="status-label">配置模型</div>
            <div class="status-sublabel">{{ modelStats.providers }} 个提供商</div>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card class="status-card" shadow="hover">
          <div class="status-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <i class="el-icon-folder-opened"></i>
          </div>
          <div class="status-content">
            <div class="status-value">{{ systemStatus.scripts_count }}</div>
            <div class="status-label">脚本数量</div>
            <div class="status-sublabel">Python 脚本</div>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 控制面板 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <!-- 服务控制 -->
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>🔧 服务控制</span>
          </div>
          <el-row :gutter="10">
            <el-col :span="8">
              <el-button type="success" style="width: 100%;" @click="startService" :loading="serviceLoading">
                <i class="el-icon-video-play"></i><br>启动 Gateway
              </el-button>
            </el-col>
            <el-col :span="8">
              <el-button type="warning" style="width: 100%;" @click="restartService" :loading="serviceLoading">
                <i class="el-icon-refresh-right"></i><br>重启 Gateway
              </el-button>
            </el-col>
            <el-col :span="8">
              <el-button type="danger" style="width: 100%;" @click="stopService" :loading="serviceLoading">
                <i class="el-icon-video-pause"></i><br>停止 Gateway
              </el-button>
            </el-col>
          </el-row>
          
          <!-- 执行结果 -->
          <el-card v-if="lastOperation.result" style="margin-top: 15px;" shadow="never">
            <div slot="header" style="font-size: 14px;">
              最后操作结果
            </div>
            <pre style="font-size: 12px; max-height: 100px; overflow-y: auto;">{{ lastOperation.result }}</pre>
          </el-card>
        </el-card>
      </el-col>
      
      <!-- 测试脚本 -->
      <el-col :span="12">
        <el-card>
          <div slot="header">
            <span>🧪 测试脚本</span>
            <el-button style="float: right;" type="text" @click="refreshTestScripts">刷新</el-button>
          </div>
          
          <el-row :gutter="10" style="margin-bottom: 15px;">
            <el-col :span="18">
              <el-select v-model="selectedTestScript" placeholder="选择测试脚本" style="width: 100%;">
                <el-option 
                  v-for="script in testScripts" 
                  :key="script.name" 
                  :label="script.name" 
                  :value="script.name">
                  <span style="float: left;">{{ script.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 12px;">{{ script.dir }}</span>
                </el-option>
              </el-select>
            </el-col>
            <el-col :span="6">
              <el-button 
                type="primary" 
                style="width: 100%;" 
                @click="runTestScriptAction" 
                :loading="testLoading"
                :disabled="!selectedTestScript">
                🚀 运行
              </el-button>
            </el-col>
          </el-row>
          
          <el-row :gutter="10">
            <el-col :span="12">
              <el-button 
                type="success" 
                style="width: 100%;" 
                @click="quickTest" 
                :loading="testLoading">
                ⚡ 快速测试
              </el-button>
            </el-col>
            <el-col :span="12">
              <el-button 
                type="warning" 
                style="width: 100%;" 
                @click="testOpenClaw" 
                :loading="testLoading">
                🤖 测试OpenClaw
              </el-button>
            </el-col>
          </el-row>
          
          <div v-if="testResult" style="margin-top: 15px;">
            <el-alert
              :title="testResult.message"
              :type="testResult.success ? 'success' : 'error'"
              :description="testResult.description"
              show-icon
              :closable="false">
            </el-alert>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 第二行：模型管理 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <el-col :span="24">
        <el-card>
          <div slot="header">
            <span>🤖 模型管理</span>
            <el-button style="float: right;" type="text" @click="refreshModels">刷新</el-button>
          </div>
          
          <div style="max-height: 200px; overflow-y: auto;">
            <div v-for="model in models.slice(0, 5)" :key="model.id" style="display: flex; justify-content: space-between; align-items: center; padding: 8px; border-bottom: 1px solid #f0f0f0;">
              <div>
                <div style="font-weight: bold; font-size: 14px;">{{ model.name }}</div>
                <div style="font-size: 12px; color: #666;">{{ model.provider }}/{{ model.modelId }}</div>
              </div>
              <div>
                <el-tag v-if="model.isDefault" type="success" size="mini">默认</el-tag>
                <el-tag v-if="model.reasoning" type="warning" size="mini">推理</el-tag>
              </div>
            </div>
          </div>
          
          <el-button type="primary" style="width: 100%; margin-top: 10px;" @click="$router.push('/ai/models')">
            管理所有模型
          </el-button>
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 日志查看器 -->
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>📋 系统日志</span>
        <el-button-group style="float: right;">
          <el-button size="small" @click="fetchLogs">刷新日志</el-button>
          <el-button size="small" @click="clearLogs">清除</el-button>
        </el-button-group>
      </div>
      
      <div style="background: #f5f5f5; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
        <div v-if="logs.length === 0" style="text-align: center; color: #999;">
          暂无日志，点击刷新日志获取最新信息
        </div>
        <div v-else v-for="(line, index) in logs" :key="index">
          {{ line }}
        </div>
      </div>
    </el-card>
    
    <!-- 自定义命令 -->
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>⚡ 自定义命令</span>
      </div>
      
      <el-row :gutter="10">
        <el-col :span="18">
          <el-input 
            v-model="customCommand" 
            placeholder="输入 openclaw 命令，例如: openclaw status --deep"
            @keyup.enter.native="executeCustomCommand"
          />
        </el-col>
        <el-col :span="6">
          <el-button type="primary" @click="executeCustomCommand" :loading="commandLoading">
            执行命令
          </el-button>
        </el-col>
      </el-row>
      
      <div v-if="commandResult" style="margin-top: 15px;">
        <el-card shadow="never">
          <div slot="header" style="font-size: 14px;">
            执行结果 (返回码: {{ commandResult.code }})
          </div>
          <pre style="font-size: 12px; max-height: 200px; overflow-y: auto; background: #f8f8f8; padding: 10px;">{{ commandResult.output }}</pre>
        </el-card>
      </div>
    </el-card>
  </div>
</template>

<script>
import { getStatus, restartService, getLogs, executeCommand } from '@/api/openclaw'
import { listModels } from '@/api/ai'
import { runTestScript, getTestScriptList } from '@/api/test'

export default {
  name: 'OpenClawControl',
  data() {
    return {
      systemStatus: {
        online: false,
        version: '加载中...',
        gateway: {
          latency: 0,
          pid: null
        },
        scripts_count: 0
      },
      models: [],
      modelStats: {
        total: 0,
        providers: 0
      },
      logs: [],
      customCommand: '',
      commandResult: null,
      lastOperation: {
        result: ''
      },
      serviceLoading: false,
      commandLoading: false,
      testLoading: false,
      testScripts: [],
      selectedTestScript: '',
      testResult: null
    }
  },
  mounted() {
    this.fetchSystemStatus()
    this.fetchModels()
    this.fetchTestScripts()
    
    // 初始化 WebSocket 连接
    this.$store.dispatch('realtime/initWebSocket')
    
    // 监听 task_response 消息
    this.$store.watch(
      (state) => state.realtime.liveTasks,
      (newTasks) => {
        // 处理任务状态变化
        this.handleTaskStatusChange(newTasks)
      },
      { deep: true }
    )
  },
  methods: {
    async fetchSystemStatus() {
      try {
        const response = await getStatus()
        this.systemStatus = response.data
      } catch (error) {
        console.error('获取系统状态失败:', error)
        this.$message.error('获取系统状态失败')
      }
    },
    
    async fetchModels() {
      try {
        const response = await listModels()
        this.models = response.data.models
        this.modelStats.total = this.models.length
        this.modelStats.providers = [...new Set(this.models.map(m => m.provider))].length
      } catch (error) {
        console.error('获取模型列表失败:', error)
      }
    },
    
    async refreshModels() {
      await this.fetchModels()
      this.$message.success('模型列表已刷新')
    },
    
    async startService() {
      this.serviceLoading = true
      try {
        const response = await axios.post('http://localhost:8000/openclaw/start')
        if (response.data.code === 200) {
          this.$message.success('Gateway 启动成功')
          this.lastOperation.result = response.data.data.output
          await this.fetchSystemStatus()
        } else {
          this.$message.error('Gateway 启动失败')
          this.lastOperation.result = response.data.data.output || response.data.message
        }
      } catch (error) {
        console.error('启动服务失败:', error)
        this.$message.error('启动服务失败')
      }
      this.serviceLoading = false
    },
    
    async restartService() {
      this.serviceLoading = true
      try {
        const response = await restartService()
        this.$message.success('Gateway 重启成功')
        this.lastOperation.result = response.data.output
        // 等待几秒后刷新状态
        setTimeout(() => {
          this.fetchSystemStatus()
        }, 3000)
      } catch (error) {
        console.error('重启服务失败:', error)
        this.$message.error('重启服务失败')
      }
      this.serviceLoading = false
    },
    
    async stopService() {
      this.serviceLoading = true
      try {
        const response = await axios.post('http://localhost:8000/openclaw/stop')
        if (response.data.code === 200) {
          this.$message.success('Gateway 停止成功')
          this.lastOperation.result = response.data.data.output
          await this.fetchSystemStatus()
        } else {
          this.$message.warning('停止命令已执行')
          this.lastOperation.result = response.data.data.output || response.data.message
        }
      } catch (error) {
        console.error('停止服务失败:', error)
        this.$message.error('停止服务失败')
      }
      this.serviceLoading = false
    },
    
    async fetchLogs() {
      try {
        const response = await getLogs(50)
        this.logs = response.data.logs.split('\n').filter(line => line.trim())
        this.$message.success(`获取了 ${this.logs.length} 行日志`)
      } catch (error) {
        console.error('获取日志失败:', error)
        this.$message.error('获取日志失败')
      }
    },
    
    clearLogs() {
      this.logs = []
      this.$message.success('日志已清除')
    },
    
    async executeCustomCommand() {
      if (!this.customCommand.trim()) {
        this.$message.warning('请输入命令')
        return
      }
      
      if (!this.customCommand.startsWith('openclaw ')) {
        this.$message.error('只允许执行 openclaw 命令')
        return
      }
      
      this.commandLoading = true
      try {
        const response = await executeCommand(this.customCommand)
        this.commandResult = response.data
        if (response.data.success) {
          this.$message.success('命令执行成功')
        } else {
          this.$message.warning('命令执行完成，但返回非零代码')
        }
      } catch (error) {
        console.error('执行命令失败:', error)
        this.$message.error('执行命令失败')
      }
      this.commandLoading = false
    },
    
    // 测试脚本相关方法
    async fetchTestScripts() {
      try {
        const response = await getTestScriptList()
        this.testScripts = response.data.scripts || []
      } catch (error) {
        console.error('获取测试脚本失败:', error)
        this.$message.error('获取测试脚本失败')
      }
    },
    
    async refreshTestScripts() {
      await this.fetchTestScripts()
      this.$message.success('测试脚本列表已刷新')
    },
    
    async runTestScriptAction() {
      if (!this.selectedTestScript) {
        this.$message.warning('请选择要运行的测试脚本')
        return
      }
      
      this.testLoading = true
      this.testResult = null
      
      try {
        const response = await runTestScript(this.selectedTestScript)
        if (response.data && response.data.request_id) {
          this.testResult = {
            success: true,
            message: '测试脚本已启动',
            description: `脚本 ${this.selectedTestScript} 运行中，请求ID: ${response.data.request_id}`
          }
          this.$message.success('测试脚本已启动')
        } else {
          throw new Error('无效的响应数据')
        }
      } catch (error) {
        console.error('运行测试脚本失败:', error)
        this.testResult = {
          success: false,
          message: '运行失败',
          description: error.response?.data?.message || error.message
        }
        this.$message.error('运行测试脚本失败')
      }
      
      this.testLoading = false
    },
    
    async quickTest() {
      this.testLoading = true
      this.testResult = null
      
      try {
        const response = await axios.post('http://localhost:8000/api/test/quick')
        if (response.data.code === 200) {
          this.testResult = {
            success: true,
            message: '快速测试已启动',
            description: response.data.data.tip || '请检查后台日志查看结果'
          }
          this.$message.success('快速测试已启动')
        } else {
          throw new Error(response.data.message || '快速测试失败')
        }
      } catch (error) {
        console.error('快速测试失败:', error)
        this.testResult = {
          success: false,
          message: '快速测试失败',
          description: error.response?.data?.message || error.message
        }
        this.$message.error('快速测试失败')
      }
      
      this.testLoading = false
    },
    
    async testOpenClaw() {
      this.testLoading = true
      this.testResult = null
      
      try {
        const response = await axios.post('http://localhost:8000/api/test/openclaw', {
          agent_id: 'hc-coding',
          message: 'AdminPro 控制面板测试消息 - ' + new Date().toLocaleString('zh-CN'),
          options: { timeout: 30 }
        })
        
        if (response.data.code === 200) {
          this.testResult = {
            success: true,
            message: 'OpenClaw 测试已启动',
            description: `已向 Agent ${response.data.data.agent_id} 发送测试消息，请求ID: ${response.data.data.request_id}`
          }
          this.$message.success('OpenClaw 测试已启动')
        } else {
          throw new Error(response.data.message || 'OpenClaw 测试失败')
        }
      } catch (error) {
        console.error('OpenClaw 测试失败:', error)
        this.testResult = {
          success: false,
          message: 'OpenClaw 测试失败',
          description: error.response?.data?.message || error.message
        }
        this.$message.error('OpenClaw 测试失败')
      }
      
      this.testLoading = false
    },
    
    // 处理任务状态变化（WebSocket 监听）
    handleTaskStatusChange(tasks) {
      // 仅在测试进行中时处理
      if (!this.testResult || this.testResult.success === false) return
      
      // 查找最近完成的任务
      const recentCompletedTasks = Object.values(tasks).filter(task => 
        task.status === 'success' || task.status === 'failed'
      ).sort((a, b) => b.lastUpdate - a.lastUpdate)
      
      if (recentCompletedTasks.length > 0) {
        const completedTask = recentCompletedTasks[0]
        // 只处理最近 30 秒内完成的任务
        if (Date.now() - completedTask.lastUpdate < 30000) {
          this.showTaskCompletionNotification(completedTask)
        }
      }
    },
    
    // 显示任务完成通知
    showTaskCompletionNotification(task) {
      const isSuccess = task.status === 'success'
      const title = isSuccess ? '测试脚本执行成功' : '测试脚本执行失败'
      const type = isSuccess ? 'success' : 'error'
      
      // 更新测试结果显示
      this.testResult = {
        success: isSuccess,
        message: title,
        description: `任务 ${task.taskId} 已${isSuccess ? '成功完成' : '执行失败'}。请查看日志获取详细信息。`
      }
      
      // 显示通知
      this.$notify({
        title,
        message: `任务 ${task.taskId.substring(0, 8)} 已${isSuccess ? '成功完成' : '执行失败'}`,
        type,
        duration: isSuccess ? 4000 : 6000,
        position: 'bottom-right'
      })
      
      // 如果有日志，显示日志内容
      const taskLogs = this.$store.state.realtime.taskLogs[task.taskId]
      if (taskLogs && taskLogs.length > 0) {
        const lastLog = taskLogs[taskLogs.length - 1]
        console.log('Task completed with logs:', lastLog)
        
        // 可以根据需要在这里处理日志显示
      }
    }
  }
}
</script>

<style scoped>
.openclaw-control {
  padding: 20px;
}

.status-cards {
  margin-bottom: 20px;
}

.status-card {
  cursor: default;
  min-height: 120px;
}

.status-card .el-card__body {
  display: flex;
  align-items: center;
  padding: 20px;
  height: 100px;
}

.status-icon {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: white;
  margin-right: 20px;
  flex-shrink: 0;
}

.status-content {
  flex: 1;
}

.status-value {
  font-size: 24px;
  font-weight: bold;
  color: #303133;
  line-height: 1;
  margin-bottom: 8px;
}

.status-label {
  font-size: 14px;
  color: #606266;
  margin-bottom: 4px;
}

.status-sublabel {
  font-size: 12px;
  color: #909399;
}
</style>