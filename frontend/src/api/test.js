/**
 * 测试脚本 API
 */

import request from '@/utils/request'

// 运行测试脚本
export function runTestScript(scriptName = 'default') {
  return request({
    url: '/api/test/run-script',
    method: 'post',
    data: { script_name: scriptName }
  })
}

// 获取测试脚本列表
export function getTestScriptList() {
  return request({
    url: '/api/test/scripts',
    method: 'get'
  })
}