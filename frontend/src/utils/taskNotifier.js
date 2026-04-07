/**
 * WebSocket 任务通知客户端
 * 用于接收来自 bridge.py 的实时任务状态通知
 */

class TaskNotificationClient {
  constructor() {
    this.ws = null
    this.isConnected = false
    this.reconnectAttempts = 0
    this.maxReconnectAttempts = 10
    this.reconnectDelay = 1000  // 1秒，指数退避
    this.listeners = new Map()  // 事件监听器
    this.heartbeatTimer = null
    
    this.nodeId = `web_client_${Date.now()}`
  }
  
  /**
   * 连接WebSocket
   */
  connect(url = 'ws://localhost:8282') {
    try {
      this.ws = new WebSocket(url)
      
      this.ws.onopen = () => {
        if (window.Vue && window.Vue.prototype.$logInfo) {
          window.Vue.prototype.$logInfo('websocket', '🔗 WebSocket 连接成功')
        } else {
          console.log('🔗 WebSocket 连接成功')
        }
        this.isConnected = true
        this.reconnectAttempts = 0
        this.startHeartbeat()
        this.registerClient()
        this.emit('connected')
      }
      
      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data)
          this.handleMessage(data)
        } catch (e) {
          console.warn('无效的WebSocket消息:', event.data)
        }
      }
      
      this.ws.onclose = (event) => {
        console.log('🔌 WebSocket 连接关闭', event.code, event.reason)
        this.isConnected = false
        this.stopHeartbeat()
        this.emit('disconnected')
        
        // 自动重连
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
          this.scheduleReconnect()
        }
      }
      
      this.ws.onerror = (error) => {
        console.error('❌ WebSocket 错误:', error)
        this.emit('error', error)
      }
      
    } catch (error) {
      console.error('WebSocket 连接失败:', error)
      this.scheduleReconnect()
    }
  }
  
  /**
   * 断开WebSocket
   */
  disconnect() {
    this.stopHeartbeat()
    if (this.ws) {
      this.ws.close()
      this.ws = null
    }
    this.isConnected = false
  }
  
  /**
   * 注册客户端
   */
  registerClient() {
    if (!this.isConnected) return
    
    // 🚀 修改：使用 PHP Gateway 期待的格式
    const token = localStorage.getItem('token')
    if (!token) {
      console.warn('⚠️ 未找到用户 token，无法注册 WebSocket 客户端')
      return
    }
    
    const registerMsg = {
      type: 'web_register',  // 改为 PHP Events.php 期待的类型
      token: token          // 使用用户 token 进行身份验证
    }
    
    this.send(registerMsg)
    console.log('📝 已发送 Web 前端注册消息')
  }
  
  /**
   * 发送消息
   */
  send(message) {
    if (this.isConnected && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(message))
      return true
    }
    console.warn('WebSocket 未连接，无法发送消息')
    return false
  }
  
  /**
   * 处理收到的消息
   */
  handleMessage(data) {
    const { type } = data
    
    switch (type) {
      case 'pong':
        // 心跳响应
        break
        
      case 'task_notification':
        this.handleTaskNotification(data)
        break
      
      case 'chat_stream':  // 🚀 新增：流式聊天数据处理
        this.handleChatStream(data)
        break
        
      case 'register_response':
        console.log('✅ 客户端注册成功:', data.message)
        break
        
      case 'web_registered':  // 🚀 新增：处理 Web 前端注册成功响应
        console.log('✅ Web 前端注册成功，在线节点:', data.nodes?.length || 0)
        this.emit('web_registered', data)
        break
        
      case 'node_online':   // 🚀 新增：节点上线通知
      case 'node_offline':  // 🚀 新增：节点下线通知
        console.log(`🔄 节点状态变化: ${type}`, data.node_name)
        this.emit(type, data)
        break
        
      default:
        console.log('📨 收到消息:', data)
        this.emit('message', data)
    }
  }
  
  /**
   * 处理流式聊天数据（新增）
   */
  handleChatStream(data) {
    const { task_id, content, status } = data
    
    if (status === 'processing' && content) {
      // 增量数据，实时显示
      console.log(`💬 流式输出 [${task_id.slice(0, 8)}]: ${content}`)
      this.emit('chat_stream_chunk', {
        taskId: task_id,
        content: content,
        status: status
      })
    } else if (status === 'completed') {
      // 对话结束
      console.log(`✅ 流式输出完成: ${task_id.slice(0, 8)}`)
      this.emit('chat_stream_complete', {
        taskId: task_id,
        status: status
      })
    }
    
    // 触发通用流事件，供前端监听
    this.emit('chat_stream', data)
  }
  
  /**
   * 处理任务通知
   */
  handleTaskNotification(data) {
    const { event_type, task_id, message, error_detail } = data
    
    console.log(`📋 任务通知 [${event_type}]:`, message)
    
    // 触发对应事件
    this.emit('task_notification', data)
    this.emit(`task_${event_type}`, data)
    
    // 特殊处理失败通知
    if (event_type === 'failed' && error_detail) {
      this.emit('task_error', {
        taskId: task_id,
        error: error_detail.error_message,
        scriptPath: error_detail.script_path,
        detail: error_detail
      })
    }
  }
  
  /**
   * 心跳机制
   */
  startHeartbeat() {
    this.stopHeartbeat()
    this.heartbeatTimer = setInterval(() => {
      if (this.isConnected) {
        const pingMsg = {
          type: 'ping',
          node_id: this.nodeId,
          timestamp: Date.now() / 1000
        }
        this.send(pingMsg)
      }
    }, 30000) // 30秒心跳
  }
  
  stopHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }
  
  /**
   * 自动重连
   */
  scheduleReconnect() {
    this.reconnectAttempts++
    const delay = Math.min(
      this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1),
      30000 // 最大30秒
    )
    
    console.log(`🔄 ${delay}ms 后尝试重连 (第${this.reconnectAttempts}次)`)
    
    setTimeout(() => {
      this.connect()
    }, delay)
  }
  
  /**
   * 事件监听
   */
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, [])
    }
    this.listeners.get(event).push(callback)
  }
  
  /**
   * 移除事件监听
   */
  off(event, callback) {
    if (!this.listeners.has(event)) return
    
    const callbacks = this.listeners.get(event)
    const index = callbacks.indexOf(callback)
    if (index > -1) {
      callbacks.splice(index, 1)
    }
  }
  
  /**
   * 触发事件
   */
  emit(event, ...args) {
    if (!this.listeners.has(event)) return
    
    this.listeners.get(event).forEach(callback => {
      try {
        callback(...args)
      } catch (error) {
        console.error(`事件处理器错误 [${event}]:`, error)
      }
    })
  }
  
  /**
   * 获取连接状态
   */
  getStatus() {
    return {
      connected: this.isConnected,
      reconnectAttempts: this.reconnectAttempts,
      nodeId: this.nodeId,
      readyState: this.ws ? this.ws.readyState : WebSocket.CLOSED
    }
  }
}

// 创建全局实例
const taskNotificationClient = new TaskNotificationClient()

// Vue 插件安装
const TaskNotificationPlugin = {
  install(Vue) {
    // 添加到Vue原型，所有组件都可以使用
    Vue.prototype.$taskNotifier = taskNotificationClient
    
    // 添加全局方法
    Vue.prototype.$connectTaskNotifier = function(url) {
      taskNotificationClient.connect(url)
    }
    
    Vue.prototype.$disconnectTaskNotifier = function() {
      taskNotificationClient.disconnect()
    }
  }
}

export default TaskNotificationPlugin
export { taskNotificationClient }