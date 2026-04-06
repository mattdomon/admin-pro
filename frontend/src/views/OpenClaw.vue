<template>
  <div class="openclaw-container">
    <h2>OpenClaw 脚本管理</h2>
    
    <el-row :gutter="20">
      <el-col :span="8">
        <el-card>
          <div slot="header">系统状态</div>
          <p>服务状态: {{ status.service_status || '加载中...' }}</p>
          <p>网关状态: {{ status.gateway_status || '加载中...' }}</p>
          <p>版本: {{ status.version || '未知' }}</p>
          <p>脚本数量: {{ status.scripts_count || 0 }}</p>
        </el-card>
      </el-col>
    </el-row>
    
    <el-card style="margin-top: 20px;">
      <div slot="header">
        <span>Python 脚本列表</span>
        <el-button style="float: right;" type="primary" size="small" @click="refreshScripts">
          刷新
        </el-button>
      </div>
      
      <el-table :data="scripts" v-loading="loading">
        <el-table-column prop="name" label="脚本名称" />
        <el-table-column prop="size" label="大小" :formatter="formatSize" />
        <el-table-column prop="modified" label="修改时间" />
        <el-table-column label="操作" width="200">
          <template slot-scope="scope">
            <el-button size="small" type="primary" @click="executeScript(scope.row.name)">
              执行
            </el-button>
            <el-button size="small" type="danger" @click="removeScript(scope.row.name)">
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      
      <div style="margin-top: 20px;">
        <el-upload
          :action="uploadUrl"
          :on-success="handleUploadSuccess"
          :on-error="handleUploadError"
          :show-file-list="false"
        >
          <el-button size="small" type="success">上传脚本</el-button>
        </el-upload>
      </div>
    </el-card>
    
    <el-dialog title="执行结果" :visible.sync="dialogVisible">
      <pre v-if="executeResult">{{ executeResult }}</pre>
    </el-dialog>
  </div>
</template>

<script>
import { getStatus, getScripts, executeScript, deleteScript } from '@/api/openclaw'

export default {
  name: 'OpenClaw',
  data() {
    return {
      status: {},
      scripts: [],
      loading: false,
      dialogVisible: false,
      executeResult: '',
      uploadUrl: (import.meta.env.VITE_API_BASE_URL || '/api') + '/openclaw/upload'
    }
  },
  mounted() {
    this.refreshStatus()
    this.refreshScripts()
  },
  methods: {
    refreshStatus() {
      getStatus().then(res => {
        this.status = res.data
      })
    },
    refreshScripts() {
      this.loading = true
      getScripts().then(res => {
        this.scripts = res.data || []
      }).finally(() => {
        this.loading = false
      })
    },
    executeScript(name) {
      this.$prompt('请输入参数（可选）', '执行脚本', {
        confirmButtonText: '执行',
        cancelButtonText: '取消'
      }).then(({ value }) => {
        executeScript({ script_name: name, params: value || '' }).then(res => {
          this.executeResult = res.data.output || JSON.stringify(res.data, null, 2)
          this.dialogVisible = true
          this.refreshStatus()
        })
      })
    },
    removeScript(name) {
      this.$confirm('确认删除脚本 ' + name + '?', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(() => {
        deleteScript(name).then(() => {
          this.$message.success('删除成功')
          this.refreshScripts()
        })
      })
    },
    handleUploadSuccess() {
      this.$message.success('上传成功')
      this.refreshScripts()
    },
    handleUploadError() {
      this.$message.error('上传失败')
    },
    formatSize(row) {
      const size = row.size
      if (size < 1024) return size + ' B'
      if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB'
      return (size / 1024 / 1024).toFixed(1) + ' MB'
    }
  }
}
</script>

<style scoped>
.openclaw-container {
  padding: 20px;
}
</style>
