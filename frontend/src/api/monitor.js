import request from '@/utils/request'

// 系统监控API
export function getSystemOverview() {
  return request({
    url: '/api/monitor/overview',
    method: 'get'
  })
}

export function getDeviceHealth() {
  return request({
    url: '/api/monitor/devices',
    method: 'get'
  })
}

export function getSystemReports(days = 7) {
  return request({
    url: '/api/monitor/reports',
    method: 'get',
    params: { days }
  })
}

export function getAlertRules() {
  return request({
    url: '/api/monitor/alert-rules',
    method: 'get'
  })
}

export function runSystemCheck() {
  return request({
    url: '/api/monitor/check',
    method: 'get'
  })
}

// 告警管理API
export function getAlertList(params) {
  return request({
    url: '/api/alerts/list',
    method: 'get',
    params
  })
}

export function markAlertAsRead(id) {
  return request({
    url: `/api/alerts/${id}/read`,
    method: 'post'
  })
}

export function deleteAlert(id) {
  return request({
    url: `/api/alerts/${id}`,
    method: 'delete'
  })
}