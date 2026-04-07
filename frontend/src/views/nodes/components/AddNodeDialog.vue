<template>
  <el-dialog
    title="接入新节点"
    :visible.sync="dialogVisible"
    width="600px"
    :close-on-click-modal="false"
    @close="handleClose"
  >
    <div class="add-node-content">
      <!-- 步骤指引 -->
      <div class="steps-guide">
        <el-steps :active="currentStep" finish-status="success" align-center>
          <el-step title="下载运行" description="在目标设备运行中转器"></el-step>
          <el-step title="浏览器访问" description="打开本地管理页面"></el-step>
          <el-step title="账号绑定" description="输入云端账号完成绑定"></el-step>
        </el-steps>
      </div>

      <!-- 详细步骤说明 -->
      <div class="step-details">
        <div class="step-item">
          <div class="step-header">
            <i class="el-icon-download"></i>
            <span class="step-title">第一步：在目标设备下载并运行中转器程序</span>
          </div>
          <div class="step-content">
            <el-alert
              title="请在需要接入的设备上执行以下命令："
              type="info"
              :closable="false"
              show-icon
            >
            </el-alert>
            <div class="code-block">
              <pre><code># 下载 bridge.py 中转器
curl -O https://your-domain.com/bridge.py

# 安装依赖
pip install websockets asyncio

# 运行中转器（后台运行）
python bridge.py &</code></pre>
              <el-button 
                type="text" 
                icon="el-icon-document-copy"
                @click="copyToClipboard($event.target.previousElementSibling.textContent)"
                style="position: absolute; top: 10px; right: 10px;"
              >
                复制
              </el-button>
            </div>
          </div>
        </div>

        <div class="step-item">
          <div class="step-header">
            <i class="el-icon-monitor"></i>
            <span class="step-title">第二步：在目标设备的浏览器中打开管理页面</span>
          </div>
          <div class="step-content">
            <el-alert
              title="bridge.py 运行后会启动本地Web服务，请访问："
              type="success"
              :closable="false"
              show-icon
            >
            </el-alert>
            <div class="url-block">
              <el-input
                value="http://127.0.0.1:8888"
                readonly
                size="medium"
              >
                <template slot="append">
                  <el-button 
                    icon="el-icon-document-copy"
                    @click="copyToClipboard('http://127.0.0.1:8888')"
                  >
                    复制
                  </el-button>
                </template>
              </el-input>
            </div>
          </div>
        </div>

        <div class="step-item">
          <div class="step-header">
            <i class="el-icon-key"></i>
            <span class="step-title">第三步：输入您的云端账号密码，点击绑定</span>
          </div>
          <div class="step-content">
            <el-alert
              title="在弹出的页面中输入当前登录的账号密码，完成节点绑定"
              type="warning"
              :closable="false"
              show-icon
            >
            </el-alert>
            <p style="color: #666; font-size: 14px; margin: 10px 0;">
              💡 提示：绑定成功后，该设备将自动获得执行权限，无需手动配置密钥
            </p>
          </div>
        </div>
      </div>

      <!-- 魔法交互：雷达扫描 -->
      <div class="radar-section">
        <div class="radar-container">
          <div class="radar-circle" :class="{ 'scanning': isScanning }">
            <div class="radar-dot"></div>
            <div class="radar-sweep" v-if="isScanning"></div>
          </div>
          <div class="radar-text">
            <span v-if="!isScanning && !successNode">点击开始监听新节点接入...</span>
            <span v-if="isScanning && !successNode">🔍 正在监听新节点接入...</span>
            <span v-if="successNode" class="success-text">
              🎉 节点 {{ successNode.node_name }} 接入成功！
            </span>
          </div>
        </div>
        
        <div class="radar-controls">
          <el-button 
            v-if="!isScanning && !successNode"
            type="primary" 
            @click="startScanning"
            icon="el-icon-search"
          >
            开始监听
          </el-button>
          <el-button 
            v-if="isScanning && !successNode"
            type="danger" 
            @click="stopScanning"
            icon="el-icon-close"
          >
            停止监听
          </el-button>
        </div>
      </div>
    </div>

    <div slot="footer" class="dialog-footer">
      <el-button @click="handleClose">
        {{ successNode ? '完成' : '取消' }}
      </el-button>
      <el-button 
        v-if="!successNode" 
        type="primary" 
        @click="openHelpDoc"
        icon="el-icon-document"
      >
        查看详细文档
      </el-button>
    </div>
  </el-dialog>
</template>

<script>
import { mapState } from 'vuex'

export default {
  name: 'AddNodeDialog',
  props: {
    visible: {
      type: Boolean,
      default: false
    }
  },
  
  data() {
    return {
      currentStep: 0,
      isScanning: false,
      successNode: null,
      scanningTimer: null,
      onlineEventWatcher: null
    }
  },
  
  computed: {
    ...mapState('nodes', ['onlineEvent']),
    
    dialogVisible: {
      get() {
        return this.visible
      },
      set(val) {
        this.$emit('update:visible', val)
      }
    }
  },
  
  watch: {
    // 监听 onlineEvent 变化
    onlineEvent: {
      handler(newEvent) {
        if (this.isScanning && newEvent && newEvent._ts) {
          // 收到新节点上线事件
          this.handleNodeOnline(newEvent)
        }
      },
      deep: true
    },
    
    // 监听对话框显示状态
    visible(show) {
      if (show) {
        this.resetDialog()
      }
    }
  },
  
  methods: {
    resetDialog() {
      this.currentStep = 0
      this.isScanning = false
      this.successNode = null
      this.stopScanning()
    },
    
    startScanning() {
      this.isScanning = true
      this.currentStep = 2 // 进入第三步
      
      // 可选：设置扫描超时（5分钟）
      this.scanningTimer = setTimeout(() => {
        if (this.isScanning) {
          this.stopScanning()
          this.$message.warning('监听超时，请检查设备是否正确执行了绑定操作')
        }
      }, 5 * 60 * 1000)
    },
    
    stopScanning() {
      this.isScanning = false
      if (this.scanningTimer) {
        clearTimeout(this.scanningTimer)
        this.scanningTimer = null
      }
    },
    
    handleNodeOnline(event) {
      // 节点上线成功
      this.stopScanning()
      this.successNode = {
        node_name: event.node_name || '新节点',
        node_key: event.node_key
      }
      
      // 2秒后自动关闭对话框
      setTimeout(() => {
        this.handleClose()
      }, 2000)
    },
    
    handleClose() {
      this.stopScanning()
      
      if (this.successNode) {
        // 通知父组件有新节点添加
        this.$emit('node-added', this.successNode)
      }
      
      this.dialogVisible = false
      this.resetDialog()
    },
    
    copyToClipboard(text) {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          this.$message.success('已复制到剪贴板')
        }).catch(() => {
          this.fallbackCopy(text)
        })
      } else {
        this.fallbackCopy(text)
      }
    },
    
    fallbackCopy(text) {
      const textarea = document.createElement('textarea')
      textarea.value = text
      document.body.appendChild(textarea)
      textarea.select()
      try {
        document.execCommand('copy')
        this.$message.success('已复制到剪贴板')
      } catch (err) {
        this.$message.error('复制失败，请手动复制')
      }
      document.body.removeChild(textarea)
    },
    
    openHelpDoc() {
      // 打开帮助文档或详细说明
      this.$message.info('功能开发中...')
    }
  }
}
</script>

<style scoped>
.add-node-content {
  padding: 20px 0;
}

.steps-guide {
  margin-bottom: 30px;
}

.step-details {
  margin-bottom: 30px;
}

.step-item {
  margin-bottom: 25px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 15px;
}

.step-header {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
}

.step-header i {
  font-size: 18px;
  color: #409EFF;
  margin-right: 8px;
}

.step-title {
  font-weight: bold;
  font-size: 14px;
}

.step-content {
  margin-left: 26px;
}

.code-block {
  position: relative;
  margin-top: 10px;
}

.code-block pre {
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 15px;
  margin: 0;
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 12px;
  line-height: 1.4;
  color: #333;
}

.url-block {
  margin-top: 10px;
}

.radar-section {
  text-align: center;
  padding: 30px 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 8px;
  color: white;
}

.radar-container {
  margin-bottom: 20px;
}

.radar-circle {
  width: 120px;
  height: 120px;
  border: 3px solid rgba(255,255,255,0.3);
  border-radius: 50%;
  margin: 0 auto 15px;
  position: relative;
  background: rgba(255,255,255,0.1);
  overflow: hidden;
}

.radar-circle.scanning {
  animation: radar-pulse 2s infinite;
}

.radar-dot {
  width: 8px;
  height: 8px;
  background: #fff;
  border-radius: 50%;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 10px rgba(255,255,255,0.8);
}

.radar-sweep {
  width: 50%;
  height: 2px;
  background: linear-gradient(to right, transparent, rgba(255,255,255,0.8));
  position: absolute;
  top: 50%;
  left: 50%;
  transform-origin: 0 50%;
  animation: radar-sweep 2s linear infinite;
}

.radar-text {
  font-size: 16px;
  min-height: 24px;
}

.success-text {
  color: #67C23A;
  font-weight: bold;
}

@keyframes radar-pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4);
  }
  70% {
    box-shadow: 0 0 0 20px rgba(255, 255, 255, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
  }
}

@keyframes radar-sweep {
  0% {
    transform: rotate(0deg);
  }
  100% {
    transform: rotate(360deg);
  }
}
</style>