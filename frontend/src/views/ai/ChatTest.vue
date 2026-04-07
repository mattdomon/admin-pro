<template>
  <div class="ai-chat-test">
    <el-card class="box-card">
      <div slot="header">
        <span><i class="el-icon-magic-stick"></i> 节点 + 模型 AI 会话测试</span>
        <el-button 
          style="float: right;" 
          type="text" 
          @click="clearChat"
          :disabled="isStreaming"
        >
          清空对话
        </el-button>
      </div>

      <!-- 配置区域 -->
      <div class="config-section">
        <el-row :gutter="20">
          <el-col :span="8">
            <el-form-item label="执行节点">
              <el-select 
                v-model="config.nodeKey"
                placeholder="选择节点"
                size="small"
                style="width: 100%"
              >
                <el-option
                  v-for="node in onlineNodes"
                  :key="node.node_key"
                  :label="`${node.node_name} (${node.status})`"
                  :value="node.node_key"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="模型路径">
              <el-input
                v-model="config.modelPath"
                placeholder="如: openai/gpt-3.5-turbo"
                size="small"
              />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="API配置">
              <el-input
                v-model="config.apiKey"
                placeholder="API Key"
                size="small"
                type="password"
                show-password
              />
            </el-form-item>
          </el-col>
        </el-row>
      </div>

      <!-- 聊天区域 -->
      <div class="chat-container">
        <div class="chat-messages" ref="chatMessages">
          <div 
            v-for="(message, index) in messages" 
            :key="index"
            :class="['message', message.role]"
          >
            <div class="message-header">
              <span class="role">{{ message.role === 'user' ? '🧑 用户' : '🤖 AI' }}</span>
              <span class="timestamp">{{ message.timestamp }}</span>
            </div>
            <div class="message-content">
              <pre v-if="message.content">{{ message.content }}</pre>
              <div v-if="message.streaming" class="streaming-indicator">
                <i class="el-icon-loading"></i> 正在输出...
              </div>
            </div>
          </div>
        </div>

        <!-- 输入区域 -->
        <div class="chat-input">
          <el-input
            v-model="userMessage"
            type="textarea"
            :rows="3"
            placeholder="输入你的问题..."
            :disabled="isStreaming"
            @keydown.ctrl.enter="sendMessage"
          />
          <div class="input-actions">
            <el-button 
              type="primary" 
              @click="sendMessage"
              :loading="isStreaming"
              :disabled="!userMessage.trim() || !config.nodeKey || !config.modelPath"
            >
              发送 (Ctrl+Enter)
            </el-button>
            <el-button 
              v-if="isStreaming"
              type="warning"
              @click="stopStreaming"
            >
              停止输出
            </el-button>
          </div>
        </div>
      </div>

      <!-- 状态信息 -->
      <div class="status-info">
        <el-tag 
          :type="wsConnected ? 'success' : 'danger'" 
          size="mini"
        >
          WebSocket: {{ wsConnected ? '已连接' : '未连接' }}
        </el-tag>
        <el-tag 
          v-if="currentTaskId"
          type="info" 
          size="mini"
        >
          任务ID: {{ currentTaskId.slice(-8) }}
        </el-tag>
        <el-tag 
          v-if="streamingStats.chunks > 0"
          type="success" 
          size="mini"
        >
          已接收: {{ streamingStats.chunks }} 块 / {{ streamingStats.chars }} 字符
        </el-tag>
      </div>
    </el-card>
  </div>
</template>

<script>
export default {
  name: 'AiChatTest',
  
  data() {
    return {
      // 配置
      config: {
        nodeKey: '',
        modelPath: 'openai/gpt-3.5-turbo',
        apiKey: '',
        baseUrl: 'https://api.openai.com'
      },
      
      // 聊天数据
      messages: [],
      userMessage: '',
      isStreaming: false,
      currentTaskId: null,
      
      // WebSocket 状态
      wsConnected: false,
      
      // 流式输出统计
      streamingStats: {
        chunks: 0,
        chars: 0
      },
      
      // 节点列表
      onlineNodes: []
    }
  },
  
  mounted() {
    this.initWebSocket()
    this.loadNodes()
  },
  
  beforeDestroy() {
    this.cleanupWebSocket()
  },
  
  methods: {
    // ===== WebSocket 相关 =====
    initWebSocket() {
      try {
        // 连接任务通知器
        this.$connectTaskNotifier('ws://localhost:8282')
        
        // 监听连接状态
        this.$taskNotifier.on('connected', () => {
          this.wsConnected = true
          console.log('🔗 WebSocket 连接成功')
        })
        
        this.$taskNotifier.on('disconnected', () => {
          this.wsConnected = false
          console.log('🔌 WebSocket 连接断开')
        })
        
        // 🚀 核心：监听流式聊天数据
        this.$taskNotifier.on('chat_stream', this.handleChatStream)
        
        // 监听任务完成
        this.$taskNotifier.on('task_success', this.handleTaskComplete)
        this.$taskNotifier.on('task_failed', this.handleTaskError)
        
      } catch (e) {
        console.error('WebSocket 初始化失败:', e)
      }
    },
    
    cleanupWebSocket() {
      if (this.$taskNotifier) {
        this.$taskNotifier.off('chat_stream', this.handleChatStream)
        this.$taskNotifier.off('task_success', this.handleTaskComplete)
        this.$taskNotifier.off('task_failed', this.handleTaskError)
        this.$disconnectTaskNotifier()
      }
    },
    
    // ===== 流式数据处理 =====
    handleChatStream(data) {
      const { task_id, content, status } = data
      
      // 只处理当前任务的流数据
      if (task_id !== this.currentTaskId) {
        return
      }
      
      if (status === 'processing' && content) {
        // 增量内容：添加到最后一条AI消息
        const lastMessage = this.messages[this.messages.length - 1]
        if (lastMessage && lastMessage.role === 'assistant') {
          lastMessage.content += content
          lastMessage.streaming = true
          
          // 更新统计
          this.streamingStats.chunks++
          this.streamingStats.chars += content.length
          
          // 自动滚动到底部
          this.$nextTick(() => {
            this.scrollToBottom()
          })
        }
        
        console.log(`📡 收到流数据: ${content}`)
        
      } else if (status === 'completed') {
        // 流式输出完成
        const lastMessage = this.messages[this.messages.length - 1]
        if (lastMessage && lastMessage.role === 'assistant') {
          lastMessage.streaming = false
        }
        
        this.isStreaming = false
        this.currentTaskId = null
        
        console.log('✅ 流式输出完成')
        this.$message.success('AI 回答完成')
      }
    },
    
    handleTaskComplete(data) {
      if (data.task_id === this.currentTaskId) {
        this.isStreaming = false
        this.currentTaskId = null
      }
    },
    
    handleTaskError(data) {
      if (data.task_id === this.currentTaskId) {
        this.isStreaming = false
        this.currentTaskId = null
        
        this.$message.error(`AI 任务失败: ${data.error}`)
        
        // 添加错误消息
        this.messages.push({
          role: 'assistant',
          content: `❌ 错误: ${data.error}`,
          timestamp: new Date().toLocaleTimeString(),
          streaming: false
        })
      }
    },
    
    // ===== 聊天功能 =====
    async sendMessage() {
      if (!this.userMessage.trim() || this.isStreaming) {
        return
      }
      
      const message = this.userMessage.trim()
      this.userMessage = ''
      
      // 添加用户消息
      this.messages.push({
        role: 'user',
        content: message,
        timestamp: new Date().toLocaleTimeString(),
        streaming: false
      })
      
      // 添加占位AI消息
      this.messages.push({
        role: 'assistant',
        content: '',
        timestamp: new Date().toLocaleTimeString(),
        streaming: true
      })
      
      // 发送AI任务
      this.isStreaming = true
      this.streamingStats = { chunks: 0, chars: 0 }
      
      try {
        // 构建消息历史
        const chatHistory = this.messages
          .filter(m => !m.streaming && m.content)
          .map(m => ({
            role: m.role === 'assistant' ? 'assistant' : 'user',
            content: m.content
          }))
        
        const payload = {
          type: 'ai',
          target_node_key: this.config.nodeKey,
          payload: {
            model_path: this.config.modelPath,
            messages: chatHistory,
            base_url: this.config.baseUrl,
            api_key: this.config.apiKey,
            api_type: 'openai'
          }
        }
        
        const response = await this.$axios.post('/api/openclaw/bridge/task', payload)
        
        if (response.data.code === 200) {
          this.currentTaskId = response.data.data.task_id
          console.log(`🚀 AI任务已提交: ${this.currentTaskId}`)
        } else {
          throw new Error(response.data.message || 'AI任务提交失败')
        }
        
      } catch (error) {
        this.isStreaming = false
        this.currentTaskId = null
        
        this.$message.error('发送失败: ' + error.message)
        
        // 移除占位消息
        this.messages.pop()
      }
      
      this.$nextTick(() => {
        this.scrollToBottom()
      })
    },
    
    stopStreaming() {
      // TODO: 实现停止流式输出的逻辑
      this.isStreaming = false
      this.currentTaskId = null
      
      const lastMessage = this.messages[this.messages.length - 1]
      if (lastMessage && lastMessage.streaming) {
        lastMessage.streaming = false
        lastMessage.content += '\\n\\n[用户手动停止]'
      }
    },
    
    clearChat() {
      this.messages = []
      this.streamingStats = { chunks: 0, chars: 0 }
    },
    
    scrollToBottom() {
      const container = this.$refs.chatMessages
      if (container) {
        container.scrollTop = container.scrollHeight
      }
    },
    
    // ===== 节点管理 =====
    async loadNodes() {
      try {
        const response = await this.$axios.get('/api/nodes')
        if (response.data.code === 200) {
          this.onlineNodes = response.data.data.filter(node => node.status === 'online')
          
          // 自动选择第一个在线节点
          if (this.onlineNodes.length > 0 && !this.config.nodeKey) {
            this.config.nodeKey = this.onlineNodes[0].node_key
          }
        }
      } catch (error) {
        console.error('加载节点失败:', error)
      }
    }
  }
}
</script>

<style scoped>
.ai-chat-test {
  padding: 20px;
}

.config-section {
  margin-bottom: 20px;
  padding: 15px;
  background: #f8f9fa;
  border-radius: 8px;
}

.chat-container {
  margin-bottom: 15px;
}

.chat-messages {
  height: 400px;
  overflow-y: auto;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  padding: 15px;
  background: #fafafa;
  margin-bottom: 15px;
}

.message {
  margin-bottom: 20px;
}

.message.user {
  text-align: right;
}

.message.assistant {
  text-align: left;
}

.message-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 5px;
  font-size: 12px;
  color: #666;
}

.message.user .message-header {
  flex-direction: row-reverse;
}

.role {
  font-weight: bold;
}

.message-content {
  background: white;
  padding: 10px 15px;
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  display: inline-block;
  max-width: 80%;
}

.message.user .message-content {
  background: #409eff;
  color: white;
}

.message-content pre {
  margin: 0;
  white-space: pre-wrap;
  word-wrap: break-word;
  font-family: inherit;
}

.streaming-indicator {
  color: #409eff;
  font-style: italic;
  margin-top: 5px;
}

.chat-input {
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  padding: 15px;
}

.input-actions {
  margin-top: 10px;
  text-align: right;
}

.status-info {
  padding-top: 10px;
  border-top: 1px solid #ebeef5;
}

.status-info .el-tag {
  margin-right: 8px;
}
</style>