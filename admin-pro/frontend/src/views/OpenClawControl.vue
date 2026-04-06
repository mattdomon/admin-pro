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
      
      <!-- 模型管理 -->
      <el-col :span="12">
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
      commandLoading: false
    }
  },
  mounted() {
    this.fetchSystemStatus()
    this.fetchModels()
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