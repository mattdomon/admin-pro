<template>
  <div class="ai-chat">
    <el-container style="height: 100%;">
      <!-- 左侧模型选择 -->
      <el-aside width="250px" style="background: #f5f5f5; padding: 15px;">
        <h4 style="margin-top: 0;">AI模型</h4>
        <el-radio-group v-model="selectedModelId" style="width: 100%;" @change="handleModelChange">
          <div v-if="defaultModel" class="model-item">
            <el-radio :label="defaultModel.id">
              <span class="model-name">{{ defaultModel.name }}</span>
              <el-tag v-if="defaultModel.is_default" type="warning" size="mini">默认</el-tag>
            </el-radio>
          </div>
          <div v-if="backupModel" class="model-item">
            <el-radio :label="backupModel.id">
              <span class="model-name">{{ backupModel.name }}</span>
              <el-tag v-if="backupModel.is_backup" size="mini">备用</el-tag>
            </el-radio>
          </div>
          <el-divider></el-divider>
          <div v-for="model in otherModels" :key="model.id" class="model-item">
            <el-radio :label="model.id">
              <span class="model-name">{{ model.name }}</span>
            </el-radio>
          </div>
        </el-radio-group>
        
        <el-empty v-if="models.length === 0" description="暂无模型配置"></el-empty>
        
        <el-button 
          type="text" 
          style="margin-top: 15px; width: 100%;" 
          @click="$router.push('/ai/models')">
          <i class="el-icon-setting"></i> 管理模型
        </el-button>
      </el-aside>
      
      <!-- 右侧聊天区域 -->
      <el-main style="padding: 0; display: flex; flex-direction: column;">
        <!-- 消息列表 -->
        <div class="chat-messages" ref="messagesContainer">
          <div v-if="messages.length === 0" class="empty-hint">
            <el-empty description="开始对话吧">
              <el-button type="primary" size="small" @click="showExamples = true">查看示例</el-button>
            </el-empty>
          </div>
          
          <div 
            v-for="(msg, index) in messages" 
            :key="index" 
            :class="['message-item', msg.role]">
            <div class="message-avatar">
              <span v-if="msg.role === 'user'">👤</span>
              <span v-else>🤖</span>
            </div>
            <div class="message-content">
              <div class="message-text" v-html="formatMessage(msg.content)"></div>
              <div class="message-time">{{ msg.time }}</div>
            </div>
          </div>
          
          <div v-if="streaming" class="message-item assistant">
            <div class="message-avatar">🤖</div>
            <div class="message-content">
              <div class="message-text">
                <span class="streaming-text">{{ streamingText }}</span>
                <span class="cursor-blink">▊</span>
              </div>
            </div>
          </div>
        </div>
        
        <!-- 输入区域 -->
        <div class="chat-input-area">
          <div class="chat-input-wrapper">
            <el-input
              v-model="inputText"
              type="textarea"
              :rows="2"
              placeholder="输入消息... (Shift+Enter换行，Enter发送)"
              @keydown.enter.exact.prevent="handleSend"
              @keydown.enter.shift.exact="breakLine"
              resize="none"
            ></el-input>
            <el-button 
              type="primary" 
              :loading="streaming"
              @click="handleSend"
              style="margin-left: 10px;">
              发送
            </el-button>
          </div>
          <div class="chat-options">
            <el-checkbox v-model="streamMode">流式输出</el-checkbox>
            <el-button type="text" size="small" @click="clearHistory">清空对话</el-button>
          </div>
        </div>
      </el-main>
    </el-container>
  </div>
</template>

<script>
import { listModels, chatStream } from '@/api/ai'

export default {
  name: 'AIChat',
  data() {
    return {
      models: [],
      selectedModelId: null,
      messages: [],
      inputText: '',
      streaming: false,
      streamingText: '',
      streamMode: true,
      currentController: null
    }
  },
  computed: {
    defaultModel() {
      return this.models.find(m => m.isDefault) || this.models.find(m => m.is_default)
    },
    backupModel() {
      return this.models.find(m => m.is_backup)
    },
    otherModels() {
      return this.models.filter(m => !(m.isDefault || m.is_default) && !m.is_backup)
    }
  },
  mounted() {
    this.fetchModels()
  },
  beforeDestroy() {
    if (this.currentController) {
      this.currentController.abort()
    }
  },
  methods: {
    async fetchModels() {
      try {
        const res = await listModels()
        const models = res.data?.models || []
        this.primaryModel = res.data?.primaryModel || ''
        
        // 标准化模型数据结构
        this.models = models.map((m, idx) => ({
          ...m,
          id: m.id || String(idx),  // 兼容 numeric ID
          is_default: m.isDefault || false,
          is_backup: m.is_backup || false,
        }))
        
        // 自动选择默认模型
        if (this.defaultModel) {
          this.selectedModelId = this.defaultModel.id
        } else if (this.models.length > 0) {
          this.selectedModelId = this.models[0].id
        }
      } catch (e) {
        console.error('获取模型列表失败:', e)
      }
    },
    handleModelChange(val) {
      this.selectedModelId = val
    },
    handleSend() {
      if (!this.inputText.trim()) return
      if (!this.selectedModelId && this.models.length > 0) {
        this.$message.warning('请先选择一个模型')
        return
      }
      
      const userMessage = {
        role: 'user',
        content: this.inputText.trim(),
        time: this.formatTime(new Date())
      }
      this.messages.push(userMessage)
      this.inputText = ''
      this.scrollToBottom()
      
      this.sendToAI(userMessage)
    },
    breakLine() {
      // Shift+Enter 的默认行为是换行
    },
    async sendToAI(userMessage) {
      const model = this.models.find(m => m.id === this.selectedModelId)
      
      if (!model) {
        this.$message.error('未找到选择的模型')
        return
      }
      
      // 准备消息历史
      const messages = this.messages.map(m => ({
        role: m.role,
        content: m.content
      }))
      
      this.streaming = true
      this.streamingText = ''
      
      if (this.streamMode) {
        await this.streamChat(model, messages)
      } else {
        await this.normalChat(model, messages)
      }
    },
    async streamChat(model, messages) {
      this.currentController = new AbortController()
      
      try {
        const token = localStorage.getItem('token')
        const baseURL = import.meta.env?.VITE_API_URL || import.meta.env?.DEV ? '/api' : ''
        
        const response = await fetch(`${baseURL}/ai/chat`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          },
          body: JSON.stringify({
            model_path: model.modelPath || `${model.provider}/${model.modelId}`,
            messages: messages,
            stream: true
          }),
          signal: this.currentController.signal
        })
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }
        
        const reader = response.body.getReader()
        const decoder = new TextDecoder()
        let fullContent = ''
        
        while (true) {
          const { done, value } = await reader.read()
          if (done) break
          
          const chunk = decoder.decode(value)
          const lines = chunk.split('\n')
          
          for (const line of lines) {
            if (line.startsWith('data: ')) {
              const data = line.slice(6)
              if (data === '[DONE]') continue
              
              try {
                const parsed = JSON.parse(data)
                const content = this.extractContent(parsed)
                if (content) {
                  fullContent += content
                  this.streamingText = fullContent
                  this.scrollToBottom()
                }
              } catch (e) {
                // 忽略解析错误
              }
            }
          }
        }
        
        this.messages.push({
          role: 'assistant',
          content: fullContent,
          time: this.formatTime(new Date())
        })
      } catch (e) {
        if (e.name === 'AbortError') {
          console.log('请求已取消')
        } else {
          console.error('流式请求失败:', e)
          this.$message.error('请求失败: ' + e.message)
        }
      } finally {
        this.streaming = false
        this.streamingText = ''
        this.currentController = null
        this.scrollToBottom()
      }
    },
    extractContent(data) {
      // OpenAI 格式
      if (data.choices && data.choices[0] && data.choices[0].delta) {
        return data.choices[0].delta.content || ''
      }
      // Anthropic 格式
      if (data.type === 'content_block_delta') {
        return data.delta?.text || ''
      }
      return ''
    },
    async normalChat(model, messages) {
      try {
        const token = localStorage.getItem('token')
        const baseURL = import.meta.env?.VITE_API_URL || import.meta.env?.DEV ? '/api' : ''
        
        const response = await fetch(`${baseURL}/ai/chat`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': token ? `Bearer ${token}` : ''
          },
          body: JSON.stringify({
            model_path: model.modelPath || `${model.provider}/${model.modelId}`,
            messages: messages,
            stream: false
          })
        })
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }
        
        const res = await response.json()
        if (res.code === 200) {
          const content = this.extractNormalContent(res.data)
          this.messages.push({
            role: 'assistant',
            content: content,
            time: this.formatTime(new Date())
          })
        } else {
          throw new Error(res.message || '请求失败')
        }
      } catch (e) {
        console.error('请求失败:', e)
        this.$message.error('请求失败: ' + e.message)
      } finally {
        this.streaming = false
        this.scrollToBottom()
      }
    },
    extractNormalContent(data) {
      // OpenAI 格式
      if (data.choices && data.choices[0]) {
        return data.choices[0].message?.content || ''
      }
      // Anthropic 格式
      if (data.content) {
        if (Array.isArray(data.content)) {
          return data.content.map(c => c.text || '').join('')
        }
        return data.content
      }
      return ''
    },
    formatMessage(content) {
      if (!content) return ''
      // 简单处理代码块
      return content
        .replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\n/g, '<br>')
    },
    formatTime(date) {
      return date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.messagesContainer
        if (container) {
          container.scrollTop = container.scrollHeight
        }
      })
    },
    clearHistory() {
      this.$confirm('确认清空对话历史？', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(() => {
        this.messages = []
      }).catch(() => {})
    }
  }
}
</script>

<style scoped>
.ai-chat {
  height: calc(100vh - 60px);
}
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  background: #fafafa;
}
.empty-hint {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100%;
}
.message-item {
  display: flex;
  margin-bottom: 20px;
}
.message-item.user {
  flex-direction: row-reverse;
}
.message-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #e0e0e0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}
.message-item.assistant .message-avatar {
  background: #409EFF;
  color: #fff;
}
.message-item.user .message-avatar {
  background: #67C23A;
  color: #fff;
}
.message-content {
  max-width: 70%;
  margin: 0 12px;
}
.message-text {
  padding: 12px 16px;
  border-radius: 8px;
  line-height: 1.6;
  word-break: break-word;
}
.message-item.user .message-text {
  background: #67C23A;
  color: #fff;
}
.message-item.assistant .message-text {
  background: #fff;
  border: 1px solid #e0e0e0;
}
.message-time {
  font-size: 12px;
  color: #999;
  margin-top: 5px;
}
.message-item.user .message-time {
  text-align: right;
}
.cursor-blink {
  animation: blink 1s infinite;
}
@keyframes blink {
  0%, 50% { opacity: 1; }
  51%, 100% { opacity: 0; }
}
.chat-input-area {
  padding: 15px 20px;
  background: #fff;
  border-top: 1px solid #e0e0e0;
}
.chat-input-wrapper {
  display: flex;
  align-items: flex-end;
}
.chat-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 10px;
}
.model-item {
  padding: 8px 0;
}
.model-name {
  margin-right: 8px;
}
pre {
  background: #f5f5f5;
  padding: 10px;
  border-radius: 4px;
  overflow-x: auto;
}
code {
  background: #f5f5f5;
  padding: 2px 5px;
  border-radius: 3px;
  font-family: Consolas, Monaco, monospace;
}
</style>
