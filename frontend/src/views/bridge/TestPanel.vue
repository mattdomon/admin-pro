<template>
  <div class="bridge-test-panel">
    <el-card class="box-card">
      <div slot="header" class="clearfix">
        <span><i class="el-icon-connection"></i> Bridge WebSocket 测试面板</span>
        <el-button 
          style="float: right; padding: 3px 0" 
          type="text" 
          @click="refreshStatus"
          :loading="statusLoading"
        >
          刷新状态
        </el-button>
      </div>
      
      <!-- 连接状态 -->
      <div class="status-section">
        <h4>连接状态</h4>
        <el-tag 
          :type="connectionStatus.connected ? 'success' : 'danger'" 
          size="medium"
        >
          {{ connectionStatus.connected ? '已连接' : '未连接' }}
        </el-tag>
        <span class="status-info">
          待处理任务: {{ connectionStatus.pending_requests || 0 }}
        </span>
      </div>

      <!-- 快速测试 -->
      <div class="quick-test-section">
        <h4>快速测试</h4>
        <el-row :gutter="20">
          <el-col :span="8">
            <el-button 
              type="primary" 
              @click="runQuickTest"
              :loading="quickTestLoading"
              icon="el-icon-video-play"
              size="medium"
            >
              运行 Hello 脚本
            </el-button>
          </el-col>
          <el-col :span="8">
            <el-button 
              type="success" 
              @click="testOpenClaw"
              :loading="openclawTestLoading"
              icon="el-icon-chat-dot-round"
              size="medium"
            >
              测试 OpenClaw 调用
            </el-button>
          </el-col>
          <el-col :span="8">
            <el-button 
              type="warning" 
              @click="testAI"
              :loading="aiTestLoading"
              icon="el-icon-magic-stick"
              size="medium"
            >
              测试 AI 调用
            </el-button>
          </el-col>
        </el-row>
      </div>

      <!-- 脚本管理 -->
      <div class="script-section">
        <h4>可用脚本</h4>
        <el-table 
          :data="availableScripts" 
          style="width: 100%" 
          size="mini"
          v-loading="scriptsLoading"
        >
          <el-table-column prop="name" label="脚本名称" width="200"></el-table-column>
          <el-table-column prop="dir" label="目录" width="120"></el-table-column>
          <el-table-column prop="size" label="大小" width="80">
            <template slot-scope="scope">
              {{ formatFileSize(scope.row.size) }}
            </template>
          </el-table-column>
          <el-table-column prop="modified" label="修改时间"></el-table-column>
          <el-table-column label="操作" width="150">
            <template slot-scope="scope">
              <el-button 
                type="primary" 
                size="mini" 
                @click="runScript(scope.row)"
                :loading="scope.row.loading"
              >
                运行
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 自定义测试 -->
      <div class="custom-test-section">
        <h4>自定义测试</h4>
        <el-form :model="customForm" label-width="120px" size="small">
          <el-row :gutter="20">
            <el-col :span="12">
              <el-form-item label="脚本名称:">
                <el-input v-model="customForm.scriptName" placeholder="如: test_script.py"></el-input>
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="Agent ID:">
                <el-input v-model="customForm.agentId" placeholder="如: hc-coding"></el-input>
              </el-form-item>
            </el-col>
          </el-row>
          
          <el-form-item label="参数 JSON:">
            <el-input 
              type="textarea" 
              v-model="customForm.params" 
              placeholder='{"message": "自定义测试消息"}'
              rows="3"
            ></el-input>
          </el-form-item>
          
          <el-form-item label="OpenClaw 消息:">
            <el-input 
              type="textarea" 
              v-model="customForm.message" 
              placeholder="发送给 Agent 的测试消息"
              rows="2"
            ></el-input>
          </el-form-item>
          
          <el-form-item>
            <el-button 
              type="primary" 
              @click="runCustomScript"
              :loading="customScriptLoading"
            >
              运行自定义脚本
            </el-button>
            <el-button 
              type="success" 
              @click="sendCustomOpenClaw"
              :loading="customOpenClawLoading"
            >
              发送 OpenClaw 消息
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 执行历史 -->
      <div class="history-section">
        <h4>执行历史</h4>
        <el-timeline>
          <el-timeline-item 
            v-for="item in executionHistory" 
            :key="item.id"
            :timestamp="item.timestamp"
            :type="item.success ? 'success' : 'danger'"
          >
            <el-card class="history-card">
              <div>
                <strong>{{ item.type }}</strong> - {{ item.message }}
              </div>
              <div v-if="item.requestId" class="request-id">
                请求ID: {{ item.requestId }}
              </div>
              <div v-if="item.details" class="details">
                <el-button type="text" @click="item.showDetails = !item.showDetails">
                  {{ item.showDetails ? '隐藏' : '显示' }}详情
                </el-button>
                <div v-if="item.showDetails" class="details-content">
                  <pre>{{ JSON.stringify(item.details, null, 2) }}</pre>
                </div>
              </div>
            </el-card>
          </el-timeline-item>
        </el-timeline>
      </div>
    </el-card>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  name: 'BridgeTestPanel',
  data() {
    return {
      // 状态相关
      connectionStatus: {
        connected: false,
        pending_requests: 0,
        status: 'offline'
      },
      statusLoading: false,
      
      // 脚本相关
      availableScripts: [],
      scriptsLoading: false,
      
      // 加载状态
      quickTestLoading: false,
      openclawTestLoading: false,
      aiTestLoading: false,
      customScriptLoading: false,
      customOpenClawLoading: false,
      
      // 自定义表单
      customForm: {
        scriptName: 'test_script.py',
        agentId: 'hc-coding',
        params: '{"message": "自定义测试消息"}',
        message: '这是一条测试消息，用于验证 Bridge 服务通信'
      },
      
      // 执行历史
      executionHistory: []
    }
  },
  
  mounted() {
    this.refreshStatus()
    this.loadScripts()
    this.startStatusPolling()
  },
  
  beforeDestroy() {
    if (this.statusTimer) {
      clearInterval(this.statusTimer)
    }
  },
  
  methods: {
    // 刷新连接状态
    async refreshStatus() {
      this.statusLoading = true
      try {
        const response = await axios.get('/api/test/status')
        if (response.data.code === 200) {
          this.connectionStatus = response.data.data.connection
        }
      } catch (error) {
        this.$message.error('获取连接状态失败: ' + error.message)
      } finally {
        this.statusLoading = false
      }
    },
    
    // 加载可用脚本
    async loadScripts() {
      this.scriptsLoading = true
      try {
        const response = await axios.get('/api/test/scripts')
        if (response.data.code === 200) {
          this.availableScripts = response.data.data.scripts.map(script => ({
            ...script,
            loading: false
          }))
        }
      } catch (error) {
        this.$message.error('加载脚本列表失败: ' + error.message)
      } finally {
        this.scriptsLoading = false
      }
    },
    
    // 运行快速测试
    async runQuickTest() {
      this.quickTestLoading = true
      try {
        const response = await axios.post('/api/test/quick')
        this.handleResponse('快速测试', response)
      } catch (error) {
        this.addHistory('快速测试', false, '执行失败: ' + error.message)
      } finally {
        this.quickTestLoading = false
      }
    },
    
    // 测试 OpenClaw
    async testOpenClaw() {
      this.openclawTestLoading = true
      try {
        const response = await axios.post('/api/test/openclaw', {
          agent_id: 'hc-coding',
          message: '这是来自 AdminPro 的测试消息 - ' + new Date().toLocaleString(),
          options: { timeout: 60 }
        })
        this.handleResponse('OpenClaw测试', response)
      } catch (error) {
        this.addHistory('OpenClaw测试', false, '执行失败: ' + error.message)
      } finally {
        this.openclawTestLoading = false
      }
    },
    
    // 测试 AI
    async testAI() {
      this.aiTestLoading = true
      try {
        const response = await axios.post('/api/test/ai', {
          provider: 'openai',
          model: 'gpt-3.5-turbo',
          prompt: 'Hello from AdminPro Bridge Test! Please respond with current time.',
          options: { max_tokens: 100 }
        })
        this.handleResponse('AI测试', response)
      } catch (error) {
        this.addHistory('AI测试', false, '执行失败: ' + error.message)
      } finally {
        this.aiTestLoading = false
      }
    },
    
    // 运行指定脚本
    async runScript(script) {
      this.$set(script, 'loading', true)
      try {
        const response = await axios.post('/api/test/run-script', {
          script_name: script.name,
          params: { 
            message: `运行脚本 ${script.name} - ${new Date().toLocaleString()}`,
            source: 'AdminPro-UI'
          }
        })
        this.handleResponse(`运行脚本: ${script.name}`, response)
      } catch (error) {
        this.addHistory(`运行脚本: ${script.name}`, false, '执行失败: ' + error.message)
      } finally {
        this.$set(script, 'loading', false)
      }
    },
    
    // 运行自定义脚本
    async runCustomScript() {
      this.customScriptLoading = true
      try {
        let params = {}
        if (this.customForm.params) {
          try {
            params = JSON.parse(this.customForm.params)
          } catch (e) {
            throw new Error('参数 JSON 格式错误')
          }
        }
        
        const response = await axios.post('/api/test/run-script', {
          script_name: this.customForm.scriptName,
          params: params
        })
        this.handleResponse(`自定义脚本: ${this.customForm.scriptName}`, response)
      } catch (error) {
        this.addHistory(`自定义脚本: ${this.customForm.scriptName}`, false, '执行失败: ' + error.message)
      } finally {
        this.customScriptLoading = false
      }
    },
    
    // 发送自定义 OpenClaw 消息
    async sendCustomOpenClaw() {
      this.customOpenClawLoading = true
      try {
        const response = await axios.post('/api/test/openclaw', {
          agent_id: this.customForm.agentId,
          message: this.customForm.message,
          options: { timeout: 60 }
        })
        this.handleResponse(`OpenClaw: ${this.customForm.agentId}`, response)
      } catch (error) {
        this.addHistory(`OpenClaw: ${this.customForm.agentId}`, false, '执行失败: ' + error.message)
      } finally {
        this.customOpenClawLoading = false
      }
    },
    
    // 处理响应
    handleResponse(type, response) {
      const data = response.data
      if (data.code === 200) {
        this.$message.success(data.message)
        this.addHistory(type, true, data.message, data.data?.request_id, data.data)
      } else {
        this.$message.error(data.message || '执行失败')
        this.addHistory(type, false, data.message || '执行失败', null, data)
      }
    },
    
    // 添加历史记录
    addHistory(type, success, message, requestId = null, details = null) {
      this.executionHistory.unshift({
        id: Date.now(),
        type: type,
        success: success,
        message: message,
        requestId: requestId,
        timestamp: new Date().toLocaleString(),
        details: details,
        showDetails: false
      })
      
      // 只保留最近 20 条记录
      if (this.executionHistory.length > 20) {
        this.executionHistory = this.executionHistory.slice(0, 20)
      }
    },
    
    // 格式化文件大小
    formatFileSize(bytes) {
      if (bytes === 0) return '0 B'
      const k = 1024
      const sizes = ['B', 'KB', 'MB', 'GB']
      const i = Math.floor(Math.log(bytes) / Math.log(k))
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
    },
    
    // 开始状态轮询
    startStatusPolling() {
      this.statusTimer = setInterval(() => {
        this.refreshStatus()
      }, 10000) // 每10秒刷新一次状态
    }
  }
}
</script>

<style scoped>
.bridge-test-panel {
  padding: 20px;
}

.status-section, .quick-test-section, .script-section, 
.custom-test-section, .history-section {
  margin-bottom: 30px;
}

.status-section h4, .quick-test-section h4, 
.script-section h4, .custom-test-section h4, 
.history-section h4 {
  margin-bottom: 15px;
  color: #409EFF;
  border-bottom: 2px solid #409EFF;
  padding-bottom: 5px;
}

.status-info {
  margin-left: 15px;
  color: #666;
  font-size: 14px;
}

.history-card {
  margin-bottom: 10px;
}

.request-id {
  color: #666;
  font-size: 12px;
  margin-top: 5px;
}

.details {
  margin-top: 10px;
}

.details-content {
  margin-top: 10px;
  background: #f5f5f5;
  padding: 10px;
  border-radius: 4px;
  max-height: 200px;
  overflow-y: auto;
}

.details-content pre {
  margin: 0;
  font-size: 12px;
  white-space: pre-wrap;
  word-wrap: break-word;
}

.el-timeline {
  max-height: 400px;
  overflow-y: auto;
}
</style>