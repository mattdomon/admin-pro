<template>
  <div class="agent-manager">
    <!-- 顶部统计 -->
    <el-row :gutter="16" class="stats-row">
      <el-col :span="6">
        <el-card class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background:#e8f4fd"><i class="el-icon-user" style="color:#409EFF"></i></div>
            <div class="stat-info">
              <div class="stat-num">{{ agents.length }}</div>
              <div class="stat-label">Agent 总数</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background:#f0f9eb"><i class="el-icon-circle-check" style="color:#67C23A"></i></div>
            <div class="stat-info">
              <div class="stat-num">{{ onlineCount }}</div>
              <div class="stat-label">已配置</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background:#fdf6ec"><i class="el-icon-connection" style="color:#E6A23C"></i></div>
            <div class="stat-info">
              <div class="stat-num">{{ modelProviderCount }}</div>
              <div class="stat-label">模型提供商</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background:#fef0f0"><i class="el-icon-cpu" style="color:#F56C6C"></i></div>
            <div class="stat-info">
              <div class="stat-num">{{ totalModels }}</div>
              <div class="stat-label">可用模型</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 主内容卡片 -->
    <el-card class="main-card">
      <div slot="header" class="card-header">
        <span><i class="el-icon-s-custom"></i> Agent 管理</span>
        <div class="header-actions">
          <el-button size="small" icon="el-icon-refresh" @click="loadData" :loading="loading">刷新</el-button>
          <el-button size="small" type="primary" icon="el-icon-edit" @click="showBatchDialog = true">批量配置模型</el-button>
        </div>
      </div>

      <!-- Agent 列表 -->
      <el-table :data="agents" v-loading="loading" stripe>
        <el-table-column label="Agent ID" prop="id" width="140">
          <template slot-scope="{ row }">
            <el-tag size="small" type="info">{{ row.id }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="名称" prop="name" width="140" />

        <el-table-column label="主/备用模型" min-width="300">
          <template slot-scope="{ row }">
            <div class="model-config">
              <div class="model-row">
                <el-tag :type="getModelTagType(row.primaryModel)" size="small">
                  <i class="el-icon-star-on"></i> 主模型
                </el-tag>
                <span class="model-name">{{ getModelDisplayName(row.primaryModel) || '未配置' }}</span>
              </div>
              <div class="model-row" v-if="row.fallbackModel">
                <el-tag type="info" size="small">
                  <i class="el-icon-refresh-left"></i> 备用
                </el-tag>
                <span class="model-name">{{ getModelDisplayName(row.fallbackModel) }}</span>
              </div>
              <div class="model-row" v-else>
                <el-tag type="warning" size="small">
                  <i class="el-icon-warning"></i> 无备用
                </el-tag>
                <span class="model-name" style="color: #E6A23C">建议配置备用模型</span>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="工作空间" min-width="200">
          <template slot-scope="{ row }">
            <span class="path-text">{{ row.workspace }}</span>
          </template>
        </el-table-column>

        <el-table-column label="绑定渠道" width="120">
          <template slot-scope="{ row }">
            <div v-if="row.binding">
              <el-tag size="mini" type="success">{{ row.binding.match.channel }}</el-tag>
              <span style="font-size:11px;color:#999;display:block">{{ row.binding.match.accountId }}</span>
            </div>
            <span v-else style="color:#999;font-size:12px">未绑定</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="160" fixed="right">
          <template slot-scope="{ row }">
          <el-button size="mini" type="primary" icon="el-icon-edit" @click="openModelDialog(row)">配置主/备用模型</el-button>
            <el-button size="mini" icon="el-icon-info" @click="openDetailDialog(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 配置单个 Agent 主/备用模型弹窗 -->
    <el-dialog
      :title="`配置主/备用模型 — ${currentAgent.id}`"
      :visible.sync="showModelDialog"
      width="680px"
      @close="resetModelForm">
      <el-form :model="modelForm" label-width="100px" v-if="currentAgent.id">
        <el-form-item label="Agent">
          <el-tag>{{ currentAgent.id }}</el-tag>
          <span style="margin-left:8px;color:#666">{{ currentAgent.name }}</span>
        </el-form-item>
        
        <el-divider content-position="left">
          <i class="el-icon-star-on" style="color: #F7BA2A"></i> 主模型配置
        </el-divider>
        
        <el-form-item label="当前主模型">
          <el-tag type="success" size="small">{{ currentAgent.primaryModel || '未配置' }}</el-tag>
        </el-form-item>
        
        <el-form-item label="新主模型" required>
          <el-select
            v-model="modelForm.primaryModel"
            placeholder="选择主模型"
            filterable
            style="width:100%">
            <el-option-group
              v-for="(group, provider) in groupedModels"
              :key="provider"
              :label="provider">
              <el-option
                v-for="m in group"
                :key="m.fullId"
                :label="m.name + ' (' + m.id + ')'"
                :value="m.fullId">
                <span>{{ m.name }}</span>
                <span style="float:right;color:#aaa;font-size:12px">{{ m.id }}</span>
              </el-option>
            </el-option-group>
          </el-select>
          <el-alert
            v-if="modelForm.primaryModel"
            :title="'主模型: ' + modelForm.primaryModel"
            type="success"
            :closable="false"
            show-icon
            style="margin-top: 8px">
          </el-alert>
        </el-form-item>
        
        <el-divider content-position="left">
          <i class="el-icon-refresh-left" style="color: #909399"></i> 备用模型配置
        </el-divider>
        
        <el-form-item label="当前备用模型">
          <el-tag v-if="currentAgent.fallbackModel" type="info" size="small">{{ currentAgent.fallbackModel }}</el-tag>
          <span v-else style="color: #999">未配置</span>
        </el-form-item>
        
        <el-form-item label="启用备用模型">
          <el-switch
            v-model="modelForm.enableFallback"
            active-text="启用"
            inactive-text="禁用">
          </el-switch>
          <div style="color: #999; font-size: 12px; margin-top: 4px">
            当主模型不可用时，自动切换到备用模型
          </div>
        </el-form-item>
        
        <el-form-item v-if="modelForm.enableFallback" label="备用模型">
          <el-select
            v-model="modelForm.fallbackModel"
            placeholder="选择备用模型（建议选择不同提供商的模型）"
            filterable
            clearable
            style="width:100%">
            <el-option-group
              v-for="(group, provider) in groupedModels"
              :key="provider"
              :label="provider">
              <el-option
                v-for="m in group"
                :key="m.fullId"
                :label="m.name + ' (' + m.id + ')'"
                :value="m.fullId"
                :disabled="m.fullId === modelForm.primaryModel">
                <span>{{ m.name }}</span>
                <span style="float:right;color:#aaa;font-size:12px">{{ m.id }}</span>
              </el-option>
            </el-option-group>
          </el-select>
          <el-alert
            v-if="modelForm.fallbackModel"
            :title="'备用模型: ' + modelForm.fallbackModel"
            type="info"
            :closable="false"
            show-icon
            style="margin-top: 8px">
          </el-alert>
        </el-form-item>
        
        <el-form-item v-if="modelForm.enableFallback" label="切换策略">
          <el-radio-group v-model="modelForm.fallbackStrategy">
            <el-radio label="auto">自动切换（推荐）</el-radio>
            <el-radio label="manual">手动切换</el-radio>
          </el-radio-group>
          <div style="color: #999; font-size: 12px; margin-top: 4px">
            自动切换：检测到主模型故障时立即切换；手动切换：需要手动指定使用备用模型
          </div>
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button @click="showModelDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveModel">
          <i class="el-icon-check"></i> 保存配置
        </el-button>
      </div>
    </el-dialog>

    <!-- 批量配置模型弹窗 -->
    <el-dialog title="批量配置 Agent 模型" :visible.sync="showBatchDialog" width="700px">
      <el-alert
        title="批量配置将同时修改所选 Agent 的模型，请谨慎操作"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom:16px">
      </el-alert>
      <el-table :data="batchList" border>
        <el-table-column label="Agent ID" prop="id" width="130">
          <template slot-scope="{ row }">
            <el-checkbox v-model="row.selected">{{ row.id }}</el-checkbox>
          </template>
        </el-table-column>
        <el-table-column label="名称" prop="name" width="120" />
        <el-table-column label="当前模型" min-width="160">
          <template slot-scope="{ row }">
            <el-tag size="small" type="warning">{{ row.model }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="新模型" min-width="200">
          <template slot-scope="{ row }">
            <el-select
              v-model="row.newModel"
              placeholder="选择模型"
              filterable
              size="small"
              style="width:100%"
              :disabled="!row.selected">
              <el-option-group
                v-for="(group, provider) in groupedModels"
                :key="provider"
                :label="provider">
                <el-option
                  v-for="m in group"
                  :key="m.fullId"
                  :label="m.name"
                  :value="m.fullId" />
              </el-option-group>
            </el-select>
          </template>
        </el-table-column>
      </el-table>
      <div slot="footer">
        <el-button @click="showBatchDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveBatch">批量保存</el-button>
      </div>
    </el-dialog>

    <!-- Agent 详情弹窗 -->
    <el-dialog :title="`Agent 详情 — ${detailAgent.id}`" :visible.sync="showDetailDialog" width="620px">
      <el-descriptions v-if="detailAgent.id" :column="2" border>
        <el-descriptions-item label="Agent ID">
          <el-tag>{{ detailAgent.id }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="名称">{{ detailAgent.name }}</el-descriptions-item>
        <el-descriptions-item label="主模型" :span="2">
          <el-tag type="success">{{ detailAgent.primaryModel || '未配置' }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="备用模型" :span="2">
          <el-tag v-if="detailAgent.fallbackModel" type="info">{{ detailAgent.fallbackModel }}</el-tag>
          <span v-else style="color: #999">未配置</span>
        </el-descriptions-item>
        <el-descriptions-item label="工作空间" :span="2">
          <span class="path-text">{{ detailAgent.workspace }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="Agent 目录" :span="2">
          <span class="path-text">{{ detailAgent.agentDir }}</span>
        </el-descriptions-item>
        <el-descriptions-item v-if="detailAgent.binding" label="绑定渠道">
          {{ detailAgent.binding.match.channel }} / {{ detailAgent.binding.match.accountId }}
        </el-descriptions-item>
        <el-descriptions-item v-if="detailAgent.binding" label="绑定群组">
          {{ detailAgent.binding.match.peer && detailAgent.binding.match.peer.id || '-' }}
        </el-descriptions-item>
      </el-descriptions>

      <div v-if="detailLoading" style="text-align:center;padding:20px">
        <i class="el-icon-loading"></i> 加载详情中...
      </div>

      <template v-if="detailInfo">
        <el-divider>工作空间统计</el-divider>
        <el-row :gutter="12" v-if="detailInfo.workspaceStats">
          <el-col :span="8">
            <el-statistic title="文件数" :value="detailInfo.workspaceStats.files" />
          </el-col>
          <el-col :span="8">
            <el-statistic title="大小" :value="detailInfo.workspaceStats.sizeFormatted" />
          </el-col>
          <el-col :span="8">
            <el-statistic title="最后修改" :value="detailInfo.workspaceStats.lastModifiedFormatted" />
          </el-col>
        </el-row>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { getAgentList, getModelList, updateAgentModel, batchUpdateAgents, getAgentDetail } from '@/api/agent'

export default {
  name: 'AgentManager',
  data() {
    return {
      loading: false,
      saving: false,
      detailLoading: false,

      agents: [],
      models: [],

      // 单个配置弹窗
      showModelDialog: false,
      currentAgent: {},
      modelForm: {
        primaryModel: '',
        fallbackModel: '',
        enableFallback: false,
        fallbackStrategy: 'auto'
      },

      // 批量配置弹窗
      showBatchDialog: false,
      batchList: [],

      // 详情弹窗
      showDetailDialog: false,
      detailAgent: {},
      detailInfo: null
    }
  },
  computed: {
    onlineCount() {
      return this.agents.filter(a => a.model).length
    },
    groupedModels() {
      const groups = {}
      this.models.forEach(m => {
        if (!groups[m.provider]) groups[m.provider] = []
        groups[m.provider].push(m)
      })
      return groups
    },
    modelProviderCount() {
      return Object.keys(this.groupedModels).length
    },
    totalModels() {
      return this.models.length
    }
  },
  mounted() {
    this.loadData()
  },
  methods: {
    async loadData() {
      this.loading = true
      try {
        const [agentRes, modelRes] = await Promise.all([
          getAgentList(),
          getModelList()
        ])
        if (agentRes.code === 200) this.agents = agentRes.data.agents || []
        if (modelRes.code === 200) this.models = modelRes.data || []
      } catch (e) {
        this.$message.error('加载数据失败: ' + e.message)
      } finally {
        this.loading = false
      }
    },

    openModelDialog(agent) {
      this.currentAgent = { ...agent }
      this.modelForm = {
        primaryModel: agent.primaryModel || agent.model || '',
        fallbackModel: agent.fallbackModel || '',
        enableFallback: !!agent.fallbackModel,
        fallbackStrategy: agent.fallbackStrategy || 'auto'
      }
      this.showModelDialog = true
    },

    resetModelForm() {
      this.currentAgent = {}
      this.modelForm = {
        primaryModel: '',
        fallbackModel: '',
        enableFallback: false,
        fallbackStrategy: 'auto'
      }
    },

    async saveModel() {
      if (!this.modelForm.primaryModel) {
        this.$message.warning('请选择主模型')
        return
      }
      
      if (this.modelForm.enableFallback && !this.modelForm.fallbackModel) {
        this.$message.warning('启用备用模型时，请选择备用模型')
        return
      }
      
      if (this.modelForm.primaryModel === this.modelForm.fallbackModel) {
        this.$message.warning('主模型和备用模型不能相同')
        return
      }
      
      this.saving = true
      try {
        const requestData = {
          agent_id: this.currentAgent.id,
          primary_model: this.modelForm.primaryModel,
          fallback_model: this.modelForm.enableFallback ? this.modelForm.fallbackModel : null,
          fallback_strategy: this.modelForm.enableFallback ? this.modelForm.fallbackStrategy : null
        }
        
        const res = await updateAgentModel(requestData)
        if (res.code === 200) {
          this.$message({
            type: 'success',
            message: '主/备用模型配置已更新',
            duration: 3000
          })
          this.showModelDialog = false
          await this.loadData()
        } else {
          this.$message.error(res.message)
        }
      } catch (e) {
        this.$message.error('保存失败: ' + e.message)
      } finally {
        this.saving = false
      }
    },

    // 批量配置
    openBatchDialog() {
      this.batchList = this.agents.map(a => ({ ...a, selected: false, newModel: a.model }))
      this.showBatchDialog = true
    },

    async saveBatch() {
      const updates = this.batchList
        .filter(a => a.selected && a.newModel && a.newModel !== a.model)
        .map(a => ({ agent_id: a.id, model: a.newModel }))

      if (updates.length === 0) {
        this.$message.warning('请选择 Agent 并选择新模型')
        return
      }

      this.saving = true
      try {
        const res = await batchUpdateAgents({ updates })
        if (res.code === 200) {
          this.$message.success(res.message)
          this.showBatchDialog = false
          await this.loadData()
        } else {
          this.$message.error(res.message)
        }
      } catch (e) {
        this.$message.error('批量保存失败: ' + e.message)
      } finally {
        this.saving = false
      }
    },

    // 详情
    async openDetailDialog(agent) {
      this.detailAgent = { ...agent }
      this.detailInfo = null
      this.showDetailDialog = true
      this.detailLoading = true
      try {
        const res = await getAgentDetail(agent.id)
        if (res.code === 200) this.detailInfo = res.data
      } catch (e) {
        this.$message.error('获取详情失败')
      } finally {
        this.detailLoading = false
      }
    },

    getModelDisplayName(model) {
      if (!model) return '未配置'
      const parts = model.split('/')
      return parts[parts.length - 1]
    },

    getModelTagType(model) {
      if (!model) return 'danger'
      if (model.includes('claude')) return 'success'
      if (model.includes('gpt')) return 'primary'
      if (model.includes('gemini')) return 'warning'
      if (model.includes('minimax') || model.includes('MiniMax')) return ''
      return 'info'
    }
  },
  watch: {
    // 打开批量弹窗时同步列表
    showBatchDialog(val) {
      if (val) {
        this.batchList = this.agents.map(a => ({ ...a, selected: false, newModel: a.model }))
      }
    }
  }
}
</script>

<style scoped>
.agent-manager { padding: 0; }

.stats-row { margin-bottom: 16px; }
.stat-card { border-radius: 8px; }
.stat-inner { display: flex; align-items: center; gap: 14px; }
.stat-icon {
  width: 48px; height: 48px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
}
.stat-icon i { font-size: 22px; }
.stat-num { font-size: 24px; font-weight: 700; color: #303133; }
.stat-label { font-size: 12px; color: #909399; margin-top: 2px; }

.main-card { border-radius: 8px; }
.card-header {
  display: flex; justify-content: space-between; align-items: center;
  font-weight: 600; font-size: 15px;
}
.card-header i { margin-right: 6px; color: #409EFF; }

.model-cell { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
.model-config {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.model-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.model-name {
  font-size: 13px;
  color: #333;
  font-weight: 500;
}
.path-text { font-size: 12px; color: #666; font-family: monospace; word-break: break-all; }

.el-descriptions { margin-top: 8px; }
</style>
