import request from '@/utils/request'

/**
 * Agent 管理 API
 * 
 * 功能：
 * - 获取 agent 列表
 * - 更新 agent 模型配置
 * - 批量更新配置
 * - 获取可用模型列表
 * - 重启 agent
 */

// 获取 Agent 列表
export const getAgentList = () => {
  return request({
    url: '/api/agent/list',
    method: 'get'
  })
}

// 获取可用模型列表
export const getModelList = () => {
  return request({
    url: '/api/agent/models',
    method: 'get'
  })
}

// 更新单个 Agent 模型配置
export const updateAgentModel = (data) => {
  return request({
    url: '/api/agent/update-model',
    method: 'post',
    data
  })
}

// 批量更新 Agent 模型配置
export const batchUpdateAgents = (data) => {
  return request({
    url: '/api/agent/batch-update',
    method: 'post',
    data
  })
}

// 获取 Agent 详细信息
export const getAgentDetail = (agentId) => {
  return request({
    url: '/api/agent/detail',
    method: 'get',
    params: {
      agent_id: agentId
    }
  })
}

// 重启 Agent
export const restartAgent = (agentId) => {
  return request({
    url: '/api/agent/restart',
    method: 'post',
    data: {
      agent_id: agentId
    }
  })
}