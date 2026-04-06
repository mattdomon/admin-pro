import request from '@/utils/request'

// 节点设备管理
export function getDeviceList(params) {
  return request({ url: '/api/device/list', method: 'get', params })
}

export function listDevices(params) {
  return request({ url: '/api/device/list', method: 'get', params })
}

export function getDeviceDetail(uuid) {
  return request({ url: `/api/device/detail/${uuid}`, method: 'get' })
}

export function testDevice(uuid) {
  return request({ url: `/api/device/test/${uuid}`, method: 'post' })
}

export function deleteDevice(uuid) {
  return request({ url: `/api/device/delete/${uuid}`, method: 'delete' })
}

// 任务管理
export function listTasks(params) {
  return request({ url: '/api/task/list', method: 'get', params })
}

export function getTaskDetail(id) {
  return request({ url: `/api/task/detail/${id}`, method: 'get' })
}

export function dispatchTask(data) {
  return request({ url: '/api/task/dispatch', method: 'post', data })
}

export function killTask(data) {
  return request({ url: '/api/task/kill', method: 'post', data })
}

// LLM 密钥矩阵
export function listLlmProviders() {
  return request({ url: '/api/llm/list', method: 'get' })
}

export function createLlmProvider(data) {
  return request({ url: '/api/llm/create', method: 'post', data })
}

export function updateLlmProvider(id, data) {
  return request({ url: `/api/llm/update/${id}`, method: 'post', data })
}

export function deleteLlmProvider(id) {
  return request({ url: `/api/llm/delete/${id}`, method: 'delete' })
}

export function setFallback(id) {
  return request({ url: `/api/llm/setFallback/${id}`, method: 'post' })
}
