<template>
  <div class="session-management">
    <el-card>
      <div slot="header" class="clearfix">
        <span>会话管理</span>
        <div style="float: right">
          <el-button type="primary" size="small" @click="loadSessions" :loading="loading">
            <i class="el-icon-refresh"></i> 刷新
          </el-button>
          <el-button type="danger" size="small" @click="showCleanupDialog" :loading="cleanupLoading">
            <i class="el-icon-delete"></i> 清理无用会话
          </el-button>
        </div>
      </div>
      
      <!-- 统计信息 -->
      <el-row :gutter="20" style="margin-bottom: 20px">
        <el-col :span="6">
          <el-card shadow="hover">
            <div class="stat-item">
              <div class="stat-value">{{ totalCount }}</div>
              <div class="stat-label">总会话数</div>
            </div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="hover">
            <div class="stat-item">
              <div class="stat-value">{{ agentCount }}</div>
              <div class="stat-label">活跃 Agent</div>
            </div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="hover">
            <div class="stat-item">
              <div class="stat-value">{{ activeToday }}</div>
              <div class="stat-label">今日活跃</div>
            </div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="hover">
            <div class="stat-item">
              <div class="stat-value">{{ totalTokens }}</div>
              <div class="stat-label">总 Token 使用</div>
            </div>
          </el-card>
        </el-col>
      </el-row>

      <!-- 搜索筛选 -->
      <el-row :gutter="10" style="margin-bottom: 20px">
        <el-col :span="6">
          <el-select v-model="filterAgent" placeholder="选择 Agent" clearable @change="applyFilters">
            <el-option label="全部 Agent" value=""></el-option>
            <el-option
              v-for="agent in agents"
              :key="agent"
              :label="agent"
              :value="agent">
            </el-option>
          </el-select>
        </el-col>
        <el-col :span="6">
          <el-select v-model="filterModel" placeholder="选择模型" clearable @change="applyFilters">
            <el-option label="全部模型" value=""></el-option>
            <el-option
              v-for="model in availableModels"
              :key="model"
              :label="model"
              :value="model">
            </el-option>
          </el-select>
        </el-col>
        <el-col :span="8">
          <el-input 
            v-model="searchKey" 
            placeholder="搜索会话 ID 或关键词" 
            clearable
            @input="applyFilters">
            <i slot="prefix" class="el-icon-search"></i>
          </el-input>
        </el-col>
        <el-col :span="4">
          <el-switch
            v-model="showInactive"
            active-text="显示非活跃"
            @change="applyFilters">
          </el-switch>
        </el-col>
      </el-row>

      <!-- 会话列表 -->
      <div v-loading="loading">
        <div v-for="agent in filteredAgents" :key="agent" class="agent-section">
          <h3 class="agent-title">
            <i class="el-icon-user"></i> {{ agent }} 
            <el-badge :value="getAgentSessionCount(agent)" type="primary"></el-badge>
          </h3>
          
          <el-table
            :data="getAgentSessions(agent)"
            stripe
            size="mini"
            @row-click="showSessionDetail">
            <el-table-column prop="sessionId" label="会话 ID" width="280">
              <template slot-scope="scope">
                <el-tooltip :content="scope.row.key" placement="top">
                  <code class="session-id">{{ scope.row.sessionId.substring(0, 8) }}...</code>
                </el-tooltip>
              </template>
            </el-table-column>
            
            <el-table-column prop="kind" label="类型" width="80">
              <template slot-scope="scope">
                <el-tag :type="scope.row.kind === 'group' ? 'success' : 'info'" size="mini">
                  {{ scope.row.kind }}
                </el-tag>
              </template>
            </el-table-column>
            
            <el-table-column prop="model" label="模型" width="120">
              <template slot-scope="scope">
                <el-tooltip :content="`Provider: ${scope.row.modelProvider}`" placement="top">
                  <span class="model-name">{{ scope.row.model }}</span>
                </el-tooltip>
              </template>
            </el-table-column>
            
            <el-table-column prop="updatedAtFormatted" label="最后更新" width="140" sortable></el-table-column>
            
            <el-table-column label="Token 使用" width="120">
              <template slot-scope="scope">
                <div class="token-info">
                  <div>{{ scope.row.totalTokens | numberFormat }}</div>
                  <el-progress 
                    :percentage="scope.row.usage_percent" 
                    :stroke-width="4"
                    :show-text="false"
                    :color="getUsageColor(scope.row.usage_percent)">
                  </el-progress>
                </div>
              </template>
            </el-table-column>
            
            <el-table-column label="输入/输出" width="100">
              <template slot-scope="scope">
                <div class="io-tokens">
                  <div>📥 {{ scope.row.inputTokens | numberFormat }}</div>
                  <div>📤 {{ scope.row.outputTokens | numberFormat }}</div>
                </div>
              </template>
            </el-table-column>
            
            <el-table-column label="状态" width="100">
              <template slot-scope="scope">
                <div class="session-status">
                  <el-tag v-if="scope.row.systemSent" type="success" size="mini">系统消息</el-tag>
                  <el-tag v-if="scope.row.abortedLastRun" type="danger" size="mini">中断</el-tag>
                  <el-tag v-if="scope.row.ageMs < 300000" type="warning" size="mini">活跃</el-tag>
                </div>
              </template>
            </el-table-column>
            
            <el-table-column label="操作" width="120">
              <template slot-scope="scope">
                <el-button 
                  type="text" 
                  size="mini" 
                  @click.stop="showSessionDetail(scope.row)">
                  <i class="el-icon-view"></i> 查看
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
        
        <div v-if="filteredAgents.length === 0" class="no-data">
          <el-empty description="没有找到会话数据"></el-empty>
        </div>
      </div>
    </el-card>

    <!-- 会话详情弹窗 -->
    <el-dialog
      title="会话详情"
      :visible.sync="detailVisible"
      width="80%"
      :before-close="closeDetail">
      
      <div v-if="currentSession" class="session-detail">
        <el-descriptions :column="3" border>
          <el-descriptions-item label="会话 ID">{{ currentSession.sessionId }}</el-descriptions-item>
          <el-descriptions-item label="Agent">{{ currentSession.agentId }}</el-descriptions-item>
          <el-descriptions-item label="类型">{{ currentSession.kind }}</el-descriptions-item>
          <el-descriptions-item label="模型">{{ currentSession.model }} ({{ currentSession.modelProvider }})</el-descriptions-item>
          <el-descriptions-item label="最后更新">{{ currentSession.updatedAtFormatted }}</el-descriptions-item>
          <el-descriptions-item label="Token 使用">{{ currentSession.totalTokens }} / {{ currentSession.contextTokens }} ({{ currentSession.usage_percent }}%)</el-descriptions-item>
        </el-descriptions>
        
        <el-divider content-position="left">对话消息 ({{ sessionMessages.length }} 条)</el-divider>
        
        <div v-loading="detailLoading" class="messages-container">
          <div v-if="sessionMessages.length === 0" class="no-messages">
            <el-empty description="暂无消息记录" :image-size="100"></el-empty>
          </div>
          
          <div v-for="(message, index) in sessionMessages" :key="index" class="message-item" :class="`message-${message.role}`">
            <div class="message-header">
              <div class="message-meta">
                <el-avatar 
                  :size="24" 
                  :icon="getRoleIcon(message.role)" 
                  :style="{backgroundColor: getRoleColorHex(message.role)}"
                ></el-avatar>
                <span class="role-label">{{ getRoleLabel(message.role) }}</span>
                <span v-if="message.name" class="message-name">{{ message.name }}</span>
              </div>
              <div class="message-time">{{ formatMessageTime(message.timestamp) }}</div>
            </div>
            <div class="message-content">
              <!-- 文本内容 -->
              <div v-if="typeof message.content === 'string'" class="content-text" v-html="formatContent(message.content)"></div>
              
              <!-- 结构化内容 -->
              <div v-else-if="message.content && typeof message.content === 'object'" class="content-structured">
                <el-collapse v-if="Array.isArray(message.content)" accordion>
                  <el-collapse-item 
                    v-for="(item, i) in message.content" 
                    :key="i" 
                    :title="`内容块 ${i+1}`">
                    <div v-if="item.type === 'text'" v-html="formatContent(item.text)"></div>
                    <div v-else>{{ JSON.stringify(item, null, 2) }}</div>
                  </el-collapse-item>
                </el-collapse>
                <pre v-else class="json-content">{{ JSON.stringify(message.content, null, 2) }}</pre>
              </div>
              
              <!-- 工具调用 -->
              <div v-if="message.tool_calls && message.tool_calls.length > 0" class="tool-calls">
                <el-tag type="info" size="small" icon="el-icon-s-tools">工具调用 ({{ message.tool_calls.length }})</el-tag>
                <div v-for="(call, i) in message.tool_calls" :key="i" class="tool-call">
                  <div class="tool-call-header">
                    <el-tag size="mini" type="warning">{{ call.function?.name || 'Unknown Tool' }}</el-tag>
                    <span class="tool-call-id">{{ call.id || call.tool_call_id }}</span>
                  </div>
                  <div class="tool-arguments">
                    <el-collapse>
                      <el-collapse-item title="查看参数">
                        <pre class="json-content">{{ JSON.stringify(call.function?.arguments, null, 2) }}</pre>
                      </el-collapse-item>
                    </el-collapse>
                  </div>
                </div>
              </div>
              
              <!-- 工具结果 -->
              <div v-if="message.role === 'tool'" class="tool-result">
                <el-tag size="small" type="success" icon="el-icon-check">工具执行结果</el-tag>
                <div class="tool-result-content">
                  <pre v-if="typeof message.content === 'string'">{{ message.content }}</pre>
                  <pre v-else class="json-content">{{ JSON.stringify(message.content, null, 2) }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 清理确认弹窗 -->
    <el-dialog
      title="清理会话数据"
      :visible.sync="cleanupDialogVisible"
      width="450px"
      center>
      
      <div style="text-align: center; padding: 20px 0;">
        <i class="el-icon-warning" style="font-size: 48px; color: #E6A23C; margin-bottom: 20px;"></i>
        <p style="font-size: 16px; margin-bottom: 15px;">确定要清理无用的会话数据吗？</p>
        <p style="color: #999; font-size: 14px; line-height: 1.5;">
          此操作将删除：<br>
          • 过期的会话文件<br>
          • 无效的会话记录<br>
          • 临时缓存数据<br><br>
          <strong>此操作不可撤销</strong>
        </p>
      </div>
      
      <div slot="footer" class="dialog-footer">
        <el-button @click="cleanupDialogVisible = false">取消</el-button>
        <el-button type="danger" @click="executeCleanup" :loading="cleanupLoading">
          <i class="el-icon-delete"></i> 确认清理
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { getSessionsList, getSessionDetail, cleanupSessions } from '@/api/sessions'

export default {
  name: 'SessionManagement',
  data() {
    return {
      loading: false,
      cleanupLoading: false,
      detailVisible: false,
      detailLoading: false,
      
      // 数据
      totalCount: 0,
      agents: [],
      sessionsData: {},
      
      // 筛选
      filterAgent: '',
      filterModel: '',
      searchKey: '',
      showInactive: false,
      
      // 详情
      currentSession: null,
      sessionMessages: [],
      
      // 清理
      cleanupDialogVisible: false
    }
  },
  
  computed: {
    agentCount() {
      return this.agents.length
    },
    
    activeToday() {
      const today = new Date().getTime()
      const oneDayAgo = today - 24 * 60 * 60 * 1000
      
      let count = 0
      for (const agent of this.agents) {
        const sessions = this.sessionsData[agent] || []
        count += sessions.filter(s => s.updatedAt > oneDayAgo).length
      }
      return count
    },
    
    totalTokens() {
      let total = 0
      for (const agent of this.agents) {
        const sessions = this.sessionsData[agent] || []
        total += sessions.reduce((sum, s) => sum + (s.totalTokens || 0), 0)
      }
      return this.$options.filters.numberFormat(total)
    },
    
    availableModels() {
      const models = new Set()
      for (const agent of this.agents) {
        const sessions = this.sessionsData[agent] || []
        sessions.forEach(s => {
          if (s.model) models.add(s.model)
        })
      }
      return Array.from(models).sort()
    },
    
    filteredAgents() {
      let agents = [...this.agents]
      
      if (this.filterAgent) {
        agents = agents.filter(agent => agent === this.filterAgent)
      }
      
      // 过滤掉没有符合条件会话的 Agent
      agents = agents.filter(agent => this.getAgentSessions(agent).length > 0)
      
      return agents
    }
  },
  
  filters: {
    numberFormat(value) {
      if (!value) return '0'
      return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')
    }
  },
  
  mounted() {
    this.loadSessions()
  },
  
  methods: {
    async loadSessions() {
      this.loading = true
      try {
        const response = await getSessionsList()
        if (response.code === 200) {
          this.totalCount = response.data.total_count
          this.agents = response.data.agents || []
          this.sessionsData = response.data.sessions || {}
        } else {
          this.$message.error(response.message || '获取会话列表失败')
        }
      } catch (error) {
        console.error('获取会话列表失败:', error)
        this.$message.error('获取会话列表失败')
      } finally {
        this.loading = false
      }
    },
    
    getAgentSessions(agent) {
      const sessions = this.sessionsData[agent] || []
      
      return sessions.filter(session => {
        // 模型筛选
        if (this.filterModel && session.model !== this.filterModel) {
          return false
        }
        
        // 关键词搜索
        if (this.searchKey) {
          const key = this.searchKey.toLowerCase()
          return session.sessionId.toLowerCase().includes(key) ||
                 session.key.toLowerCase().includes(key) ||
                 (session.model && session.model.toLowerCase().includes(key))
        }
        
        // 活跃状态筛选
        if (!this.showInactive && session.ageMs > 24 * 60 * 60 * 1000) {
          return false
        }
        
        return true
      })
    },
    
    getAgentSessionCount(agent) {
      return this.getAgentSessions(agent).length
    },
    
    applyFilters() {
      // 筛选逻辑在计算属性中处理
    },
    
    getUsageColor(percentage) {
      if (percentage < 50) return '#67C23A'
      if (percentage < 80) return '#E6A23C'
      return '#F56C6C'
    },
    
    getRoleColor(role) {
      const colors = {
        'user': 'primary',
        'assistant': 'success',
        'system': 'info',
        'tool': 'warning'
      }
      return colors[role] || 'info'
    },
    
    async showSessionDetail(session) {
      this.currentSession = session
      this.detailVisible = true
      this.detailLoading = true
      
      try {
        const response = await getSessionDetail(session.key, session.agentId)
        if (response.code === 200) {
          this.sessionMessages = response.data.messages || []
        } else {
          this.$message.error(response.message || '获取会话详情失败')
        }
      } catch (error) {
        console.error('获取会话详情失败:', error)
        this.$message.error('获取会话详情失败')
      } finally {
        this.detailLoading = false
      }
    },
    
    closeDetail() {
      this.detailVisible = false
      this.currentSession = null
      this.sessionMessages = []
    },
    
    showCleanupDialog() {
      this.cleanupDialogVisible = true
    },
    
    async executeCleanup() {
      this.cleanupLoading = true
      try {
        const response = await cleanupSessions()
        if (response.code === 200) {
          this.$message({
            type: 'success',
            message: '清理完成！已删除无用的会话数据',
            duration: 3000
          })
          this.cleanupDialogVisible = false
          // 延迟刷新，让用户看到成功消息
          setTimeout(() => {
            this.loadSessions()
          }, 1000)
        } else {
          this.$message.error(response.message || '清理失败')
        }
      } catch (error) {
        console.error('清理失败:', error)
        this.$message.error('清理操作失败，请稍后重试')
      } finally {
        this.cleanupLoading = false
      }
    },
    
    // 消息格式化方法
    getRoleLabel(role) {
      const labels = {
        'user': '用户',
        'assistant': '助手',
        'system': '系统',
        'tool': '工具'
      }
      return labels[role] || role
    },
    
    getRoleIcon(role) {
      const icons = {
        'user': 'el-icon-user',
        'assistant': 'el-icon-cpu',
        'system': 'el-icon-setting',
        'tool': 'el-icon-tools'
      }
      return icons[role] || 'el-icon-chat-line-square'
    },
    
    getRoleColorHex(role) {
      const colors = {
        'user': '#409EFF',
        'assistant': '#67C23A',
        'system': '#909399',
        'tool': '#E6A23C'
      }
      return colors[role] || '#909399'
    },
    
    formatMessageTime(timestamp) {
      if (!timestamp) return ''
      
      try {
        const date = new Date(timestamp)
        const now = new Date()
        const diffMs = now - date
        const diffHours = diffMs / (1000 * 60 * 60)
        const diffDays = diffMs / (1000 * 60 * 60 * 24)
        
        if (diffDays > 7) {
          return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
        } else if (diffDays > 1) {
          return `${Math.floor(diffDays)}天前 ` + date.toLocaleTimeString()
        } else if (diffHours > 1) {
          return `${Math.floor(diffHours)}小时前`
        } else {
          const diffMins = Math.floor(diffMs / (1000 * 60))
          return diffMins > 0 ? `${diffMins}分钟前` : '刚刚'
        }
      } catch (e) {
        return timestamp
      }
    },
    
    formatContent(content) {
      if (!content || typeof content !== 'string') return content
      
      // 基本的格式化：换行、链接、代码块
      return content
        .replace(/\n/g, '<br>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/https?:\/\/[^\s<>"]+/g, (url) => {
          return `<a href="${url}" target="_blank" style="color: #409EFF;">${url}</a>`
        })
    }
  }
}
</script>

<style scoped>
.session-management {
  padding: 20px;
}

.stat-item {
  text-align: center;
  padding: 10px;
}

.stat-value {
  font-size: 24px;
  font-weight: bold;
  color: #409EFF;
  margin-bottom: 5px;
}

.stat-label {
  font-size: 12px;
  color: #666;
}

.agent-section {
  margin-bottom: 30px;
}

.agent-title {
  margin: 20px 0 10px 0;
  padding: 10px;
  background: #f5f7fa;
  border-left: 4px solid #409EFF;
  display: flex;
  align-items: center;
  gap: 10px;
}

.session-id {
  font-family: Monaco, 'Courier New', monospace;
  background: #f5f5f5;
  padding: 2px 4px;
  border-radius: 3px;
  font-size: 11px;
}

.model-name {
  font-size: 12px;
  color: #666;
}

.token-info {
  text-align: center;
  font-size: 12px;
}

.io-tokens {
  font-size: 11px;
  line-height: 1.2;
}

.session-status {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.no-data {
  text-align: center;
  padding: 40px;
}

.session-detail {
  max-height: 600px;
  overflow-y: auto;
}

.messages-container {
  max-height: 500px;
  overflow-y: auto;
  border: 1px solid #EBEEF5;
  border-radius: 6px;
  padding: 15px;
  background: #fafafa;
}

.message-item {
  margin-bottom: 20px;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #f0f0f0;
  overflow: hidden;
  transition: all 0.2s;
}

.message-item:hover {
  border-color: #ddd;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.message-user {
  border-left: 4px solid #409EFF;
}

.message-assistant {
  border-left: 4px solid #67C23A;
}

.message-system {
  border-left: 4px solid #909399;
}

.message-tool {
  border-left: 4px solid #E6A23C;
}

.message-header {
  padding: 12px 15px;
  background: #f8f9fa;
  border-bottom: 1px solid #f0f0f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.message-meta {
  display: flex;
  align-items: center;
  gap: 10px;
}

.role-label {
  font-weight: 500;
  color: #333;
}

.message-name {
  font-size: 12px;
  color: #999;
  font-style: italic;
}

.message-time {
  font-size: 12px;
  color: #999;
  white-space: nowrap;
}

.message-content {
  padding: 15px;
}

.content-text {
  line-height: 1.6;
  color: #333;
  font-size: 14px;
}

.content-text code {
  background: #f1f1f1;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  color: #e83e8c;
}

.content-text strong {
  color: #333;
  font-weight: 600;
}

.content-structured {
  margin-top: 10px;
}

.json-content {
  background: #f8f8f8;
  padding: 12px;
  border-radius: 4px;
  font-size: 12px;
  color: #666;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
  line-height: 1.4;
  overflow-x: auto;
  max-height: 200px;
  overflow-y: auto;
  margin: 0;
  white-space: pre-wrap;
}

.tool-calls {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 1px solid #f0f0f0;
}

.tool-call {
  margin: 12px 0;
  padding: 12px;
  background: #f8f9fa;
  border-radius: 6px;
  border-left: 3px solid #E6A23C;
}

.tool-call-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.tool-call-id {
  font-size: 11px;
  color: #999;
  font-family: monospace;
}

.tool-arguments {
  margin-top: 8px;
}

.tool-result {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 1px solid #f0f0f0;
}

.tool-result-content {
  margin-top: 10px;
  padding: 12px;
  background: #f0f9ff;
  border-radius: 6px;
  border-left: 3px solid #67C23A;
}

.no-messages {
  text-align: center;
  padding: 40px 20px;
  color: #999;
}

.dialog-footer {
  text-align: center;
  padding-top: 20px;
}

/* 响应式设计 */
@media (max-width: 768px) {
  .message-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  
  .message-meta {
    gap: 8px;
  }
  
  .messages-container {
    max-height: 400px;
  }
}

/* 滚动条样式 */
.messages-container::-webkit-scrollbar,
.json-content::-webkit-scrollbar {
  width: 6px;
}

.messages-container::-webkit-scrollbar-track,
.json-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}

.messages-container::-webkit-scrollbar-thumb,
.json-content::-webkit-scrollbar-thumb {
  background: #ccc;
  border-radius: 3px;
}

.messages-container::-webkit-scrollbar-thumb:hover,
.json-content::-webkit-scrollbar-thumb:hover {
  background: #999;
}
</style>