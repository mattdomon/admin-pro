import Vue from 'vue'

/**
 * 节点状态管理 (SaaS 升级版)
 *
 * 新增功能：
 * - nodeKeys: SaaS node_key 列表（对应 node_keys 表）
 * - node_online / node_offline 事件实时更新状态
 * - wsRegistered: 是否已向 Workerman 注册 web 身份
 * - onlineEvent: 最新上线事件（供 AddNodeDialog 监听）
 */
export const nodes = {
  namespaced: true,

  state: {
    // ── 旧字段（保持兼容）──────────────────────────────
    connected: false,
    ws: null,
    devices: [],
    deviceMap: {},
    tasks: [],
    wsUrl: 'ws://' + window.location.hostname + ':8282',

    // ── SaaS 新增字段 ──────────────────────────────────
    /** node_keys 表的节点列表 */
    nodeKeys: [],
    /** nodeKey.id -> nodeKey 快速索引 */
    nodeKeyMap: {},
    /** nodeKey.node_key_masked -> nodeKey 快速索引（脱敏key） */
    nodeKeyByMasked: {},
    /** 是否已向 Workerman 注册 web 身份 */
    wsRegistered: false,
    /**
     * 最新 node_online 事件（触发时更新，AddNodeDialog 用 watch 监听）
     * 格式: { node_key, node_name, timestamp } 或 null
     */
    onlineEvent: null,
  },

  mutations: {
    // ── 旧 mutations（保持兼容）────────────────────────
    SET_WS(state, ws) { state.ws = ws },
    SET_CONNECTED(state, v) { state.connected = v },
    SET_DEVICES(state, devices) {
      state.devices = devices
      state.deviceMap = {}
      devices.forEach(d => { state.deviceMap[d.uuid] = d })
    },
    UPDATE_DEVICE(state, device) {
      const idx = state.devices.findIndex(d => d.uuid === device.uuid)
      if (idx >= 0) {
        Vue.set(state.devices, idx, device)
        Vue.set(state.deviceMap, device.uuid, device)
      }
    },
    SET_TASKS(state, tasks) { state.tasks = tasks },
    ADD_TASK(state, task) { state.tasks.unshift(task) },
    UPDATE_TASK(state, updated) {
      const idx = state.tasks.findIndex(t => t.id === updated.id)
      if (idx >= 0) Vue.set(state.tasks, idx, updated)
    },

    // ── SaaS 新增 mutations ────────────────────────────

    /** 整体替换 nodeKeys 列表（页面初始加载） */
    SET_NODE_KEYS(state, list) {
      state.nodeKeys = list
      state.nodeKeyMap = {}
      state.nodeKeyByMasked = {}
      list.forEach(n => {
        state.nodeKeyMap[n.id] = n
        if (n.node_key_masked) state.nodeKeyByMasked[n.node_key_masked] = n
      })
    },

    /** 追加一条新节点（generate 后本地追加，避免重新拉列表） */
    ADD_NODE_KEY(state, node) {
      state.nodeKeys.unshift(node)
      state.nodeKeyMap[node.id] = node
    },

    /** 删除节点 */
    REMOVE_NODE_KEY(state, id) {
      const idx = state.nodeKeys.findIndex(n => n.id === id)
      if (idx >= 0) {
        Vue.delete(state.nodeKeyMap, state.nodeKeys[idx].id)
        state.nodeKeys.splice(idx, 1)
      }
    },

    /**
     * 收到 node_online 广播：
     * 1. 更新列表中对应节点的 status → online
     * 2. 写入 onlineEvent（供 AddNodeDialog 的 watch 响应）
     */
    NODE_ONLINE(state, payload) {
      // payload: { node_key, node_name, timestamp }
      // 用脱敏后四位找匹配（node_key_masked 格式 "xxxxxxxx****yyyy"）
      const suffix = payload.node_key.slice(-4)
      const idx = state.nodeKeys.findIndex(n =>
        n.node_key_masked && n.node_key_masked.endsWith(suffix)
      )
      if (idx >= 0) {
        Vue.set(state.nodeKeys, idx, {
          ...state.nodeKeys[idx],
          status: 'online',
          last_heartbeat: payload.timestamp,
        })
      }
      // 触发 onlineEvent（新对象确保 watch 能感知）
      state.onlineEvent = { ...payload, _ts: Date.now() }
    },

    /**
     * 收到 node_offline 广播：更新对应节点 status → offline
     */
    NODE_OFFLINE(state, payload) {
      const suffix = payload.node_key.slice(-4)
      const idx = state.nodeKeys.findIndex(n =>
        n.node_key_masked && n.node_key_masked.endsWith(suffix)
      )
      if (idx >= 0) {
        Vue.set(state.nodeKeys, idx, {
          ...state.nodeKeys[idx],
          status: 'offline',
          last_heartbeat: payload.timestamp,
        })
      }
    },

    SET_WS_REGISTERED(state, v) { state.wsRegistered = v },
  },

  actions: {
    /**
     * 连接 Workerman WebSocket，连接成功后发送 web_register
     * 注意：只有登录后才调用（需要 token）
     */
    connectWs({ commit, state, dispatch }) {
      if (state.ws && state.connected) return

      const token = localStorage.getItem('token')
      if (!token) return

      try {
        const ws = new WebSocket(state.wsUrl)

        ws.onopen = () => {
          commit('SET_CONNECTED', true)
          // 向网关注册 web 身份，绑定 UID = web:{user_id}
          ws.send(JSON.stringify({ type: 'web_register', token }))
          console.log('[WS] 已连接，已发送 web_register')
        }

        ws.onclose = () => {
          commit('SET_CONNECTED', false)
          commit('SET_WS_REGISTERED', false)
          commit('SET_WS', null)
          // 5s 后重连
          setTimeout(() => dispatch('connectWs'), 5000)
        }

        ws.onerror = () => {
          commit('SET_CONNECTED', false)
        }

        ws.onmessage = (event) => {
          try {
            const data = JSON.parse(event.data)
            dispatch('handleWsMessage', data)
          } catch (e) { /* ignore */ }
        }

        commit('SET_WS', ws)
      } catch (e) {
        console.error('[WS] 连接失败:', e)
      }
    },

    /** 分发 WebSocket 消息 */
    handleWsMessage({ commit }, data) {
      const type = data.type || data.action || ''
      switch (type) {
        case 'web_registered':
          commit('SET_WS_REGISTERED', true)
          // 后端可能同时返回当前节点列表快照
          if (data.nodes) commit('SET_NODE_KEYS', data.nodes)
          break

        case 'node_online':
          commit('NODE_ONLINE', data)
          break

        case 'node_offline':
          commit('NODE_OFFLINE', data)
          break

        case 'task_result':
        case 'task_progress':
          // 交给其他模块处理（taskNotifier）
          break

        default:
          break
      }
    },

    /** 拉取节点列表 */
    async fetchNodeKeys({ commit }) {
      const { getNodeList } = await import('@/api/nodes')
      const res = await getNodeList()
      commit('SET_NODE_KEYS', res.data?.list || [])
    },

    /** 旧兼容 */
    refreshDevices({ commit }) {
      import('@/api/device').then(({ listDevices }) => {
        listDevices().then(res => {
          commit('SET_DEVICES', res.data || [])
        }).catch(() => {})
      })
    },
    refreshTasks({ commit }) {
      import('@/api/device').then(({ listTasks }) => {
        listTasks().then(res => {
          commit('SET_TASKS', res.data || [])
        }).catch(() => {})
      })
    },
  },

  getters: {
    /** 在线节点列表（用于任务下发下拉框） */
    onlineNodes: state => state.nodeKeys.filter(n => n.status === 'online'),
    /** 总节点数 */
    totalNodes: state => state.nodeKeys.length,
    /** 在线节点数 */
    onlineCount: state => state.nodeKeys.filter(n => n.status === 'online').length,
  },
}

export default nodes
