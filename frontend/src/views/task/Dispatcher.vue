<template>
  <div class="task-dispatcher">
    <el-card class="box-card">
      <div slot="header" class="clearfix">
        <span><i class="el-icon-s-promotion"></i> 任务下发中心</span>
        <el-button 
          style="float: right; padding: 3px 0" 
          type="text" 
          @click="refreshNodes"
          :loading="refreshing"
        >
          刷新节点
        </el-button>
      </div>

      <!-- 节点状态提示 -->
      <div class="node-status-alert">
        <el-alert
          v-if="!hasOnlineNodes"
          title="暂无在线节点"
          description="请先确保有节点在线，然后刷新页面。如需添加新节点，请前往 节点管理 页面。"
          type="warning"
          :closable="false"
          show-icon
        >
        </el-alert>
        <el-alert
          v-else
          :title="`检测到 ${onlineNodes.length} 个在线节点`"
          :description="`可执行任务的节点：${onlineNodes.map(n => n.node_name || '未命名').join('、')}`"
          type="success"
          :closable="false"
          show-icon
        >
        </el-alert>
      </div>

      <!-- 任务表单 -->
      <el-form :model="form" label-width="120px" style="max-width: 800px; margin: 20px 0;">
        <!-- 任务类型 -->
        <el-form-item label="任务类型:">
          <el-radio-group v-model="form.type" @change="onTypeChange">
            <el-radio label="script">
              <i class="el-icon-document"></i> 脚本执行
            </el-radio>
            <el-radio label="openclaw">
              <i class="el-icon-chat-dot-round"></i> OpenClaw消息
            </el-radio>
            <el-radio label="custom">
              <i class="el-icon-magic-stick"></i> 自定义指令
            </el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- 目标节点选择 -->
        <el-form-item label="目标节点:" required>
          <el-select 
            v-model="form.targetNodeKey"
            placeholder="选择执行节点"
            style="width: 100%;"
            :disabled="!hasOnlineNodes"
            filterable
          >
            <el-option
              v-for="node in onlineNodes"
              :key="node.id"
              :label="getNodeDisplayName(node)"
              :value="node.node_key_masked"
            >
              <span style="float: left">{{ node.node_name || '未命名节点' }}</span>
              <span style="float: right; color: #8492a6; font-size: 12px;">
                {{ node.node_key_masked }} · {{ formatTime(node.last_heartbeat) }}
              </span>
            </el-option>
          </el-select>
          <div style="margin-top: 5px; color: #666; font-size: 12px;">
            💡 提示：只显示状态为"在线"的节点
          </div>
        </el-form-item>

        <!-- 脚本执行配置 -->
        <template v-if="form.type === 'script'">
          <el-form-item label="执行脚本:" required>
            <el-select 
              v-model="form.scriptName"
              placeholder="选择要执行的脚本"
              style="width: 100%;"
              filterable
              :loading="scriptsLoading"
            >
              <el-option
                v-for="script in scripts"
                :key="script.name"
                :label="script.name"
                :value="script.name"
              >
                <span style="float: left">{{ script.name }}</span>
                <span style="float: right; color: #8492a6; font-size: 12px;">
                  {{ script.size_kb }}KB · {{ script.modified }}
                </span>
              </el-option>
            </el-select>
            <el-button 
              type="text" 
              @click="loadScripts" 
              :loading="scriptsLoading"
              style="margin-left: 10px;"
            >
              刷新脚本列表
            </el-button>
          </el-form-item>
          
          <el-form-item label="脚本参数:">
            <el-input
              type="textarea"
              v-model="form.params"
              placeholder='JSON格式参数，如: {"message": "测试", "count": 5}'
              rows="3"
            ></el-input>
            <div style="margin-top: 5px; color: #666; font-size: 12px;">
              参数将作为命令行参数传递给脚本
            </div>
          </el-form-item>
        </template>

        <!-- OpenClaw消息配置 -->
        <template v-if="form.type === 'openclaw'">
          <el-form-item label="Agent ID:" required>
            <el-input 
              v-model="form.agentId" 
              placeholder="如: hc-coding"
              style="width: 300px;"
            ></el-input>
          </el-form-item>
          
          <el-form-item label="消息内容:" required>
            <el-input
              type="textarea"
              v-model="form.message"
              placeholder="要发送给Agent的消息内容"
              rows="4"
            ></el-input>
          </el-form-item>
          
          <el-form-item label="超时设置:">
            <el-input-number 
              v-model="form.timeout" 
              :min="10" 
              :max="300"
              :step="10"
              style="width: 150px;"
            ></el-input-number>
            <span style="margin-left: 10px; color: #666;">秒</span>
          </el-form-item>
        </template>

        <!-- 自定义指令配置 -->
        <template v-if="form.type === 'custom'">
          <el-form-item label="执行命令:" required>
            <el-input 
              v-model="form.command" 
              placeholder="如: python test.py 或 ls -la"
            ></el-input>
          </el-form-item>
          
          <el-form-item label="工作目录:">
            <el-input 
              v-model="form.workdir" 
              placeholder="执行目录，默认为脚本目录"
            ></el-input>
          </el-form-item>
        </template>

        <!-- 高级选项 -->
        <el-form-item>
          <el-collapse>
            <el-collapse-item title="高级选项" name="advanced">
              <el-form-item label="任务优先级:">
                <el-select v-model="form.priority" style="width: 150px;">
                  <el-option label="低" value="low"></el-option>
                  <el-option label="正常" value="normal"></el-option>
                  <el-option label="高" value="high"></el-option>
                </el-select>
              </el-form-item>
              
              <el-form-item label="执行超时:">
                <el-input-number 
                  v-model="form.executionTimeout" 
                  :min="30" 
                  :max="1800"
                  :step="30"
                  style="width: 150px;"
                ></el-input-number>
                <span style="margin-left: 10px; color: #666;">秒</span>
              </el-form-item>
              
              <el-form-item label="失败重试:">
                <el-input-number 
                  v-model="form.retryCount" 
                  :min="0" 
                  :max="3"
                  style="width: 150px;"
                ></el-input-number>
                <span style="margin-left: 10px; color: #666;">次</span>
              </el-form-item>
            </el-collapse-item>
          </el-collapse>
        </el-form-item>

        <!-- 提交按钮 -->
        <el-form-item>
          <el-button 
            type="primary" 
            @click="submitTask"
            :loading="submitting"
            :disabled="!canSubmit"
            size="medium"
          >
            <i class="el-icon-s-promotion"></i>
            {{ submitting ? '提交中...' : '立即执行' }}
          </el-button>
          <el-button @click="resetForm">重置表单</el-button>
          <el-button type="success" @click="previewPayload">预览载荷</el-button>
        </el-form-item>
      </el-form>

      <!-- 最近任务 -->
      <RecentTasks @task-clicked="viewTaskDetail" />
    </el-card>

    <!-- 载荷预览对话框 -->
    <el-dialog title="任务载荷预览" :visible.sync="previewVisible" width="600px">
      <pre class="payload-preview">{{ previewData }}</pre>
      <div slot="footer">
        <el-button @click="previewVisible = false">关闭</el-button>
        <el-button type="primary" @click="copyPayload">复制到剪贴板</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { mapState, mapGetters, mapActions } from 'vuex'
import RecentTasks from './components/RecentTasks.vue'

export default {
  name: 'TaskDispatcher',
  components: {
    RecentTasks
  },
  
  data() {
    return {
      refreshing: false,
      scriptsLoading: false,
      submitting: false,
      previewVisible: false,
      
      form: {
        type: 'script',
        targetNodeKey: '',
        
        // 脚本执行
        scriptName: '',
        params: '{"message": "Hello from AdminPro"}',
        
        // OpenClaw消息
        agentId: 'hc-coding',
        message: '这是来自AdminPro的测试消息',
        timeout: 60,
        
        // 自定义指令
        command: '',
        workdir: '',
        
        // 高级选项
        priority: 'normal',
        executionTimeout: 300,
        retryCount: 0
      },
      
      scripts: []
    }
  },
  
  computed: {
    ...mapState('nodes', ['nodeKeys', 'connected']),
    ...mapGetters('nodes', ['onlineNodes']),
    
    hasOnlineNodes() {
      return this.onlineNodes.length > 0
    },
    
    canSubmit() {
      if (!this.form.targetNodeKey) return false
      
      switch (this.form.type) {
        case 'script':
          return !!this.form.scriptName
        case 'openclaw':
          return !!(this.form.agentId && this.form.message)
        case 'custom':
          return !!this.form.command
        default:
          return false
      }
    },
    
    previewData() {
      return JSON.stringify(this.buildPayload(), null, 2)
    }
  },
  
  mounted() {
    this.initPage()
  },
  
  methods: {
    ...mapActions('nodes', ['connectWs', 'fetchNodeKeys']),
    
    async initPage() {
      // 连接WebSocket获取节点状态
      this.connectWs()
      await this.refreshNodes()
      
      // 加载脚本列表
      if (this.form.type === 'script') {
        this.loadScripts()
      }
    },
    
    async refreshNodes() {
      this.refreshing = true
      try {
        await this.fetchNodeKeys()
        this.$message.success(`已刷新，当前${this.onlineNodes.length}个节点在线`)
      } catch (error) {
        this.$message.error('刷新节点列表失败: ' + error.message)
      } finally {
        this.refreshing = false
      }
    },
    
    async loadScripts() {
      this.scriptsLoading = true
      try {
        const res = await this.$axios.get('/api/openclaw/scripts')
        if (res.data.code === 200) {
          this.scripts = res.data.data.scripts || []
        }
      } catch (error) {
        this.$message.error('加载脚本列表失败')
      } finally {
        this.scriptsLoading = false
      }
    },
    
    onTypeChange() {
      // 切换类型时加载对应资源
      if (this.form.type === 'script' && this.scripts.length === 0) {
        this.loadScripts()
      }
    },
    
    buildPayload() {
      const basePayload = {
        type: this.form.type,
        target_node_key: this.form.targetNodeKey,
        priority: this.form.priority,
        execution_timeout: this.form.executionTimeout,
        retry_count: this.form.retryCount
      }
      
      switch (this.form.type) {
        case 'script':
          return {
            ...basePayload,
            payload: {
              script_path: this.form.scriptName,
              params: this.form.params ? JSON.parse(this.form.params) : {}
            }
          }
          
        case 'openclaw':
          return {
            ...basePayload,
            payload: {
              agent_id: this.form.agentId,
              message: this.form.message,
              timeout: this.form.timeout
            }
          }
          
        case 'custom':
          return {
            ...basePayload,
            payload: {
              command: this.form.command,
              workdir: this.form.workdir || undefined
            }
          }
          
        default:
          return basePayload
      }
    },
    
    async submitTask() {
      if (!this.canSubmit) return
      
      this.submitting = true
      try {
        const payload = this.buildPayload()
        const res = await this.$axios.post('/api/openclaw/bridge/task', payload)
        
        if (res.data.code === 200) {
          const taskId = res.data.data.task_id
          this.$message.success(`任务已提交成功！任务ID: ${taskId}`)
          
          // 触发最近任务刷新
          this.$refs.recentTasks && this.$refs.recentTasks.refresh()
          
          // 可选：跳转到任务详情
          this.$confirm('是否查看任务执行状态？', '提示', {
            confirmButtonText: '查看',
            cancelButtonText: '继续提交',
            type: 'success'
          }).then(() => {
            this.$router.push(`/tasks/${taskId}`)
          }).catch(() => {})
          
        } else {
          this.$message.error(res.data.message || '任务提交失败')
        }
      } catch (error) {
        this.$message.error('提交任务时发生错误: ' + (error.response?.data?.message || error.message))
      } finally {
        this.submitting = false
      }
    },
    
    resetForm() {
      this.form = {
        type: 'script',
        targetNodeKey: '',
        scriptName: '',
        params: '{"message": "Hello from AdminPro"}',
        agentId: 'hc-coding',
        message: '这是来自AdminPro的测试消息',
        timeout: 60,
        command: '',
        workdir: '',
        priority: 'normal',
        executionTimeout: 300,
        retryCount: 0
      }
    },
    
    previewPayload() {
      this.previewVisible = true
    },
    
    copyPayload() {
      const text = this.previewData
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          this.$message.success('载荷已复制到剪贴板')
        })
      } else {
        // Fallback
        const textarea = document.createElement('textarea')
        textarea.value = text
        document.body.appendChild(textarea)
        textarea.select()
        document.execCommand('copy')
        document.body.removeChild(textarea)
        this.$message.success('载荷已复制到剪贴板')
      }
      this.previewVisible = false
    },
    
    getNodeDisplayName(node) {
      return node.node_name || `节点-${node.node_key_masked.slice(-4)}`
    },
    
    formatTime(timestamp) {
      if (!timestamp) return '未知'
      const date = new Date(timestamp * 1000)
      const now = new Date()
      const diff = now - date
      
      if (diff < 60000) return '刚刚'
      if (diff < 3600000) return `${Math.floor(diff / 60000)}分钟前`
      if (diff < 86400000) return `${Math.floor(diff / 3600000)}小时前`
      return date.toLocaleDateString()
    },
    
    viewTaskDetail(task) {
      this.$router.push(`/tasks/${task.task_id}`)
    }
  }
}
</script>

<style scoped>
.task-dispatcher {
  padding: 20px;
}

.node-status-alert {
  margin-bottom: 20px;
}

.payload-preview {
  background: #f5f5f5;
  padding: 15px;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.5;
  max-height: 400px;
  overflow-y: auto;
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.el-form-item {
  margin-bottom: 18px;
}

.el-radio {
  margin-right: 20px;
}
</style>