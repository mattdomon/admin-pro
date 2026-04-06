import Vue from 'vue'

/**
 * 节点状态管理
 * - WebSocket 实时状态树
 * - 心跳同步
 * - 任务列表本地缓存
 */
export const nodes = {
  namespaced: true,
  state: {
    connected: false,
    ws: null,
    devices: [],          // 设备列表
    deviceMap: {},        // uuid -> device 快速查找
    tasks: [],            // 任务列表
    wsUrl: 'ws://' + window.location.hostname + ':8282',
  },
  mutations: {
    SET_WS(state, ws) {
      state.ws = ws
    },
    SET_CONNECTED(state, connected) {
      state.connected = connected
    },
    SET_DEVICES(state, devices) {
      state.devices = devices
      state.deviceMap = {}
      devices.forEach(d => {
        state.deviceMap[d.uuid] = d
      })
    },
    UPDATE_DEVICE(state, device) {
      const idx = state.devices.findIndex(d => d.uuid === device.uuid)
      if (idx >= 0) {
        Vue.set(state.devices, idx, device)
        Vue.set(state.deviceMap, device.uuid, device)
      }
    },
    SET_TASKS(state, tasks) {
      state.tasks = tasks
    },
    ADD_TASK(state, task) {
      state.tasks.unshift(task)
    },
    UPDATE_TASK(state, updated) {
      const idx = state.tasks.findIndex(t => t.id === updated.id)
      if (idx >= 0) {
        Vue.set(state.tasks, idx, updated)
      }
    }
  },
  actions: {
    // 连接 WebSocket
    connectWs({ commit, state }) {
      if (state.ws && state.connected) return
      try {
        const ws = new WebSocket(state.wsUrl)
        ws.onopen = () => {
          commit('SET_CONNECTED', true)
          console.log('[WS] 已连接')
        }
        ws.onclose = () => {
          commit('SET_CONNECTED', false)
          // 5s 后重连
          setTimeout(() => {
            this.dispatch('nodes/connectWs')
          }, 5000)
        }
        ws.onmessage = (event) => {
          try {
            const data = JSON.parse(event.data)
            console.log('[WS] 收到:', data)
          } catch (e) {
            // ignore
          }
        }
        commit('SET_WS', ws)
      } catch (e) {
        console.error('[WS] 连接败:', e)
      }
    },
    // 刷新设备列表
    refreshDevices({ commit }) {
      import('@/api/device').then(({ listDevices }) => {
        listDevices().then(res => {
          commit('SET_DEVICES', res.data || [])
        }).catch(() => {})
      })
    },
    // 刷新任务列表
    refreshTasks({ commit }) {
      import('@/api/device').then(({ listTasks }) => {
        listTasks().then(res => {
          commit('SET_TASKS', res.data || [])
        }).catch(() => {})
      })
    }
  }
}
