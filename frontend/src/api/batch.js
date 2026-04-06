import request from '@/utils/request'

// 批量任务API
export function batchDispatch(data) {
  return request({
    url: '/api/batch/dispatch',
    method: 'post',
    data
  })
}

export function autoDispatch(data) {
  return request({
    url: '/api/batch/auto-dispatch',
    method: 'post',
    data
  })
}

export function processQueue() {
  return request({
    url: '/api/batch/process-queue',
    method: 'get'
  })
}

export function getBatchStatus(batchId) {
  return request({
    url: `/api/batch/status/${batchId}`,
    method: 'get'
  })
}

// 任务模板API
export function getTemplateList() {
  return request({
    url: '/api/template/list',
    method: 'get'
  })
}

export function getTemplateDetail(id) {
  return request({
    url: `/api/template/detail/${id}`,
    method: 'get'
  })
}

export function createTemplate(data) {
  return request({
    url: '/api/template/create',
    method: 'post',
    data
  })
}

export function updateTemplate(id, data) {
  return request({
    url: `/api/template/update/${id}`,
    method: 'post',
    data
  })
}

export function deleteTemplate(id) {
  return request({
    url: `/api/template/delete/${id}`,
    method: 'delete'
  })
}

export function useTemplate(id, data) {
  return request({
    url: `/api/template/use/${id}`,
    method: 'post',
    data
  })
}