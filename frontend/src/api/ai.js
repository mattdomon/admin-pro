import request from '@/utils/request'

// ===== OpenClaw 模型配置管理（直接读写 openclaw.json） =====

// 获取 OpenClaw 模型列表
export function listModels() {
  return request({ url: '/api/openclaw/config/models', method: 'get' })
}

// 保存模型（新增或更新）
export function saveModel(data) {
  return request({ url: '/api/openclaw/config/models', method: 'put', data })
}

// 删除模型
export function deleteModel(provider, modelId) {
  return request({ url: `/api/openclaw/config/models/${encodeURIComponent(provider)}/${encodeURIComponent(modelId)}`, method: 'delete' })
}

// 测试连通性
export function testConnectivity(data) {
  return request({ url: '/api/openclaw/config/models/test', method: 'post', data })
}

// 对话测试
export function testChat(data) {
  return request({ url: '/api/openclaw/config/models/chat-test', method: 'post', data })
}

// ===== 以下为旧 AI 模型管理（独立数据库，保留兼容） =====

// 获取本地 AI 模型列表
export function listAiModels() {
  return request({ url: '/api/ai/models', method: 'get' })
}

// 创建本地 AI 模型
export function createAiModel(data) {
  return request({ url: '/api/ai/models', method: 'post', data })
}

// 更新本地 AI 模型
export function updateAiModel(id, data) {
  return request({ url: `/api/ai/models/${id}`, method: 'put', data })
}

// 删除本地 AI 模型
export function deleteAiModel(id) {
  return request({ url: `/api/ai/models/${id}`, method: 'delete' })
}

// 测试本地模型连通性
export function testAiModel(id) {
  return request({ url: `/api/ai/models/${id}/test`, method: 'post' })
}

// 设为默认
export function setDefault(id) {
  return request({ url: `/api/ai/models/${id}/default`, method: 'post' })
}

// 设为备用
export function setBackup(id) {
  return request({ url: `/api/ai/models/${id}/backup`, method: 'post' })
}

// AI 聊天
export function chat(data) {
  return request({
    url: '/api/ai/chat',
    method: 'post',
    data,
    responseType: 'blob'
  })
}

// AI 聊天 (流式)
export function chatStream(data) {
  const token = localStorage.getItem('token')
  const baseURL = import.meta.env?.VITE_API_BASE_URL || ''

  return fetch(`${baseURL}/api/ai/chat`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': token ? `Bearer ${token}` : ''
    },
    body: JSON.stringify({ ...data, stream: true })
  })
}
