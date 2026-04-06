<template>
  <div class="batch-task-manager">
    <!-- 页面头部 -->
    <div class="page-header">
      <h2>批量任务调度</h2>
      <p>高效管理多节点任务执行，支持批量下发、智能调度和模板化配置</p>
    </div>

    <!-- 功能选项卡 -->
    <el-tabs v-model="activeTab" @tab-click="handleTabClick">
      <!-- 批量下发 -->
      <el-tab-pane label="批量下发" name="dispatch">
        <div class="batch-dispatch">
          <el-card>
            <div slot="header">
              <span>新建批量任务</span>
            </div>
            
            <el-form :model="batchForm" label-width="120px">
              <el-form-item label="执行策略">
                <el-radio-group v-model="batchForm.strategy">
                  <el-radio label="parallel">并行执行</el-radio>
                  <el-radio label="sequential">串行执行</el-radio>
                </el-radio-group>
              </el-form-item>
              
              <el-form-item label="目标设备">
                <el-select
                  v-model="batchForm.device_uuids"
                  multiple
                  placeholder="选择目标设备"
                  style="width: 100%"
                >
                  <el-option
                    v-for="device in onlineDevices"
                    :key="device.uuid"
                    :label="`${device.name || device.uuid} (${device.cpu_percent || 0}% CPU)`"
                    :value="device.uuid"
                  />
                </el-select>
              </el-form-item>
              
              <el-form-item label="脚本名称">
                <el-input
                  v-model="batchForm.script_name"
                  placeholder="输入脚本名，如: hello.py"
                />
              </el-form-item>
              
              <el-form-item label="优先级">
                <el-slider
                  v-model="batchForm.priority"
                  :min="1"
                  :max="10"
                  show-stops
                  show-input
                />
              </el-form-item>
              
              <el-form-item label="任务参数">
                <el-input
                  type="textarea"
                  v-model="paramsText"
                  placeholder="JSON格式参数，如: {&quot;input&quot;: &quot;hello&quot;}"
                  :rows="3"
                />
              </el-form-item>
              
              <el-form-item>
                <el-button type="primary" @click="handleBatchDispatch">
                  立即执行
                </el-button>
                <el-button @click="resetBatchForm">重置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </div>
      </el-tab-pane>

      <!-- 智能调度 -->
      <el-tab-pane label="智能调度" name="auto">
        <div class="auto-dispatch">
          <el-card>
            <div slot="header">
              <span>智能负载均衡</span>
              <small>系统自动选择最优设备执行任务</small>
            </div>
            
            <el-form :model="autoForm" label-width="120px">
              <el-form-item label="脚本名称">
                <el-input
                  v-model="autoForm.script_name"
                  placeholder="输入脚本名"
                />
              </el-form-item>
              
              <el-form-item label="任务数量">
                <el-input-number
                  v-model="autoForm.count"
                  :min="1"
                  :max="50"
                  style="width: 200px"
                />
              </el-form-item>
              
              <el-form-item label="优先级">
                <el-slider
                  v-model="autoForm.priority"
                  :min="1"
                  :max="10"
                  show-stops
                  show-input
                />
              </el-form-item>
              
              <el-form-item label="任务参数">
                <el-input
                  type="textarea"
                  v-model="autoParamsText"
                  placeholder="JSON格式参数"
                  :rows="3"
                />
              </el-form-item>
              
              <el-form-item>
                <el-button type="primary" @click="handleAutoDispatch">
                  智能调度
                </el-button>
                <el-button @click="resetAutoForm">重置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
          
          <!-- 设备负载状态 -->
          <el-card style="margin-top: 20px">
            <div slot="header">设备负载状态</div>
            <div class="device-load-grid">
              <div
                v-for="device in onlineDevices"
                :key="device.uuid"
                class="device-load-item"
                :class="getLoadClass(device)"
              >
                <div class="device-name">{{ device.name || device.uuid.slice(0, 8) }}</div>
                <div class="device-stats">
                  <span>CPU: {{ device.cpu_percent || 0 }}%</span>
                  <span>MEM: {{ device.mem_percent || 0 }}%</span>
                </div>
                <div class="running-tasks">运行中: {{ getRunningTaskCount(device.uuid) }}</div>
              </div>
            </div>
          </el-card>
        </div>
      </el-tab-pane>

      <!-- 任务模板 -->
      <el-tab-pane label="任务模板" name="template">
        <div class="task-templates">
          <div class="template-actions">
            <el-button type="primary" @click="showCreateTemplate">
              新建模板
            </el-button>
          </div>
          
          <el-table :data="templates" style="width: 100%">
            <el-table-column prop="name" label="模板名称" />
            <el-table-column prop="description" label="描述" />
            <el-table-column prop="script_name" label="脚本名" />
            <el-table-column prop="priority" label="优先级" />
            <el-table-column label="操作" width="200">
              <template slot-scope="scope">
                <el-button size="mini" @click="useTemplate(scope.row)">
                  使用
                </el-button>
                <el-button size="mini" type="primary" @click="editTemplate(scope.row)">
                  编辑
                </el-button>
                <el-button size="mini" type="danger" @click="deleteTemplate(scope.row.id)">
                  删除
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
      </el-tab-pane>

      <!-- 批次监控 -->
      <el-tab-pane label="批次监控" name="monitor">
        <div class="batch-monitor">
          <el-table :data="batches" style="width: 100%">
            <el-table-column prop="batch_id" label="批次ID" />
            <el-table-column prop="total_tasks" label="总任务数" />
            <el-table-column prop="completed" label="已完成" />
            <el-table-column prop="progress" label="进度">
              <template slot-scope="scope">
                <el-progress :percentage="scope.row.progress" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="150">
              <template slot-scope="scope">
                <el-button size="mini" @click="viewBatchDetail(scope.row.batch_id)">
                  详情
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- 模板编辑对话框 -->
    <el-dialog
      :title="templateDialogTitle"
      :visible.sync="templateDialogVisible"
      width="600px"
    >
      <el-form :model="templateForm" label-width="120px">
        <el-form-item label="模板名称">
          <el-input v-model="templateForm.name" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input type="textarea" v-model="templateForm.description" />
        </el-form-item>
        <el-form-item label="脚本名称">
          <el-input v-model="templateForm.script_name" />
        </el-form-item>
        <el-form-item label="默认参数">
          <el-input
            type="textarea"
            v-model="templateParamsText"
            placeholder="JSON格式默认参数"
            :rows="4"
          />
        </el-form-item>
        <el-form-item label="优先级">
          <el-slider
            v-model="templateForm.priority"
            :min="1"
            :max="10"
            show-stops
            show-input
          />
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button @click="templateDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="saveTemplate">保存</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import {
  batchDispatch,
  autoDispatch,
  getBatchStatus,
  getTemplateList,
  createTemplate,
  updateTemplate,
  deleteTemplate,
  useTemplate
} from '@/api/batch'
import { getDeviceList } from '@/api/device'

export default {
  name: 'BatchTaskManager',
  data() {
    return {
      activeTab: 'dispatch',
      
      // 批量下发表单
      batchForm: {
        device_uuids: [],
        script_name: '',
        priority: 5,
        strategy: 'parallel'
      },
      paramsText: '{}',
      
      // 智能调度表单
      autoForm: {
        script_name: '',
        count: 1,
        priority: 5
      },
      autoParamsText: '{}',
      
      // 数据
      onlineDevices: [],
      templates: [],
      batches: [],
      
      // 模板对话框
      templateDialogVisible: false,
      templateDialogTitle: '新建模板',
      templateForm: {
        name: '',
        description: '',
        script_name: '',
        priority: 5
      },
      templateParamsText: '{}',
      isEditingTemplate: false,
      editingTemplateId: null
    }
  },
  
  mounted() {
    this.loadDevices()
    this.loadTemplates()
  },
  
  methods: {
    async loadDevices() {
      try {
        const response = await getDeviceList()
        this.onlineDevices = response.data.filter(device => device.status === 'online')
      } catch (error) {
        this.$message.error('获取设备列表失败')
      }
    },
    
    async loadTemplates() {
      try {
        const response = await getTemplateList()
        this.templates = response.data
      } catch (error) {
        this.$message.error('获取模板列表失败')
      }
    },
    
    async handleBatchDispatch() {
      try {
        const params = JSON.parse(this.paramsText || '{}')
        const data = {
          ...this.batchForm,
          params_json: params
        }
        
        const response = await batchDispatch(data)
        this.$message.success(`批量任务已创建，批次ID: ${response.data.batch_id}`)
        this.resetBatchForm()
      } catch (error) {
        this.$message.error('批量任务创建失败: ' + (error.message || ''))
      }
    },
    
    async handleAutoDispatch() {
      try {
        const params = JSON.parse(this.autoParamsText || '{}')
        const data = {
          ...this.autoForm,
          params_json: params
        }
        
        const response = await autoDispatch(data)
        this.$message.success(`智能调度完成，批次ID: ${response.data.batch_id}`)
        this.resetAutoForm()
      } catch (error) {
        this.$message.error('智能调度失败: ' + (error.message || ''))
      }
    },
    
    resetBatchForm() {
      this.batchForm = {
        device_uuids: [],
        script_name: '',
        priority: 5,
        strategy: 'parallel'
      }
      this.paramsText = '{}'
    },
    
    resetAutoForm() {
      this.autoForm = {
        script_name: '',
        count: 1,
        priority: 5
      }
      this.autoParamsText = '{}'
    },
    
    getLoadClass(device) {
      const cpu = device.cpu_percent || 0
      if (cpu < 30) return 'load-low'
      if (cpu < 70) return 'load-medium'
      return 'load-high'
    },
    
    getRunningTaskCount(deviceUuid) {
      // TODO: 从store或API获取运行中任务数
      return Math.floor(Math.random() * 3)
    },
    
    showCreateTemplate() {
      this.isEditingTemplate = false
      this.templateForm = {
        name: '',
        description: '',
        script_name: '',
        priority: 5
      }
      this.templateParamsText = '{}'
      this.templateDialogTitle = '新建模板'
      this.templateDialogVisible = true
    },
    
    editTemplate(template) {
      this.isEditingTemplate = true
      this.editingTemplateId = template.id
      this.templateForm = { ...template }
      this.templateParamsText = JSON.stringify(template.default_params || {}, null, 2)
      this.templateDialogTitle = '编辑模板'
      this.templateDialogVisible = true
    },
    
    async saveTemplate() {
      try {
        const params = JSON.parse(this.templateParamsText || '{}')
        const data = {
          ...this.templateForm,
          default_params: params
        }
        
        if (this.isEditingTemplate) {
          await updateTemplate(this.editingTemplateId, data)
          this.$message.success('模板更新成功')
        } else {
          await createTemplate(data)
          this.$message.success('模板创建成功')
        }
        
        this.templateDialogVisible = false
        this.loadTemplates()
      } catch (error) {
        this.$message.error('保存失败: ' + (error.message || ''))
      }
    },
    
    async deleteTemplate(id) {
      try {
        await this.$confirm('确认删除此模板？', '提示')
        await deleteTemplate(id)
        this.$message.success('删除成功')
        this.loadTemplates()
      } catch (error) {
        if (error !== 'cancel') {
          this.$message.error('删除失败')
        }
      }
    },
    
    async useTemplate(template) {
      // TODO: 打开使用模板对话框
      this.$message.info('使用模板功能开发中...')
    },
    
    handleTabClick(tab) {
      if (tab.name === 'template') {
        this.loadTemplates()
      }
    },
    
    viewBatchDetail(batchId) {
      this.$message.info('批次详情功能开发中...')
    }
  }
}
</script>

<style scoped>
.batch-task-manager {
  padding: 20px;
}

.page-header {
  margin-bottom: 20px;
}

.page-header h2 {
  margin: 0 0 8px 0;
  color: #303133;
}

.page-header p {
  margin: 0;
  color: #909399;
  font-size: 14px;
}

.device-load-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}

.device-load-item {
  padding: 16px;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  text-align: center;
}

.device-load-item.load-low {
  border-color: #67c23a;
  background-color: #f0f9ff;
}

.device-load-item.load-medium {
  border-color: #e6a23c;
  background-color: #fdf6ec;
}

.device-load-item.load-high {
  border-color: #f56c6c;
  background-color: #fef0f0;
}

.device-name {
  font-weight: bold;
  margin-bottom: 8px;
}

.device-stats {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: #606266;
  margin-bottom: 4px;
}

.running-tasks {
  font-size: 12px;
  color: #909399;
}

.template-actions {
  margin-bottom: 20px;
}
</style>