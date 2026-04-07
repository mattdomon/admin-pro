<template>
  <div class="nodes-index">
    <el-card class="box-card">
      <div slot="header" class="clearfix">
        <span><i class="el-icon-s-grid"></i> 节点监控看板</span>
        <div style="float: right;">
          <el-button 
            type="primary" 
            size="small" 
            icon="el-icon-plus"
            @click="showAddNodeDialog = true"
          >
            接入新节点
          </el-button>
          <el-button 
            type="default" 
            size="small" 
            icon="el-icon-refresh"
            @click="refreshNodeList"
            :loading="loading"
          >
            刷新
          </el-button>
        </div>
      </div>

      <!-- 统计信息 -->
      <div class="stats-row">
        <el-row :gutter="20">
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-value">{{ totalNodes }}</div>
              <div class="stat-label">总节点数</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card online">
              <div class="stat-value">{{ onlineCount }}</div>
              <div class="stat-label">在线节点</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card offline">
              <div class="stat-value">{{ totalNodes - onlineCount }}</div>
              <div class="stat-label">离线节点</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-value">{{ wsConnected ? '已连接' : '未连接' }}</div>
              <div class="stat-label">WebSocket</div>
            </div>
          </el-col>
        </el-row>
      </div>

      <!-- 节点列表表格 -->
      <el-table 
        :data="nodeList" 
        v-loading="loading"
        style="width: 100%; margin-top: 20px;"
      >
        <el-table-column prop="node_name" label="节点名称" width="200">
          <template slot-scope="scope">
            <el-tag v-if="!scope.row.node_name" type="info" size="mini">未命名</el-tag>
            <span v-else>{{ scope.row.node_name }}</span>
          </template>
        </el-table-column>
        
        <el-table-column prop="node_key_masked" label="节点Key" width="150">
          <template slot-scope="scope">
            <code style="font-size: 12px; color: #666;">{{ scope.row.node_key_masked }}</code>
          </template>
        </el-table-column>
        
        <el-table-column prop="status" label="状态" width="100">
          <template slot-scope="scope">
            <el-tag 
              :type="scope.row.status === 'online' ? 'success' : 'danger'"
              size="small"
            >
              {{ scope.row.status === 'online' ? '🟢 在线' : '🔴 离线' }}
            </el-tag>
          </template>
        </el-table-column>
        
        <el-table-column prop="last_heartbeat" label="最后心跳" width="180">
          <template slot-scope="scope">
            <span v-if="scope.row.last_heartbeat">
              {{ formatTime(scope.row.last_heartbeat) }}
            </span>
            <el-tag v-else type="info" size="mini">无记录</el-tag>
          </template>
        </el-table-column>
        
        <el-table-column prop="created_at" label="创建时间" width="180">
          <template slot-scope="scope">
            {{ formatTime(scope.row.created_at) }}
          </template>
        </el-table-column>
        
        <el-table-column label="操作" width="200">
          <template slot-scope="scope">
            <el-button 
              type="text" 
              size="small" 
              icon="el-icon-edit"
              @click="editNodeName(scope.row)"
            >
              重命名
            </el-button>
            <el-button 
              type="text" 
              size="small" 
              style="color: #f56c6c;"
              icon="el-icon-delete"
              @click="deleteNode(scope.row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <el-pagination
        v-if="nodeList.length > 0"
        @size-change="handleSizeChange"
        @current-change="handleCurrentChange"
        :current-page="pagination.page"
        :page-sizes="[10, 20, 50, 100]"
        :page-size="pagination.size"
        :total="pagination.total"
        layout="total, sizes, prev, pager, next, jumper"
        style="margin-top: 20px; text-align: right;"
      >
      </el-pagination>
    </el-card>

    <!-- 添加节点对话框 -->
    <AddNodeDialog 
      :visible.sync="showAddNodeDialog"
      @node-added="handleNodeAdded"
    />

    <!-- 重命名对话框 -->
    <el-dialog
      title="重命名节点"
      :visible.sync="renameDialogVisible"
      width="400px"
    >
      <el-form :model="renameForm" label-width="80px">
        <el-form-item label="节点名称">
          <el-input 
            v-model="renameForm.node_name"
            placeholder="请输入节点名称"
            maxlength="50"
            show-word-limit
          ></el-input>
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <el-button @click="renameDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmRename" :loading="renameLoading">
          确定
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { mapState, mapGetters, mapActions } from 'vuex'
import { getNodeList, renameNode, deleteNode as deleteNodeApi } from '@/api/nodes'
import AddNodeDialog from './components/AddNodeDialog.vue'

export default {
  name: 'NodesIndex',
  components: {
    AddNodeDialog
  },
  data() {
    return {
      loading: false,
      showAddNodeDialog: false,
      autoRefreshTimer: null,
      
      // 分页
      pagination: {
        page: 1,
        size: 20,
        total: 0
      },
      
      // 重命名
      renameDialogVisible: false,
      renameLoading: false,
      renameForm: {
        id: null,
        node_name: ''
      }
    }
  },
  
  computed: {
    ...mapState('nodes', ['nodeKeys', 'connected']),
    ...mapGetters('nodes', ['onlineNodes', 'totalNodes', 'onlineCount']),
    
    nodeList() {
      return this.nodeKeys || []
    },
    
    wsConnected() {
      return this.connected
    }
  },
  
  mounted() {
    this.initPage()
  },
  
  beforeDestroy() {
    if (this.autoRefreshTimer) {
      clearInterval(this.autoRefreshTimer)
    }
  },
  
  methods: {
    ...mapActions('nodes', ['connectWs', 'fetchNodeKeys']),
    
    async initPage() {
      // 连接WebSocket
      this.connectWs()
      
      // 获取节点列表
      await this.refreshNodeList()
      
      // 开启自动刷新（每30秒）
      this.startAutoRefresh()
    },
    
    async refreshNodeList() {
      this.loading = true
      try {
        await this.fetchNodeKeys()
        this.pagination.total = this.nodeList.length
        this.$message.success('节点列表已刷新')
      } catch (error) {
        this.$message.error('获取节点列表失败: ' + error.message)
      } finally {
        this.loading = false
      }
    },
    
    startAutoRefresh() {
      this.autoRefreshTimer = setInterval(() => {
        // 静默刷新，不显示消息
        this.fetchNodeKeys().catch(() => {})
      }, 30000)
    },
    
    handleNodeAdded(newNode) {
      this.$message.success(`节点 ${newNode.node_name} 添加成功！`)
      this.refreshNodeList()
    },
    
    // 编辑节点名称
    editNodeName(node) {
      this.renameForm.id = node.id
      this.renameForm.node_name = node.node_name || ''
      this.renameDialogVisible = true
    },
    
    async confirmRename() {
      if (!this.renameForm.node_name.trim()) {
        this.$message.error('请输入节点名称')
        return
      }
      
      this.renameLoading = true
      try {
        await renameNode(this.renameForm.id, this.renameForm.node_name.trim())
        this.$message.success('节点重命名成功')
        this.renameDialogVisible = false
        this.refreshNodeList()
      } catch (error) {
        this.$message.error('重命名失败: ' + error.message)
      } finally {
        this.renameLoading = false
      }
    },
    
    // 删除节点
    deleteNode(node) {
      const nodeName = node.node_name || node.node_key_masked || '该节点'
      this.$confirm(`确认删除节点 ${nodeName}？删除后无法恢复。`, '警告', {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(async () => {
        try {
          await deleteNodeApi(node.id)
          this.$message.success('节点删除成功')
          this.refreshNodeList()
        } catch (error) {
          this.$message.error('删除失败: ' + error.message)
        }
      }).catch(() => {})
    },
    
    // 分页
    handleSizeChange(size) {
      this.pagination.size = size
      this.pagination.page = 1
    },
    
    handleCurrentChange(page) {
      this.pagination.page = page
    },
    
    // 格式化时间
    formatTime(timestamp) {
      if (!timestamp) return '-'
      const date = new Date(timestamp * 1000)
      return date.toLocaleString('zh-CN')
    }
  }
}
</script>

<style scoped>
.nodes-index {
  padding: 20px;
}

.stats-row {
  margin-bottom: 20px;
}

.stat-card {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  padding: 20px;
  border-radius: 8px;
  text-align: center;
  box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.stat-card.online {
  background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}

.stat-card.offline {
  background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
}

.stat-value {
  font-size: 24px;
  font-weight: bold;
  margin-bottom: 5px;
}

.stat-label {
  font-size: 14px;
  opacity: 0.9;
}

.el-table {
  box-shadow: 0 2px 12px 0 rgba(0,0,0,0.1);
}
</style>