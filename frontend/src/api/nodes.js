import request from '@/utils/request'

// ── SaaS 节点凭证管理 ────────────────────────────────────

/** 获取当前用户节点列表（脱敏 key） */
export function getNodeList() {
  return request({ url: '/api/nodes/list', method: 'get' })
}

/** 生成新节点凭证 */
export function generateNodeKey(data) {
  return request({ url: '/api/nodes/generate', method: 'post', data })
}

/** 删除节点 */
export function deleteNode(id) {
  return request({ url: `/api/nodes/${id}`, method: 'delete' })
}

/** 修改节点备注名 */
export function renameNode(id, nodeName) {
  return request({ url: `/api/nodes/${id}/name`, method: 'put', data: { node_name: nodeName } })
}
