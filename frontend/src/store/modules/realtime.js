/**
 * WebSocket 实时状态管理 Store
 * 
 * 修复记录（2026-04-07）：
 * - 连接时发送 web_register（而非旧的 auth）
 * - 消息处理改为 type 字段（Gateway 推送格式）
 * - 支持 node_online / node_offline 实时更新节点状态
 * - 支持 task_result / task_progress 实时更新任务状态
 * - 系统统计通过 API 轮询补充（Gateway 不广播 system_stats）
 */

import { getMonitorOverview } from '@/api/monitor'

let wsInstance = null
let statsTimer = null
let reconnectTimer = null

export default {
  namespaced: true,

  state: {
    // WebSocket 连接状态
    wsConnected: false,
    wsReconnecting: false,
    wsError: null,

    // 实时节点状态 { node_key: { node_key, node_name, status, last_heartbeat, sysInfo } }
    liveNodes: {},

    // 实时任务状态 { task_id: { taskId, status, nodeKey, progress } }
    liveTasks: {},

    // 系统统计
    systemStats: {
      totalNodes: 0,
      onlineNodes: 0,
      runningTasks: 0,
      todayTasks: 0
    },

    // 实时日志流 { task_id: [...logs] }
    taskLogs: {}
  },

  mutations: {
    SET_WS_CONNECTED(state, connected) {
      state.wsConnected = connected
    },
    SET_WS_RECONNECTING(state, reconnecting) {
      state.wsReconnecting = reconnecting
    },
    SET_WS_ERROR(state, error) {
      state.wsError = error
    },

    // 批量初始化节点列表（web_registered 时）
    INIT_NODES(state, nodes) {
      const map = {}
      nodes.forEach(n => { map[n.node_key] = n })
      state.liveNodes = map
      // 同步统计
      const online = nodes.filter(n => n.status === 'online').length
      state.systemStats = {
        ...state.systemStats,
        totalNodes: nodes.length,
        onlineNodes: online
      }
    },

    // 节点上线
    SET_NODE_ONLINE(state, { node_key, node_name }) {
      const existing = state.liveNodes[node_key] || {}
      state.liveNodes = {
        ...state.liveNodes,
        [node_key]: { ...existing, node_key, node_name, status: 'online', last_heartbeat: Math.floor(Date.now() / 1000) }
      }
      // 重新统计
      const online = Object.values(state.liveNodes).filter(n => n.status === 'online').length
      state.systemStats = { ...state.systemStats, onlineNodes: online, totalNodes: Object.keys(state.liveNodes).length }
    },

    // 节点离线
    SET_NODE_OFFLINE(state, { node_key, node_name }) {
      const existing = state.liveNodes[node_key] || {}
      state.liveNodes = {
        ...state.liveNodes,
        [node_key]: { ...existing, node_key, node_name, status: 'offline' }
      }
      const online = Object.values(state.liveNodes).filter(n => n.status === 'online').length
      state.systemStats = { ...state.systemStats, onlineNodes: online }
    },

    // 节点心跳更新 sysInfo
    UPDATE_NODE_HEARTBEAT(state, { node_key, cpu, mem, last_heartbeat }) {
      if (state.liveNodes[node_key]) {
        state.liveNodes = {
          ...state.liveNodes,
          [node_key]: {
            ...state.liveNodes[node_key],
            sysInfo: { cpu, mem },
            last_heartbeat: last_heartbeat || Math.floor(Date.now() / 1000)
          }
        }
      }
    },

    // 任务状态更新
    UPDATE_TASK_STATUS(state, { taskId, status, nodeKey, progress }) {
      state.liveTasks = {
        ...state.liveTasks,
        [taskId]: { ...state.liveTasks[taskId], taskId, status, nodeKey, progress, lastUpdate: Date.now() }
      }
      // 完成/失败时移除
      if (['completed', 'failed', 'killed'].includes(status)) {
        const next = { ...state.liveTasks }
        delete next[taskId]
        state.liveTasks = next
      }
      const running = Object.values(state.liveTasks).filter(t => t.status === 'running').length
      state.systemStats = { ...state.systemStats, runningTasks: running }
    },

    // 日志追加
    APPEND_TASK_LOGS(state, { taskId, logs }) {
      if (!state.taskLogs[taskId]) state.taskLogs[taskId] = []
      state.taskLogs[taskId].push(...logs)
      if (state.taskLogs[taskId].length > 1000) {
        state.taskLogs[taskId] = state.taskLogs[taskId].slice(-1000)
      }
    },

    // API 轮询统计更新
    UPDATE_SYSTEM_STATS(state, stats) {
      state.systemStats = { ...state.systemStats, ...stats }
    }
  },

  actions: {
    /**
     * 初始化 WebSocket 连接
     * 连接后发送 web_register（携带 JWT Token）
     */
    initWebSocket({ commit, dispatch, state }) {
      // 避免重复连接
      if (wsInstance && wsInstance.readyState === WebSocket.OPEN) return

      const token = localStorage.getItem('token') || ''
      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
      const wsUrl = `${protocol}//${window.location.hostname}:8282`

      console.log('🔗 连接 WebSocket:', wsUrl)

      const ws = new WebSocket(wsUrl)
      wsInstance = ws

      ws.onopen = () => {
        console.log('✅ WebSocket 已连接，发送 web_register')
        commit('SET_WS_CONNECTED', true)
        commit('SET_WS_RECONNECTING', false)
        commit('SET_WS_ERROR', null)

        // 发送 web_register（Gateway 期待的格式）
        ws.send(JSON.stringify({
          type: 'web_register',
          token: token
        }))

        // 启动 API 轮询统计（每30秒）
        dispatch('startStatsPoll')
      }

      ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data)
          dispatch('handleMessage', data)
        } catch (e) {
          console.error('WebSocket 消息解析失败:', e)
        }
      }

      ws.onclose = () => {
        console.log('🔌 WebSocket 断开，5秒后重连')
        commit('SET_WS_CONNECTED', false)
        wsInstance = null

        // 停止统计轮询
        if (statsTimer) { clearInterval(statsTimer); statsTimer = null }

        // 5秒后重连
        if (reconnectTimer) clearTimeout(reconnectTimer)
        reconnectTimer = setTimeout(() => {
          commit('SET_WS_RECONNECTING', true)
          dispatch('initWebSocket')
        }, 5000)
      }

      ws.onerror = (err) => {
        console.error('❌ WebSocket 错误:', err)
        commit('SET_WS_ERROR', 'WebSocket 连接失败，请检查 Gateway 服务')
      }
    },

    /**
     * 处理 Gateway 推送的消息（统一用 type 字段）
     */
    handleMessage({ commit, dispatch }, data) {
      const type = data.type || data.action || ''
      console.debug('📨 WS消息:', type, data)

      switch (type) {
        // Gateway 注册成功，返回所有节点快照
        case 'web_registered':
          commit('INIT_NODES', data.nodes || [])
          console.log('✅ Web注册成功，节点数:', (data.nodes || []).length)
          break

        // 节点上线
        case 'node_online':
          commit('SET_NODE_ONLINE', { node_key: data.node_key, node_name: data.node_name })
          break

        // 节点离线
        case 'node_offline':
          commit('SET_NODE_OFFLINE', { node_key: data.node_key, node_name: data.node_name })
          break

        // 心跳（含 sysInfo）
        case 'node_heartbeat':
          commit('UPDATE_NODE_HEARTBEAT', {
            node_key: data.node_key,
            cpu: data.cpu,
            mem: data.mem,
            last_heartbeat: data.timestamp
          })
          break

        // 任务进度
        case 'task_progress':
          commit('UPDATE_TASK_STATUS', {
            taskId: data.task_id,
            status: 'running',
            nodeKey: data.node_key,
            progress: data.progress || 0
          })
          if (data.log) {
            commit('APPEND_TASK_LOGS', {
              taskId: data.task_id,
              logs: [{ level: 'INFO', msg: data.log, ts: data.timestamp || Date.now() / 1000 }]
            })
          }
          break

        // 任务完成
        case 'task_result':
          commit('UPDATE_TASK_STATUS', {
            taskId: data.task_id,
            status: data.status || 'completed',
            nodeKey: data.node_key,
            progress: 100
          })
          break

        // AI 流式输出（chat_stream）
        case 'chat_stream':
          commit('APPEND_TASK_LOGS', {
            taskId: data.task_id,
            logs: [{ level: 'AI', msg: data.content, ts: data.timestamp || Date.now() / 1000 }]
          })
          break

        // Gateway 欢迎包 / 心跳回应（忽略）
        case 'require_auth':
        case 'pong':
        case 'welcome':
          break

        // Token 无效
        case 'error':
          console.warn('Gateway 错误:', data.error)
          break

        default:
          console.debug('未处理的 WS 消息 type:', type, data)
      }
    },

    /**
     * 启动 API 轮询，补充 Gateway 不广播的统计数据（今日任务数等）
     */
    startStatsPoll({ commit }) {
      if (statsTimer) clearInterval(statsTimer)

      const poll = async () => {
        try {
          const res = await getMonitorOverview()
          if (res.data && res.data.code === 200) {
            const d = res.data.data
            commit('UPDATE_SYSTEM_STATS', {
              todayTasks: d?.tasks?.total_today || 0
            })
          }
        } catch (e) {
          // 静默失败，不影响 WS 功能
        }
      }

      poll() // 立即执行一次
      statsTimer = setInterval(poll, 30000)
    },

    /**
     * 断开 WebSocket（组件销毁时调用）
     */
    disconnectWebSocket({ commit }) {
      if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
      if (statsTimer) { clearInterval(statsTimer); statsTimer = null }
      if (wsInstance) { wsInstance.close(); wsInstance = null }
      commit('SET_WS_CONNECTED', false)
      commit('SET_WS_RECONNECTING', false)
    }
  },

  getters: {
    // 在线节点列表
    onlineNodes: (state) => {
      return Object.values(state.liveNodes).filter(n => n.status === 'online')
    },

    // 所有节点列表（含离线）
    allNodes: (state) => {
      return Object.values(state.liveNodes)
    },

    // 运行中的任务列表
    runningTasks: (state) => {
      return Object.values(state.liveTasks).filter(t => t.status === 'running')
    },

    // 节点利用率
    nodeUtilization: (state) => {
      const online = Object.values(state.liveNodes).filter(n => n.status === 'online').length
      const running = Object.values(state.liveTasks).filter(t => t.status === 'running').length
      if (online === 0) return 0
      return Math.min(100, Math.round((running / online) * 100))
    }
  }
}
