import request from '@/utils/request'

// 获取所有会话列表
export function getSessionsList() {
  return request({
    url: '/api/openclaw/sessions/list',
    method: 'get'
  })
}

// 获取会话详情
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

// 清理会话数据
export function cleanupSessions() {
  return request({
    url: '/api/openclaw/sessions/cleanup',
    method: 'post'
  })
}