/**
 * 脚本管理 API
 */

import request from '@/utils/request'

// 获取脚本列表
export function getScriptList() {
  return request({
    url: '/api/script/list',
    method: 'get'
  })
}

// 获取脚本内容
export function getScript(name) {
  return request({
    url: '/api/script/get',
    method: 'get',
    params: { name }
  })
}

// 保存脚本
export function saveScript(name, content) {
  return request({
    url: '/api/script/save',
    method: 'post',
    data: { name, content }
  })
}

// 删除脚本
export function deleteScript(name) {
  return request({
    url: '/api/script/delete',
    method: 'delete',
    params: { name }
  })
}

// 获取脚本模板
export function getScriptTemplates() {
  return request({
    url: '/api/script/templates',
    method: 'get'
  })
}