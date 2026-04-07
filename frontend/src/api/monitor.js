/**
 * 监控相关API接口
 * 支持实时数据获取和历史趋势分析
 */

import request from '@/utils/request'

// 获取监控数据趋势 (24小时内)
export function getMonitorTrends(hours = 24) {
  return request({
    url: '/api/monitor/trends',
    method: 'get',
    params: { hours }
  })
}

// 获取实时系统概览
export function getMonitorOverview() {
  return request({
    url: '/api/monitor/overview',
    method: 'get'
  })
}

// 获取任务成功率统计
export function getTaskStats(days = 7) {
  return request({
    url: '/api/monitor/taskStats',
    method: 'get',
    params: { days }
  })
}

// 保存监控指标 (内部使用，一般由bridge.py调用)
export function saveMetrics(data) {
  return request({
    url: '/api/monitor/saveMetrics',
    method: 'post',
    data
  })
}

// 获取系统健康概览（SystemMonitor.vue 用）
export function getSystemOverview() {
  return request({
    url: '/api/monitor/overview',
    method: 'get'
  })
}

// 获取设备健康状态
export function getDeviceHealth() {
  return request({
    url: '/api/monitor/devices',
    method: 'get'
  })
}

// 获取告警规则
export function getAlertRules() {
  return request({
    url: '/api/monitor/alert-rules',
    method: 'get'
  })
}

// 运行系统健康检查
export function runSystemCheck() {
  return request({
    url: '/api/monitor/check',
    method: 'get'
  })
}

// WebSocket连接配置
export const WEBSOCKET_CONFIG = {
  // 监听系统指标广播的WebSocket地址
  METRICS_WS_URL: 'ws://localhost:8282/bridge',
  
  // 重连配置
  RECONNECT_ATTEMPTS: 5,
  RECONNECT_INTERVAL: 3000, // 3秒
  
  // 心跳配置
  HEARTBEAT_INTERVAL: 30000, // 30秒
  
  // 消息类型
  MESSAGE_TYPES: {
    SYS_METRICS: 'sys_metrics',
    PING: 'ping',
    PONG: 'pong'
  }
}