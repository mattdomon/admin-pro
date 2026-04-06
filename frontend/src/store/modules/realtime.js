/**
 * WebSocket 实时状态管理 Store
 */

export default {
  namespaced: true,
  state: {
    // WebSocket 连接状态
    wsConnected: false,
    wsReconnecting: false,
    wsError: null,
    
    // 实时节点状态
    liveNodes: {},
    
    // 实时任务状态  
    liveTasks: {},
    
    // 系统统计
    systemStats: {
      totalNodes: 0,
      onlineNodes: 0,
      runningTasks: 0,
      todayTasks: 0
    },
    
    // 实时日志流
    taskLogs: {},
  },
  
  mutations: {
    // WebSocket 连接状态
    SET_WS_CONNECTED(state, connected) {
      state.wsConnected = connected
    },
    
    SET_WS_RECONNECTING(state, reconnecting) {
      state.wsReconnecting = reconnecting
    },
    
    SET_WS_ERROR(state, error) {
      state.wsError = error
    },
    
    // 节点状态更新
    UPDATE_NODE_STATUS(state, { uuid, status, sysInfo, lastHeartbeat }) {
      if (!state.liveNodes[uuid]) {
        state.liveNodes[uuid] = {}
      }
      
      state.liveNodes[uuid] = {
        ...state.liveNodes[uuid],
        uuid,
        status,
        sysInfo,
        lastHeartbeat,
        lastUpdate: Date.now()
      }
    },
    
    REMOVE_NODE(state, uuid) {
      delete state.liveNodes[uuid]
    },
    
    // 任务状态更新
    UPDATE_TASK_STATUS(state, { taskId, status, deviceUuid, progress }) {
      if (!state.liveTasks[taskId]) {
        state.liveTasks[taskId] = {}
      }
      
      state.liveTasks[taskId] = {
        ...state.liveTasks[taskId],
        taskId,
        status,
        deviceUuid,
        progress,
        lastUpdate: Date.now()
      }
    },
    
    REMOVE_TASK(state, taskId) {
      delete state.liveTasks[taskId]
    },
    
    // 任务日志追加
    APPEND_TASK_LOGS(state, { taskId, logs }) {
      if (!state.taskLogs[taskId]) {
        state.taskLogs[taskId] = []
      }
      state.taskLogs[taskId].push(...logs)
      
      // 限制日志条数（最多保留1000条）
      if (state.taskLogs[taskId].length > 1000) {
        state.taskLogs[taskId] = state.taskLogs[taskId].slice(-1000)
      }
    },
    
    CLEAR_TASK_LOGS(state, taskId) {
      delete state.taskLogs[taskId]
    },
    
    // 系统统计更新
    UPDATE_SYSTEM_STATS(state, stats) {
      state.systemStats = { ...state.systemStats, ...stats }
    }
  },
  
  actions: {
    // 初始化 WebSocket 连接
    initWebSocket({ commit, dispatch }) {
      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
      const wsUrl = `${protocol}//${window.location.hostname}:8282`  // 修复: 8283 -> 8282
      
      const ws = new WebSocket(wsUrl)
      
      ws.onopen = () => {
        console.log('🔗 WebSocket 连接成功')
        commit('SET_WS_CONNECTED', true)
        commit('SET_WS_ERROR', null)
        
        // 发送认证（前端监控身份）
        ws.send(JSON.stringify({
          action: 'auth',
          uuid: 'FRONTEND_MONITOR',
          token: 'frontend_monitor_token'
        }))
      }
      
      ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data)
          dispatch('handleWebSocketMessage', data)
        } catch (error) {
          console.error('WebSocket 消息解析失败:', error)
        }
      }
      
      ws.onclose = () => {
        console.log('🔌 WebSocket 连接断开')
        commit('SET_WS_CONNECTED', false)
        
        // 5秒后重连
        setTimeout(() => {
          commit('SET_WS_RECONNECTING', true)
          dispatch('initWebSocket')
        }, 5000)
      }
      
      ws.onerror = (error) => {
        console.error('❌ WebSocket 连接错误:', error)
        commit('SET_WS_ERROR', error.toString())
      }
    },
    
    // 处理 WebSocket 消息
    handleWebSocketMessage({ commit }, data) {
      switch (data.action) {
        case 'node_status_update':
          commit('UPDATE_NODE_STATUS', data.payload)
          break
          
        case 'task_status_update':
          commit('UPDATE_TASK_STATUS', data.payload)
          break
          
        case 'task_logs':
          commit('APPEND_TASK_LOGS', {
            taskId: data.taskId,
            logs: data.logs
          })
          break
          
        case 'system_stats':
          commit('UPDATE_SYSTEM_STATS', data.payload)
          break
          
        default:
          console.debug('未处理的 WebSocket 消息:', data)
      }
    }
  },
  
  getters: {
    // 在线节点列表
    onlineNodes: (state) => {
      return Object.values(state.liveNodes).filter(node => node.status === 1)
    },
    
    // 运行中的任务列表
    runningTasks: (state) => {
      return Object.values(state.liveTasks).filter(task => task.status === 'running')
    },
    
    // 节点利用率
    nodeUtilization: (state) => {
      const onlineNodes = Object.values(state.liveNodes).filter(node => node.status === 1)
      const runningTasks = Object.values(state.liveTasks).filter(task => task.status === 'running')
      
      if (onlineNodes.length === 0) return 0
      return Math.round((runningTasks.length / onlineNodes.length) * 100)
    }
  }
}