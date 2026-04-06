import request from '@/utils/request'

export function getStatus() {
  return request({ url: '/api/openclaw/status', method: 'get' })
}

export function getScripts() {
  return request({ url: '/api/openclaw/scripts', method: 'get' })
}

export function executeScript(data) {
  return request({ url: '/api/openclaw/execute', method: 'post', data })
}

export function uploadScript(data) {
  return request({ url: '/api/openclaw/upload', method: 'post', data })
}

export function deleteScript(name) {
  return request({ url: '/api/openclaw/delete', method: 'delete', data: { name } })
}

// 监控接口
export function getMonitor() {
  return request({ url: '/api/openclaw/monitor', method: 'get' })
}

export function getMonitorOverview() {
  return request({ url: '/api/openclaw/monitor/overview', method: 'get' })
}

export function getMonitorGateway() {
  return request({ url: '/api/openclaw/monitor/gateway', method: 'get' })
}

export function getMonitorAgents() {
  return request({ url: '/api/openclaw/monitor/agents', method: 'get' })
}

export function getMonitorSessions() {
  return request({ url: '/api/openclaw/monitor/sessions', method: 'get' })
}

export function getMonitorTasks() {
  return request({ url: '/api/openclaw/monitor/tasks', method: 'get' })
}

// 会话管理接口
export function getSessionsList() {
  return request({ url: '/api/openclaw/sessions/list', method: 'get' })
}

export function getSessionDetail(sessionKey, agentId) {
  return request({ 
    url: '/api/openclaw/sessions/detail', 
    method: 'get',
    params: {
      session_key: sessionKey,
      agent_id: agentId
    }
  })
}

// 重启服务
export function restartService() {
  return request({ url: '/api/openclaw/restart', method: 'post' })
}

// 获取日志
export function getLogs(lines = 50) {
  return request({ url: '/api/openclaw/logs', method: 'get', params: { lines } })
}

// 执行命令
export function executeCommand(command) {
  return request({ url: '/api/openclaw/command', method: 'post', data: { command } })
}
