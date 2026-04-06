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

export default {
  name: 'ScriptManager',

  data() {
    return {
      scripts: [],
      loading: false,
      saving: false,

      // 当前编辑
      currentScript: null,  // null = 没有打开任何脚本
      isNew: false,
      editingName: '',
      editingContent: '',

      // 语法检查（本地简单检测）
      syntaxError: null,
      syntaxTimer: null,

      // 模板对话框
      templateDialogVisible: false,
      templatesLoading: false,
      templates: [],
      selectedTemplate: null,
    }
  },

  computed: {
    lineCount() {
      return (this.editingContent.match(/\n/g) || []).length + 1
    },
    charCount() {
      return this.editingContent.length
    }
  },

  mounted() {
    this.loadScripts()
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
</style>