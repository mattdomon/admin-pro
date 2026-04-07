export default {
  name: 'TaskDispatcher',
  data() {
    return {
      form: {
        type: 'script', // script | openclaw | custom
        scriptName: '',
        agentId: 'hc-coding', 
        message: '',
        targetNodeKey: '', // 目标节点
        params: '{"message": "测试消息"}'
      },
      submitting: false,
      scripts: [], // 可用脚本列表
      scriptsLoading: false
    }
  },
  computed: {
    ...mapGetters('nodes', ['onlineNodes']),
    hasOnlineNodes() {
      return this.onlineNodes.length > 0
    }
  },
  mounted() {
    // 初始化：连接WebSocket + 获取节点列表
    this.$store.dispatch('nodes/connectWs')
    this.$store.dispatch('nodes/fetchNodeKeys')
    this.loadScripts()
  },
  methods: {
    ...mapActions('nodes', ['fetchNodeKeys']),
    
    async loadScripts() {
      this.scriptsLoading = true
      try {
        const res = await this.$axios.get('/api/openclaw/scripts')
        this.scripts = res.data.scripts || []
      } catch (e) {
        this.$message.error('加载脚本列表失败')
      } finally {
        this.scriptsLoading = false
      }
    },
    
    async submitTask() {
      // 验证必选字段
      if (!this.form.targetNodeKey && this.hasOnlineNodes) {
        this.$message.error('请选择目标执行节点')
        return
      }
      
      if (this.form.type === 'script' && !this.form.scriptName) {
        this.$message.error('请选择要执行的脚本')
        return
      }
      
      if (this.form.type === 'openclaw' && !this.form.message) {
        this.$message.error('请输入要发送的消息')
        return
      }
      
      this.submitting = true
      try {
        let payload = {}
        
        if (this.form.type === 'script') {
          payload = {
            type: 'script',
            target_node_key: this.form.targetNodeKey,
            payload: {
              script_path: this.form.scriptName,
              args: this.form.params ? JSON.parse(this.form.params) : []
            }
          }
        } else if (this.form.type === 'openclaw') {
          payload = {
            type: 'openclaw',
            target_node_key: this.form.targetNodeKey, 
            payload: {
              agent_id: this.form.agentId,
              message: this.form.message
            }
          }
        }
        
        const res = await this.$axios.post('/api/openclaw/bridge/task', payload)
        
        if (res.data.code === 200) {
          this.$message.success(`任务已提交！任务ID: ${res.data.data.task_id}`)
          this.$emit('task-submitted', res.data.data)
        } else {
          this.$message.error(res.data.message || '提交失败')
        }
      } catch (e) {
        this.$message.error('提交任务失败: ' + (e.response?.data?.message || e.message))
      } finally {
        this.submitting = false
      }
    }
  }
}