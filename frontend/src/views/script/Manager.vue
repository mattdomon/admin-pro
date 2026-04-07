<template>
  <div class="script-manager">
    <!-- 工具栏 -->
    <div class="toolbar">
      <el-button type="primary" icon="el-icon-plus" @click="newScript">新建脚本</el-button>
      <el-button icon="el-icon-download" @click="showTemplates">从模板创建</el-button>
      <el-button icon="el-icon-refresh" @click="loadScripts">刷新</el-button>
      <span class="script-count">共 {{ scripts.length }} 个脚本</span>
    </div>

    <el-row :gutter="16" style="margin-top: 16px">
      <!-- 左侧脚本列表 -->
      <el-col :span="7">
        <el-card shadow="never" class="script-list-card">
          <div slot="header">📂 脚本文件</div>

          <div v-if="loading" class="loading-state">
            <i class="el-icon-loading"></i> 加载中...
          </div>

          <div v-else-if="scripts.length === 0" class="empty-state">
            <i class="el-icon-document"></i>
            <p>暂无脚本，点击「新建脚本」开始</p>
          </div>

          <ul v-else class="script-list">
            <li
              v-for="s in scripts"
              :key="s.name"
              :class="['script-item', { active: currentScript && currentScript.name === s.name }]"
              @click="openScript(s.name)"
            >
              <div class="script-item-header">
                <i class="el-icon-document-checked"></i>
                <span class="script-name">{{ s.name }}</span>
              </div>
              <div class="script-meta">
                <span>{{ s.line_count }} 行</span>
                <span>{{ s.modified_time }}</span>
              </div>
              <div class="script-desc" v-if="s.description">{{ s.description }}</div>
            </li>
          </ul>
        </el-card>
      </el-col>

      <!-- 右侧编辑区 -->
      <el-col :span="17">
        <el-card shadow="never" class="editor-card">
          <div slot="header" class="editor-header">
            <span v-if="currentScript">
              ✏️ {{ isNew ? '新建' : '编辑' }}: {{ editingName }}
            </span>
            <span v-else>📝 选择或新建脚本</span>

            <div class="editor-actions" v-if="currentScript !== null">
              <el-input
                v-model="editingName"
                size="small"
                placeholder="脚本文件名.py"
                style="width: 180px; margin-right: 8px"
              />
              <el-button
                type="success"
                size="small"
                icon="el-icon-check"
                :loading="saving"
                @click="saveScript"
              >保存</el-button>
              
              <!-- 新增：目标节点选择 -->
              <el-select 
                v-if="!isNew"
                v-model="targetNodeKey"
                placeholder="选择执行节点"
                size="small"
                style="width: 200px; margin-right: 8px;"
                :disabled="!onlineNodes.length"
              >
                <el-option
                  v-for="node in onlineNodes"
                  :key="node.id"
                  :label="node.node_name || `节点-${node.node_key_masked.slice(-4)}`"
                  :value="node.node_key_masked"
                >
                  <span style="float: left">{{ node.node_name || '未命名' }}</span>
                  <span style="float: right; color: #8492a6; font-size: 12px;">{{ node.node_key_masked }}</span>
                </el-option>
              </el-select>
              
              <el-button
                v-if="!isNew"
                type="primary"
                size="small"
                icon="el-icon-video-play"
                :loading="executing"
                @click="executeScript"
                :disabled="!targetNodeKey && onlineNodes.length > 0"
              >执行脚本</el-button>
              <el-button
                size="small"
                icon="el-icon-close"
                @click="closeEditor"
              >关闭</el-button>
              <el-button
                v-if="!isNew"
                type="danger"
                size="small"
                icon="el-icon-delete"
                @click="deleteScript"
              >删除</el-button>
            </div>
          </div>

          <!-- 编辑器占位（无脚本时） -->
          <div v-if="currentScript === null" class="editor-placeholder">
            <i class="el-icon-edit-outline"></i>
            <p>从左侧选择脚本，或新建一个脚本开始编辑</p>
          </div>

          <!-- 代码编辑区 -->
          <div v-else>
            <el-input
              v-model="editingContent"
              type="textarea"
              :rows="28"
              placeholder="# 在这里写 Python 脚本..."
              class="code-editor"
              spellcheck="false"
            />
            <div class="editor-footer">
              <span class="line-count">{{ lineCount }} 行 · {{ charCount }} 字符</span>
              <span v-if="syntaxError" class="syntax-error">
                <i class="el-icon-warning-outline"></i> {{ syntaxError }}
              </span>
              <span v-else-if="editingContent" class="syntax-ok">
                <i class="el-icon-circle-check"></i> 语法正常
              </span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 近期任务列表 -->
    <el-card shadow="never" style="margin-top: 16px;">
      <div slot="header" class="task-header">
        <span>📈 近期任务列表</span>
        <div class="task-actions">
          <el-button
            size="small"
            icon="el-icon-refresh"
            :loading="tasksLoading"
            @click="loadTasks"
          >刷新任务</el-button>
          <el-button
            size="small"
            icon="el-icon-delete"
            @click="clearCompletedTasks"
          >清理完成任务</el-button>
        </div>
      </div>

      <div v-if="tasksLoading" class="loading-state">
        <i class="el-icon-loading"></i> 加载任务列表...
      </div>

      <div v-else-if="tasks.length === 0" class="empty-state">
        <i class="el-icon-document-copy"></i>
        <p>暂无执行任务</p>
      </div>

      <el-table v-else :data="tasks" stripe size="small">
        <el-table-column prop="task_id" label="任务ID" width="200">
          <template slot-scope="scope">
            <el-tag size="mini" type="info">{{ scope.row.task_id.substr(-8) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="script_name" label="脚本" width="150" />
        <el-table-column prop="status" label="状态" width="100">
          <template slot-scope="scope">
            <el-tag
              size="mini"
              :type="getTaskStatusType(scope.row.status)"
            >
              {{ getTaskStatusText(scope.row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="160">
          <template slot-scope="scope">
            {{ formatTime(scope.row.created_at) }}</template>
        </el-table-column>
        <el-table-column prop="completed_at" label="完成时间" width="160">
          <template slot-scope="scope">
            {{ scope.row.completed_at ? formatTime(scope.row.completed_at) : '-' }}
          </template>
        </el-table-column>
        <el-table-column label="结果" min-width="200">
          <template slot-scope="scope">
            <div v-if="scope.row.status === 'success'" class="task-result success">
              <i class="el-icon-success"></i>
              <span>执行成功</span>
              <el-button
                v-if="scope.row.result"
                type="text"
                size="mini"
                @click="showTaskResult(scope.row)"
              >查看结果</el-button>
            </div>
            <div v-else-if="scope.row.status === 'failed'" class="task-result error">
              <i class="el-icon-error"></i>
              <span>执行失败</span>
              <el-button
                v-if="scope.row.error"
                type="text"
                size="mini"
                @click="showTaskError(scope.row)"
              >查看错误</el-button>
            </div>
            <div v-else-if="scope.row.status === 'running'" class="task-result running">
              <i class="el-icon-loading"></i>
              <span>执行中...</span>
            </div>
            <div v-else-if="scope.row.status === 'pending'" class="task-result pending">
              <i class="el-icon-time"></i>
              <span>等待执行</span>
            </div>
            <div v-else class="task-result">
              <span>{{ scope.row.status }}</span>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <el-pagination
        v-if="tasks.length > 0"
        style="margin-top: 16px; text-align: center;"
        :current-page="taskPage"
        :page-size="taskPageSize"
        :total="taskTotal"
        layout="prev, pager, next, sizes, total"
        :page-sizes="[10, 20, 50]"
        @size-change="handleTaskSizeChange"
        @current-change="handleTaskCurrentChange"
      />
    </el-card>

    <!-- 模板选择对话框 -->
    <el-dialog title="从模板创建脚本" :visible.sync="templateDialogVisible" width="600px">
      <div v-if="templatesLoading" style="text-align: center; padding: 40px">
        <i class="el-icon-loading" style="font-size: 32px"></i>
      </div>
      <div v-else>
        <el-radio-group v-model="selectedTemplate" style="width: 100%">
          <div
            v-for="tpl in templates"
            :key="tpl.name"
            class="template-item"
            @click="selectedTemplate = tpl.name"
          >
            <el-radio :label="tpl.name">
              <strong>{{ tpl.title }}</strong>
              <span class="tpl-desc">{{ tpl.description }}</span>
            </el-radio>
          </div>
        </el-radio-group>
      </div>
      <div slot="footer">
        <el-button @click="templateDialogVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!selectedTemplate" @click="applyTemplate">
          使用模板
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { getScriptList, getScript, saveScript, deleteScript, getScriptTemplates } from '@/api/script'
import { mapGetters } from 'vuex'

export default {
  name: 'ScriptManager',

  data() {
    return {
      scripts: [],
      loading: false,
      saving: false,
      executing: false,

      // 当前编辑
      currentScript: null,  // null = 没有打开任何脚本
      isNew: false,
      editingName: '',
      editingContent: '',
      
      // 新增：目标节点选择
      targetNodeKey: '', // 选中的节点key（脱敏格式）

      // 语法检查（本地简单检测）
      syntaxError: null,
      syntaxTimer: null,

      // 模板对话框
      templateDialogVisible: false,
      templatesLoading: false,
      templates: [],
      selectedTemplate: null,

      // 任务管理
      tasks: [],
      tasksLoading: false,
      taskPage: 1,
      taskPageSize: 10,
      taskTotal: 0,
      taskTimer: null, // 定时刷新定时器
    }
  },

  computed: {
    ...mapGetters('nodes', ['onlineNodes']), // 获取在线节点列表
    
    lineCount() {
      return (this.editingContent.match(/\n/g) || []).length + 1
    },
    charCount() {
      return this.editingContent.length
    }
  },

  mounted() {
    this.loadScripts()
    this.loadTasks() // 加载任务列表
    
    // 启动定时刷新（每30秒刷新一次）
    this.taskTimer = setInterval(() => {
      this.loadTasks()
    }, 30000)
    
    // 连接 WebSocket 任务通知
    this.initTaskNotifier()
  },

  beforeDestroy() {
    // 清理定时器
    if (this.taskTimer) {
      clearInterval(this.taskTimer)
    }
    
    // 断开 WebSocket 连接
    this.$disconnectTaskNotifier()
  },

  methods: {
    async loadScripts() {
      this.loading = true
      try {
        const res = await getScriptList()
        if (res.code === 200) {
          this.scripts = res.data
        }
      } catch (e) {
        this.$message.error('加载脚本列表失败')
      } finally {
        this.loading = false
      }
    },

    async openScript(name) {
      try {
        const res = await getScript(name)
        if (res.code === 200) {
          this.currentScript = res.data
          this.editingName = res.data.name
          this.editingContent = res.data.content
          this.isNew = false
          this.syntaxError = null
        }
      } catch (e) {
        this.$message.error('读取脚本失败')
      }
    },

    newScript() {
      this.currentScript = {}
      this.isNew = true
      this.editingName = 'new_script.py'
      this.editingContent = '#!/usr/bin/env python3\n"""\n@title 新脚本\n@description 描述脚本功能\n@author Admin\n@version 1.0.0\n"""\n\nimport sys\n\ndef main():\n    print("Hello, OpenClaw!")\n    return 0\n\nif __name__ == "__main__":\n    sys.exit(main())\n'
      this.syntaxError = null
    },

    closeEditor() {
      this.currentScript = null
      this.editingName = ''
      this.editingContent = ''
    },

    async saveScript() {
      if (!this.editingName || !this.editingName.endsWith('.py')) {
        this.$message.warning('文件名必须以 .py 结尾')
        return
      }
      if (!this.editingContent.trim()) {
        this.$message.warning('脚本内容不能为空')
        return
      }

      this.saving = true
      try {
        const res = await saveScript(this.editingName, this.editingContent)
        if (res.code === 200) {
          this.$message.success('脚本保存成功')
          this.isNew = false
          await this.loadScripts()
        } else {
          this.$message.error(res.message || '保存失败')
        }
      } catch (e) {
        this.$message.error('保存脚本失败')
      } finally {
        this.saving = false
      }
    },

    async deleteScript() {
      try {
        await this.$confirm(`确认删除 ${this.editingName}？`, '提示', {
          confirmButtonText: '删除',
          cancelButtonText: '取消',
          type: 'warning'
        })
        const res = await deleteScript(this.editingName)
        if (res.code === 200) {
          this.$message.success('脚本已删除')
          this.closeEditor()
          await this.loadScripts()
        }
      } catch (e) {
        if (e !== 'cancel') this.$message.error('删除失败')
      }
    },

    async showTemplates() {
      this.templateDialogVisible = true
      this.templatesLoading = true
      this.selectedTemplate = null
      try {
        const res = await getScriptTemplates()
        if (res.code === 200) {
          this.templates = res.data
        }
      } finally {
        this.templatesLoading = false
      }
    },

    applyTemplate() {
      const tpl = this.templates.find(t => t.name === this.selectedTemplate)
      if (!tpl) return

      this.currentScript = {}
      this.isNew = true
      this.editingName = tpl.name
      this.editingContent = tpl.content
      this.syntaxError = null
      this.templateDialogVisible = false
    },

    // ===================
    // 任务管理相关方法
    // ===================

    async executeScript() {
      if (!this.editingName.endsWith('.py')) {
        this.$message.error('必须先保存为 .py 文件')
        return
      }
      
      if (this.onlineNodes.length > 0 && !this.targetNodeKey) {
        this.$message.error('请选择目标执行节点')
        return
      }

      this.executing = true
      try {
        const payload = {
          type: 'script',
          payload: {
            script_path: this.editingName,
            args: []
          }
        }
        
        // 如果有选中的节点，添加target_node_key
        if (this.targetNodeKey) {
          payload.target_node_key = this.targetNodeKey
        }
        
        const res = await this.$axios.post('/api/openclaw/bridge/task', payload)

        if (res.data.code === 200) {
          this.$message.success(`脚本已下发执行，任务ID: ${res.data.task_id}`)
          // 立即刷新任务列表
          this.loadTasks()
        } else {
          this.$message.error(res.data.message || '执行失败')
        }
      } catch (e) {
        this.$message.error('执行脚本失败: ' + (e.response?.data?.message || e.message))
      } finally {
        this.executing = false
      }
    },

    async loadTasks() {
      this.tasksLoading = true
      try {
        const res = await this.$axios.get('/api/openclaw/bridge/tasks', {
          params: {
            page: this.taskPage,
            size: this.taskPageSize
          }
        })

        if (res.data.code === 200) {
          this.tasks = res.data.data.tasks || []
          this.taskTotal = res.data.data.total || 0
        }
      } catch (e) {
        // 静默失败，不显示错误信息（避免频繁弹窗）
        this.$logDebug('script-manager', '加载任务列表失败', e)
      } finally {
        this.tasksLoading = false
      }
    },

    async clearCompletedTasks() {
      try {
        await this.$confirm('是否清理所有已完成的任务？', '提示', {
          confirmButtonText: '清理',
          cancelButtonText: '取消',
          type: 'warning'
        })

        const res = await this.$axios.delete('/api/openclaw/bridge/tasks/completed')
        if (res.data.code === 200) {
          this.$message.success('清理成功')
          this.loadTasks()
        }
      } catch (e) {
        if (e !== 'cancel') {
          this.$message.error('清理失败')
        }
      }
    },

    showTaskResult(task) {
      this.$msgbox({
        title: `任务结果 - ${task.task_id.substr(-8)}`,
        message: this.$createElement('pre', {
          style: {
            'max-height': '400px',
            'overflow-y': 'auto',
            'white-space': 'pre-wrap',
            'font-family': 'monospace',
            'font-size': '12px',
            'background': '#f5f5f5',
            'padding': '12px',
            'border-radius': '4px'
          }
        }, JSON.stringify(task.result, null, 2)),
        showCancelButton: false,
        confirmButtonText: '关闭',
        customClass: 'task-result-dialog'
      })
    },

    showTaskError(task) {
      this.$msgbox({
        title: `任务错误 - ${task.task_id.substr(-8)}`,
        message: this.$createElement('pre', {
          style: {
            'max-height': '400px',
            'overflow-y': 'auto',
            'white-space': 'pre-wrap',
            'font-family': 'monospace',
            'font-size': '12px',
            'background': '#fef0f0',
            'color': '#f56c6c',
            'padding': '12px',
            'border-radius': '4px'
          }
        }, task.error),
        showCancelButton: false,
        confirmButtonText: '关闭',
        customClass: 'task-error-dialog'
      })
    },

    getTaskStatusType(status) {
      const statusMap = {
        pending: '',
        running: 'warning',
        success: 'success',
        failed: 'danger',
        timeout: 'danger',
        cancelled: 'info'
      }
      return statusMap[status] || 'info'
    },

    getTaskStatusText(status) {
      const statusMap = {
        pending: '等待中',
        running: '执行中',
        success: '成功',
        failed: '失败',
        timeout: '超时',
        cancelled: '取消'
      }
      return statusMap[status] || status
    },

    formatTime(timestamp) {
      if (!timestamp) return '-'
      const date = new Date(timestamp * 1000)
      return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      })
    },

    handleTaskSizeChange(size) {
      this.taskPageSize = size
      this.taskPage = 1
      this.loadTasks()
    },

    handleTaskCurrentChange(page) {
      this.taskPage = page
      this.loadTasks()
    },

    // ===================
    // WebSocket 通知相关方法
    // ===================

    initTaskNotifier() {
      try {
        // 连接 WebSocket
        this.$connectTaskNotifier('ws://localhost:8282')
        
        // 监听任务错误事件
        this.$taskNotifier.on('task_error', this.handleTaskError)
        
        // 监听任务成功事件
        this.$taskNotifier.on('task_success', this.handleTaskSuccess)
        
        // 监听任务开始事件
        this.$taskNotifier.on('task_started', this.handleTaskStarted)
        
        // 🚀 新增：监听 AI 流式聊天事件
        this.$taskNotifier.on('chat_stream', this.handleChatStream)
        this.$taskNotifier.on('chat_stream_complete', this.handleChatStreamComplete)
        
        // 监听连接状态
        this.$taskNotifier.on('connected', () => {
          this.$logInfo('script-manager', '🟢 任务通知服务已连接')
        })
        
        this.$taskNotifier.on('disconnected', () => {
          this.$logInfo('script-manager', '🔴 任务通知服务已断开')
        })
        
      } catch (e) {
        this.$logError('script-manager', '初始化任务通知失败', e)
      }
    },

    handleTaskError(data) {
      const { taskId, error, scriptPath } = data
      
      this.$logError('script-manager', '❗️ 任务执行失败', data)
      
      // 显示错误通知
      this.$notify.error({
        title: '脚本执行失败',
        message: `脚本 ${scriptPath} 执行失败：${error}`,
        duration: 8000,
        showClose: true,
        onClick: () => {
          // 点击通知显示详细错误
          this.showErrorDetails(data)
        }
      })
      
      // 立即刷新任务列表
      this.loadTasks()
    },

    handleTaskSuccess(data) {
      const { task_id, message, result } = data
      
      this.$logInfo('script-manager', '✅ 任务执行成功', data)
      
      // 显示成功通知
      this.$notify.success({
        title: '脚本执行成功',
        message: message || `任务 ${task_id.substr(-8)} 执行成功`,
        duration: 4000,
        showClose: true
      })
      
      // 刷新任务列表
      this.loadTasks()
    },

    handleTaskStarted(data) {
      const { task_id, message, script_name } = data
      
      this.$logInfo('script-manager', '▶️ 任务开始执行', data)
      
      // 显示开始通知
      this.$notify.info({
        title: '脚本开始执行',
        message: message || `脚本 ${script_name} 开始执行...`,
        duration: 3000
      })
      
      // 刷新任务列表
      this.loadTasks()
    },

    // 🚀 新增：AI 流式聊天事件处理
    handleChatStream(data) {
      const { task_id, content, status } = data
      
      if (status === 'processing' && content) {
        // 流式输出中，实时显示进度
        this.$logInfo('script-manager', `💬 AI流式输出: ${content.length}字符`, {
          task_id: task_id.slice(-8),
          content_preview: content.slice(0, 50)
        })
        
        // 可以在这里处理实时显示逻辑
        // 比如更新UI中的进度条或实时内容
        
      } else if (status === 'completed') {
        this.$logInfo('script-manager', `✅ AI流式输出完成: ${task_id.slice(-8)}`)
      }
    },
    
    handleChatStreamComplete(data) {
      const { taskId } = data
      
      this.$notify.success({
        title: 'AI 会话完成',
        message: `任务 ${taskId.slice(-8)} 的 AI 回答已完成`,
        duration: 4000
      })
      
      // 刷新任务列表
      this.loadTasks()
    },

    showErrorDetails(errorData) {
      const { detail } = errorData
      
      this.$msgbox({
        title: '脚本执行错误详情',
        message: this.$createElement('div', {
          style: { 'font-family': 'monospace', 'white-space': 'pre-wrap' }
        }, [
          this.$createElement('h4', '错误信息:'),
          this.$createElement('div', {
            style: {
              'background': '#fef0f0',
              'color': '#f56c6c',
              'padding': '12px',
              'border-radius': '4px',
              'margin': '8px 0'
            }
          }, detail.error_message),
          
          this.$createElement('h4', '任务信息:'),
          this.$createElement('div', {
            style: {
              'background': '#f5f5f5',
              'padding': '12px',
              'border-radius': '4px',
              'font-size': '12px'
            }
          }, [
            `任务ID: ${detail.task_id}`,
            `\n脚本路径: ${detail.script_path}`,
            `\n任务类型: ${detail.task_type}`,
            `\n重试次数: ${detail.retries}/${detail.max_retries}`
          ].join(''))
        ]),
        showCancelButton: false,
        confirmButtonText: '关闭',
        customClass: 'error-detail-dialog'
      })
    }
  },

  watch: {
    editingContent() {
      // 简单本地语法提示（检查缩进不一致等基础问题）
      clearTimeout(this.syntaxTimer)
      this.syntaxTimer = setTimeout(() => {
        if (this.editingContent.includes('\t') && this.editingContent.includes('    ')) {
          this.syntaxError = '混用 Tab 和空格缩进'
        } else {
          this.syntaxError = null
        }
      }, 500)
    }
  }
}
</script>

<style scoped>
.script-manager { padding: 20px; }

.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
}
.script-count { margin-left: 8px; color: #909399; font-size: 13px; }

.script-list-card { height: calc(100vh - 180px); overflow-y: auto; }
.editor-card { height: calc(100vh - 180px); }

.loading-state, .empty-state {
  text-align: center;
  color: #909399;
  padding: 40px 0;
}
.empty-state i { font-size: 48px; display: block; margin-bottom: 12px; }

.script-list { list-style: none; padding: 0; margin: 0; }

.script-item {
  padding: 10px 12px;
  border-radius: 6px;
  cursor: pointer;
  margin-bottom: 4px;
  border: 1px solid transparent;
  transition: all .2s;
}
.script-item:hover { background: #f5f7fa; }
.script-item.active { background: #ecf5ff; border-color: #b3d8ff; }

.script-item-header { display: flex; align-items: center; gap: 6px; font-weight: 500; }
.script-name { font-size: 13px; }

.script-meta {
  display: flex;
  gap: 12px;
  font-size: 11px;
  color: #C0C4CC;
  margin-top: 4px;
}
.script-desc { font-size: 12px; color: #909399; margin-top: 4px; }

.editor-header { display: flex; justify-content: space-between; align-items: center; }
.editor-actions { display: flex; align-items: center; gap: 4px; }

.editor-placeholder {
  text-align: center;
  color: #C0C4CC;
  padding: 100px 0;
  font-size: 16px;
}
.editor-placeholder i { font-size: 64px; display: block; margin-bottom: 16px; }

.code-editor :deep(textarea) {
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
  font-size: 13px;
  line-height: 1.6;
  background: #1e1e1e;
  color: #d4d4d4;
  border: none;
  border-radius: 4px;
  padding: 12px;
  resize: none;
}

.editor-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
  font-size: 12px;
  color: #909399;
}
.syntax-error { color: #F56C6C; }
.syntax-ok { color: #67C23A; }

.template-item {
  padding: 12px;
  border: 1px solid #EBEEF5;
  border-radius: 6px;
  margin-bottom: 8px;
  cursor: pointer;
}
.template-item:hover { border-color: #409EFF; background: #ecf5ff; }
.tpl-desc { display: block; font-size: 12px; color: #909399; margin-top: 4px; }

/* 任务列表样式 */
.task-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.task-actions {
  display: flex;
  gap: 8px;
}

.task-result {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.task-result.success {
  color: #67c23a;
}

.task-result.error {
  color: #f56c6c;
}

.task-result.running {
  color: #e6a23c;
}

.task-result.pending {
  color: #909399;
}

.task-result i {
  font-size: 14px;
}
</style>