<template>
  <div class="model-management">
    <el-card>
      <div slot="header" class="clearfix">
        <span>OpenClaw 模型管理</span>
        <div style="float: right;">
          <span v-if="primaryModel" class="model-status primary">主模型: {{ getModelDisplayName(primaryModel) }}</span>
          <span v-if="fallbackModel" class="model-status fallback">备用: {{ getModelDisplayName(fallbackModel) }}</span>
          <el-button type="primary" size="small" @click="openDialog()" style="margin-left: 10px;">添加模型</el-button>
        </div>
      </div>
      
      <el-alert 
        v-if="errorMsg" 
        :title="errorMsg" 
        type="error" 
        show-icon 
        @close="errorMsg = ''" 
        style="margin-bottom: 15px;">
      </el-alert>
      
      <el-table :data="models" border style="width: 100%" v-loading="loading">
        <el-table-column label="提供商" width="120">
          <template slot-scope="scope">
            <el-tag :type="getProviderType(scope.row.provider)" size="small">
              {{ scope.row.provider }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="模型名称" width="200">
          <template slot-scope="scope">
            <div>
              <span>{{ scope.row.name }}</span>
              <el-tag v-if="scope.row.isPrimary" type="warning" size="mini" style="margin-left: 6px;">主模型</el-tag>
              <el-tag v-if="scope.row.isFallback" type="info" size="mini" style="margin-left: 6px;">备用</el-tag>
            </div>
            <div style="font-size: 12px; color: #909399;">{{ scope.row.modelId }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="baseUrl" label="Base URL" min-width="200" show-overflow-tooltip></el-table-column>
        <el-table-column label="上下文" width="90">
          <template slot-scope="scope">
            <span v-if="scope.row.contextWindow">{{ Math.round(scope.row.contextWindow / 1024) }}K</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="380" fixed="right">
          <template slot-scope="scope">
            <el-button size="mini" type="text" @click="openDialog(scope.row)">编辑</el-button>
            <el-button size="mini" type="text" @click="handleTest(scope.row)">🔗测试</el-button>
            <el-button size="mini" type="text" @click="openChatModal(scope.row)">💬对话</el-button>
            <el-button 
              v-if="!scope.row.isPrimary"
              size="mini" 
              type="text" 
              @click="handleSetPrimary(scope.row)">设为主模型</el-button>
            <el-button 
              v-if="!scope.row.isFallback && !scope.row.isPrimary"
              size="mini" 
              type="text" 
              @click="handleSetFallback(scope.row)">设为备用</el-button>
            <el-button 
              v-if="scope.row.isFallback"
              size="mini" 
              type="text" 
              style="color: #909399;"
              @click="handleRemoveFallback(scope.row)">取消备用</el-button>
            <el-button size="mini" type="text" style="color: #F56C6C;" @click="handleDelete(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
    
    <!-- 添加/编辑对话框 -->
    <el-dialog :title="dialogTitle" :visible.sync="dialogVisible" width="600px" @close="resetForm">
      <el-form :model="form" :rules="rules" ref="form" label-width="100px">
        <el-form-item label="提供商" prop="provider">
          <el-select v-model="form.provider" placeholder="选择提供商" style="width: 100%;" @change="onProviderChange">
            <el-option label="claudeagent (ClaudeAgent)" value="claudeagent"></el-option>
            <el-option label="minimax (MiniMax)" value="minimax"></el-option>
            <el-option label="openrouter (OpenRouter)" value="openrouter"></el-option>
            <el-option label="google (Google Gemini)" value="google"></el-option>
            <el-option label="anthropic (Anthropic)" value="anthropic"></el-option>
            <el-option label="openai (OpenAI)" value="openai"></el-option>
            <el-option label="ollama (Ollama)" value="ollama"></el-option>
            <el-option label="custom (自定义)" value="custom"></el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="模型ID" prop="modelId">
          <el-input v-model="form.modelId" placeholder="如：gpt-4, claude-3-sonnet-20240307"></el-input>
        </el-form-item>
        <el-form-item label="显示名称" prop="name">
          <el-input v-model="form.name" placeholder="如：GPT-4 Turbo"></el-input>
        </el-form-item>
        <el-form-item label="Base URL" prop="baseUrl">
          <el-input v-model="form.baseUrl" placeholder="如：https://api.openai.com/v1"></el-input>
        </el-form-item>
        <el-form-item label="API Key" prop="apiKey">
          <el-input v-model="form.apiKey" placeholder="API密钥" show-password></el-input>
        </el-form-item>
        <el-form-item label="API 类型" prop="api">
          <el-select v-model="form.api" style="width: 100%;">
            <el-option label="OpenAI 兼容" value="openai-completions"></el-option>
            <el-option label="Anthropic" value="anthropic"></el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="上下文窗口">
          <el-input-number v-model="form.contextWindow" :min="0" :step="1024" placeholder="可选"></el-input-number>
          <span style="color: #909399; font-size: 12px; margin-left: 10px;">tokens</span>
        </el-form-item>
        <el-form-item label="最大输出">
          <el-input-number v-model="form.maxTokens" :min="0" :step="1024" placeholder="可选"></el-input-number>
        </el-form-item>
        <el-form-item label="模型角色">
          <el-checkbox-group v-model="modelRoles">
            <el-checkbox label="primary">主模型</el-checkbox>
            <el-checkbox label="fallback">备用模型</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
      </el-form>
      <span slot="footer" class="dialog-footer">
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </span>
    </el-dialog>
    
    <!-- 测试结果对话框 -->
    <el-dialog title="🔗连通性测试结果" :visible.sync="testDialogVisible" width="400px">
      <el-alert :title="testResult.text" :type="testResult.ok ? 'success' : 'error'" show-icon :closable="false">
      </el-alert>
      <span slot="footer">
        <el-button type="primary" @click="testDialogVisible = false">确定</el-button>
      </span>
    </el-dialog>
    
    <!-- 对话测试对话框 -->
    <el-dialog title="💬对话测试" :visible.sync="chatDialogVisible" width="600px" :close-on-click-modal="false">
      <div v-if="chatModel">
        <p style="color: #909399; margin-bottom: 10px;">模型: {{ chatModel.name }} ({{ chatModel.modelId }})</p>
        <div class="chat-messages" ref="chatMessages">
          <div v-for="(msg, i) in chatMessages" :key="i" :class="['chat-msg', msg.role]">
            <div class="chat-bubble">{{ msg.content }}</div>
          </div>
          <div v-if="chatLoading" class="chat-msg assistant">
            <div class="chat-bubble"><i class="el-icon-loading"></i> 思考中...</div>
          </div>
        </div>
        <div class="chat-input">
          <el-input 
            v-model="chatInput" 
            placeholder="输入消息..." 
            @keyup.enter.native="sendChatMessage"
            :disabled="chatLoading"></el-input>
          <el-button type="primary" @click="sendChatMessage" :loading="chatLoading" style="margin-top: 10px;">发送</el-button>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import request from '@/utils/request'

export default {
  name: 'OpenClawModelManagement',
  data() {
    return {
      models: [],
      primaryModel: '',
      fallbackModel: '',
      loading: false,
      saving: false,
      errorMsg: '',
      
      // 对话框
      dialogVisible: false,
      dialogTitle: '添加模型',
      isEdit: false,
      
      // 模型角色
      modelRoles: [],
      
      // 表单
      form: {
        modelId: '',
        name: '',
        provider: '',
        baseUrl: '',
        apiKey: '',
        api: 'openai-completions',
        contextWindow: null,
        maxTokens: null,
      },
      // 验证规则
      rules: {
        provider: [{ required: true, message: '请选择提供商', trigger: 'change' }],
        modelId: [{ required: true, message: '请输入模型ID', trigger: 'blur' }],
        name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
        baseUrl: [{ required: true, message: '请输入Base URL', trigger: 'blur' }]
      },
      
      // 连通性测试
      testDialogVisible: false,
      testResult: { text: '', ok: false },
      
      // 对话测试
      chatDialogVisible: false,
      chatModel: null,
      chatMessages: [],
      chatInput: '',
      chatLoading: false,
    }
  },
  mounted() {
    this.fetchModels()
  },
  methods: {
    async fetchModels() {
      this.loading = true
      this.errorMsg = ''
      try {
        const res = await request({ url: '/api/openclaw/config/models', method: 'get' })
        this.models = res.data?.models || []
        this.primaryModel = res.data?.primaryModel || ''
        this.fallbackModel = res.data?.fallbackModel || ''
        
        // 标记主模型和备用模型
        this.models.forEach(model => {
          model.isPrimary = (this.primaryModel === model.modelPath)
          model.isFallback = (this.fallbackModel === model.modelPath)
        })
      } catch (e) {
        this.errorMsg = '获取模型列表失败: ' + (e.message || '未知错误')
      } finally {
        this.loading = false
      }
    },
    
    onProviderChange(provider) {
      const defaultBaseUrls = {
        'claudeagent': 'https://claudeagent.com.cn/v1',
        'minimax': 'https://api.minimaxi.com/v1',
        'openrouter': 'https://openrouter.ai/api/v1',
        'google': 'https://generativelanguage.googleapis.com',
        'anthropic': 'https://api.anthropic.com',
        'openai': 'https://api.openai.com/v1',
        'ollama': 'http://localhost:11434',
        'custom': '',
      }
      if (!this.isEdit) {
        this.form.baseUrl = defaultBaseUrls[provider] || ''
      }
      // 根据 provider 自动切换 API 类型
      if (provider === 'anthropic') {
        this.form.api = 'anthropic'
      } else {
        this.form.api = 'openai-completions'
      }
    },
    
    openDialog(row) {
      if (row) {
        this.dialogTitle = '编辑模型'
        this.isEdit = true
        this.form = {
          modelId: row.modelId,
          name: row.name,
          provider: row.provider,
          baseUrl: row.baseUrl,
          apiKey: row.apiKey,
          api: row.api || 'openai-completions',
          contextWindow: row.contextWindow || null,
          maxTokens: row.maxTokens || null,
        }
        // 设置模型角色
        this.modelRoles = []
        if (row.isPrimary) this.modelRoles.push('primary')
        if (row.isFallback) this.modelRoles.push('fallback')
      } else {
        this.dialogTitle = '添加模型'
        this.isEdit = false
        this.form = {
          modelId: '',
          name: '',
          provider: '',
          baseUrl: '',
          apiKey: '',
          api: 'openai-completions',
          contextWindow: null,
          maxTokens: null,
        }
        this.modelRoles = []
      }
      this.dialogVisible = true
    },
    
    resetForm() {
      this.$refs.form && this.$refs.form.resetFields()
    },
    
    async submitForm() {
      this.$refs.form.validate(async (valid) => {
        if (!valid) return
        
        this.saving = true
        try {
          const data = {
            modelId: this.form.modelId,
            name: this.form.name,
            provider: this.form.provider,
            baseUrl: this.form.baseUrl,
            apiKey: this.form.apiKey,
            api: this.form.api,
            isPrimary: this.modelRoles.includes('primary'),
            isFallback: this.modelRoles.includes('fallback'),
          }
          if (this.form.contextWindow) data.contextWindow = this.form.contextWindow
          if (this.form.maxTokens) data.maxTokens = this.form.maxTokens
          
          await request({ url: '/api/openclaw/config/models', method: 'put', data })
          this.$message.success('保存成功')
          this.dialogVisible = false
          this.fetchModels()
        } catch (e) {
          this.$message.error('保存失败: ' + (e.message || '未知错误'))
        } finally {
          this.saving = false
        }
      })
    },
        
    async handleSetPrimary(row) {
      try {
        await request({ 
          url: '/api/openclaw/config/models/set-primary', 
          method: 'post', 
          data: {
            provider: row.provider,
            modelId: row.modelId,
          }
        })
        this.$message.success('已设为主模型')
        this.fetchModels()
      } catch (e) {
        this.$message.error('设置失败: ' + (e.message || '未知错误'))
      }
    },
    
    async handleSetFallback(row) {
      try {
        await request({ 
          url: '/api/openclaw/config/models/set-fallback', 
          method: 'post', 
          data: {
            provider: row.provider,
            modelId: row.modelId,
          }
        })
        this.$message.success('已设为备用模型')
        this.fetchModels()
      } catch (e) {
        this.$message.error('设置失败: ' + (e.message || '未知错误'))
      }
    },
    
    async handleRemoveFallback(row) {
      try {
        await request({ 
          url: '/api/openclaw/config/models/remove-fallback', 
          method: 'post', 
          data: {
            provider: row.provider,
            modelId: row.modelId,
          }
        })
        this.$message.success('已取消备用模型')
        this.fetchModels()
      } catch (e) {
        this.$message.error('取消失败: ' + (e.message || '未知错误'))
      }
    },
    
    async handleDelete(row) {
      this.$confirm(`确认删除 ${row.name} (${row.modelId}) ？`, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(async () => {
        try {
          await request({ 
            url: `/api/openclaw/config/models/${encodeURIComponent(row.provider)}/${encodeURIComponent(row.modelId)}`, 
            method: 'delete' 
          })
          this.$message.success('删除成功')
          this.fetchModels()
        } catch (e) {
          this.$message.error('删除失败: ' + (e.message || '未知错误'))
        }
      }).catch(() => {})
    },
    
    async handleTest(row) {
      this.$message.info('测试连通性中...')
      try {
        const res = await request({ 
          url: '/api/openclaw/config/models/test', 
          method: 'post', 
          data: {
            baseUrl: row.baseUrl,
            apiKey: row.apiKey,
            api: row.api,
          }
        })
        this.testResult = { 
          text: res.data?.message || '', 
          ok: res.data?.success || false 
        }
      } catch (e) {
        this.testResult = { 
          text: '测试失败: ' + (e.message || '未知错误'), 
          ok: false 
        }
      }
      this.testDialogVisible = true
    },
    
    openChatModal(row) {
      this.chatModel = row
      this.chatMessages = []
      this.chatInput = ''
      this.chatDialogVisible = true
    },
    
    async sendChatMessage() {
      if (!this.chatInput.trim() || this.chatLoading) return
      
      const msg = this.chatInput.trim()
      this.chatMessages.push({ role: 'user', content: msg })
      this.chatInput = ''
      this.chatLoading = true
      
      try {
        const res = await request({ 
          url: '/api/openclaw/config/models/chat-test', 
          method: 'post', 
          data: {
            baseUrl: this.chatModel.baseUrl,
            apiKey: this.chatModel.apiKey,
            api: this.chatModel.api,
            modelId: this.chatModel.modelId,
            message: msg,
          }
        })
        if (res.data?.success) {
          this.chatMessages.push({ role: 'assistant', content: res.data.reply || '收到响应' })
        } else {
          this.chatMessages.push({ role: 'assistant', content: '错误: ' + (res.data?.message || '未知错误') })
        }
      } catch (e) {
        this.chatMessages.push({ role: 'assistant', content: '错误: ' + (e.message || '请求失败') })
      } finally {
        this.chatLoading = false
        this.$nextTick(() => {
          const el = this.$refs.chatMessages
          if (el) el.scrollTop = el.scrollHeight
        })
      }
    },
    
    getProviderType(provider) {
      const types = {
        'openai': '',
        'anthropic': 'success',
        'google': 'warning',
        'ollama': 'info',
        'claudeagent': 'primary',
        'minimax': 'danger',
        'openrouter': 'warning',
        'custom': 'info'
      }
      return types[provider] || ''
    },
    
    getModelDisplayName(modelPath) {
      if (!modelPath) return ''
      const model = this.models.find(m => m.modelPath === modelPath)
      return model ? model.name : modelPath
    }
  }
}
</script>

<style scoped>
.model-management {
  padding: 20px;
}
.model-status {
  display: inline-block;
  margin-right: 10px;
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 4px;
}
.model-status.primary {
  background: #E6F7FF;
  color: #1890FF;
  border: 1px solid #91D5FF;
}
.model-status.fallback {
  background: #F6FFED;
  color: #52C41A;
  border: 1px solid #B7EB8F;
}
.chat-messages {
  height: 350px;
  overflow-y: auto;
  border: 1px solid #EBEEF5;
  border-radius: 4px;
  padding: 10px;
  background: #F5F7FA;
}
.chat-msg {
  display: flex;
  margin-bottom: 10px;
}
.chat-msg.user {
  justify-content: flex-end;
}
.chat-msg.assistant {
  justify-content: flex-start;
}
.chat-bubble {
  max-width: 80%;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.5;
  white-space: pre-wrap;
}
.chat-msg.user .chat-bubble {
  background: #409EFF;
  color: #fff;
}
.chat-msg.assistant .chat-bubble {
  background: #fff;
  border: 1px solid #EBEEF5;
}
.chat-input {
  margin-top: 10px;
}
</style>
