<?php
use think\facade\Route;

// 首页
Route::get('/', 'Index/index');
Route::get('hello', 'Index/hello');

// 登录接口
Route::post('api/auth/login', 'Auth/login');
Route::post('api/auth/logout', 'Auth/logout');
Route::get('api/auth/info', 'Auth/info');

// OpenClaw 接口 - 独立模块，可剥离
Route::group('api/openclaw', function () {
    Route::get('status', 'openclaw.OpenClaw/status');
    Route::get('scripts', 'openclaw.OpenClaw/scripts');
    Route::post('execute', 'openclaw.OpenClaw/execute');
    Route::post('upload', 'openclaw.OpenClaw/uploadScript');
    Route::delete('delete', 'openclaw.OpenClaw/deleteScript');
    
    // 监控接口
    Route::post('restart', 'openclaw.OpenClaw/restart');
    Route::get('logs', 'openclaw.OpenClaw/logs');
    Route::post('command', 'openclaw.OpenClaw/command');
    
    Route::get('monitor', 'openclaw.Monitor/index');
    Route::get('monitor/overview', 'openclaw.Monitor/overview');
    Route::get('monitor/gateway', 'openclaw.Monitor/gateway');
    Route::get('agents', 'openclaw.Monitor/agents');
    Route::get('monitor/sessions', 'openclaw.Monitor/sessions');
    Route::get('monitor/tasks', 'openclaw.Monitor/tasks');
    
    // 会话管理
    Route::get('sessions/list', 'openclaw.Sessions/list');
    Route::get('sessions/detail', 'openclaw.Sessions/detail');
    Route::post('sessions/cleanup', 'openclaw.Sessions/cleanup');
    
    // Bridge 任务管理 - 新增
    Route::post('bridge/task', 'BridgeCtrl/submitTask');          // 提交任务
    Route::get('bridge/tasks', 'BridgeCtrl/getTasks');            // 任务列表
    Route::get('bridge/task/:task_id', 'BridgeCtrl/getTask');     // 任务详情
    Route::delete('bridge/tasks/completed', 'BridgeCtrl/clearCompletedTasks'); // 清理完成任务
    Route::put('bridge/task/:task_id/cancel', 'BridgeCtrl/cancelTask');        // 取消任务
    Route::get('bridge/status', 'BridgeCtrl/getBridgeStatus');    // Bridge 状态
});

// 系统监控接口 - 新增监控面板功能
Route::group('api/monitor', function () {
    Route::post('saveMetrics', 'MonitorCtrl/saveMetrics');       // 保存监控数据 (由 bridge.py 调用)
    Route::get('trends', 'MonitorCtrl/getTrends');               // 获取监控趋势数据
    Route::get('overview', 'MonitorCtrl/getOverview');           // 获取系统概览
    Route::get('taskStats', 'MonitorCtrl/getTaskStats');         // 获取任务统计
});

// OpenClaw 模型配置管理 - 直接读写 openclaw.json
Route::group('api/openclaw/config', function () {
    Route::get('models', 'app\controller\openclaw\OpenClawConfig@index');
    Route::put('models', 'app\controller\openclaw\OpenClawConfig@save');
    Route::delete('models/:provider/:modelId', 'app\controller\openclaw\OpenClawConfig@delete');
    Route::post('models/test', 'app\controller\openclaw\OpenClawConfig@test');
    Route::post('models/chat-test', 'app\controller\openclaw\OpenClawConfig@chatTest');
    Route::post('models/set-primary', 'app\controller\openclaw\OpenClawConfig@setPrimary');
    Route::post('models/set-fallback', 'app\controller\openclaw\OpenClawConfig@setFallback');
    Route::post('models/remove-fallback', 'app\controller\openclaw\OpenClawConfig@removeFallback');
});

// Bridge WebSocket 测试接口
Route::group('api/test', function () {
    Route::post('run-script', 'TestCtrl/runScript');              // 运行测试脚本
    Route::post('openclaw', 'TestCtrl/testOpenClaw');             // 测试 OpenClaw 调用
    Route::post('ai', 'TestCtrl/testAi');                        // 测试 AI 调用
    Route::get('status', 'TestCtrl/status');                     // WebSocket 连接状态
    Route::get('scripts', 'TestCtrl/listScripts');               // 可用脚本列表
    Route::post('quick', 'TestCtrl/quickTest');                  // 快速测试
});

// 简单的 API 状态检查
Route::get('api/test-simple', function () {
    return json(['code' => 200, 'message' => 'API正常', 'time' => date('Y-m-d H:i:s')]);
});

// AI 大模型管理接口
Route::group('api/ai', function () {
    Route::get('models', 'ai.ModelController/index');
    Route::post('models', 'ai.ModelController/save');
    Route::put('models/:id', 'ai.ModelController/update');
    Route::delete('models/:id', 'ai.ModelController/delete');
    Route::post('models/:id/test', 'ai.ModelController/test');
    Route::post('models/:id/default', 'ai.ModelController/setDefault');
    Route::post('models/:id/backup', 'ai.ModelController/setBackup');
    Route::post('chat', 'ai.Chat/chat');
});

// ===== Agent 管理 =====
Route::group('api/agent', function () {
    Route::get('list', 'AgentCtrl/list');                         // Agent 列表
    Route::get('models', 'AgentCtrl/models');                     // 可用模型列表
    Route::post('update-model', 'AgentCtrl/updateModel');         // 更新单个 Agent 模型
    Route::post('batch-update', 'AgentCtrl/batchUpdate');         // 批量更新模型
    Route::get('detail', 'AgentCtrl/detail');                     // Agent 详情
    Route::post('restart', 'AgentCtrl/restart');                  // 重启 Agent
});

// ===== Sprint 2: 脚本管理系统 =====
Route::group('api/script', function () {
    Route::get('list', 'ScriptCtrl/list');
    Route::get('get', 'ScriptCtrl/get');
    Route::post('save', 'ScriptCtrl/save');
    Route::delete('delete', 'ScriptCtrl/delete');
    Route::get('templates', 'ScriptCtrl/templates');
});

// ===== Sprint 1: 多节点分布式系统 =====

// 设备节点管理
Route::group('api/device', function () {
    Route::get('list', 'NodeCtrl/list');
    Route::get('detail/:uuid', 'NodeCtrl/detail');
    Route::post('test/:uuid', 'NodeCtrl/test');
    Route::delete('delete/:uuid', 'NodeCtrl/delete');
});

// 任务管理
Route::group('api/task', function () {
    Route::get('list', 'TaskCtrl/list');
    Route::get('detail/:id', 'TaskCtrl/detail');
    Route::post('dispatch', 'TaskCtrl/dispatch');
    Route::post('kill', 'TaskCtrl/kill');
});

// LLM 密钥矩阵管理
Route::group('api/llm', function () {
    Route::get('list', 'LlmCtrl/list');
    Route::post('create', 'LlmCtrl/create');
    Route::post('update/:id', 'LlmCtrl/update');
    Route::delete('delete/:id', 'LlmCtrl/delete');
    Route::post('setFallback/:id', 'LlmCtrl/setFallback');
});

// ===== Sprint 2: 批量任务调度系统 =====

// 批量任务调度
Route::group('api/batch', function () {
    Route::post('dispatch', 'BatchTaskCtrl/batchDispatch');          // 批量下发
    Route::post('auto-dispatch', 'BatchTaskCtrl/autoDispatch');       // 智能调度
    Route::get('process-queue', 'BatchTaskCtrl/processQueue');        // 队列处理
    Route::get('status/:batch_id', 'BatchTaskCtrl/batchStatus');      // 批次状态
});

// 任务模板管理  
Route::group('api/template', function () {
    Route::get('list', 'TaskTemplateCtrl/list');                     // 模板列表
    Route::get('detail/:id', 'TaskTemplateCtrl/detail');             // 模板详情
    Route::post('create', 'TaskTemplateCtrl/create');                // 创建模板
    Route::post('update/:id', 'TaskTemplateCtrl/update');            // 更新模板
    Route::delete('delete/:id', 'TaskTemplateCtrl/delete');          // 删除模板
    Route::post('use/:id', 'TaskTemplateCtrl/useTemplate');          // 使用模板
});

// ===== Sprint 2: 系统监控与告警 =====

// 系统监控
Route::group('api/monitor', function () {
    Route::get('overview', 'MonitorCtrl/overview');                  // 系统健康概览
    Route::get('devices', 'MonitorCtrl/deviceHealth');               // 节点健康检查
    Route::get('reports', 'MonitorCtrl/reports');                    // 性能报表
    Route::get('alert-rules', 'MonitorCtrl/alertRules');             // 告警规则
    Route::get('check', 'MonitorCtrl/systemCheck');                  // 系统检查（定时任务）
});

// ===== Sprint 2: 用户权限与安全 =====

// 用户管理
Route::group('api/user', function () {
    Route::get('list', 'UserCtrl/userList');                        // 用户列表
    Route::post('create', 'UserCtrl/createUser');                   // 创建用户
    Route::post('update/:id', 'UserCtrl/updateUser');               // 更新用户
    Route::delete('delete/:id', 'UserCtrl/deleteUser');             // 删除用户
});

// 角色管理
Route::group('api/role', function () {
    Route::get('list', 'UserCtrl/roleList');                        // 角色列表
    Route::post('create', 'UserCtrl/createRole');                   // 创建角色
    Route::post('update/:id', 'UserCtrl/updateRole');               // 更新角色
    Route::delete('delete/:id', 'UserCtrl/deleteRole');             // 删除角色
});

// 权限管理
Route::group('api/permission', function () {
    Route::get('list', 'UserCtrl/permissionList');                  // 权限列表
});

// 操作日志
Route::group('api/log', function () {
    Route::get('operations', 'UserCtrl/operationLogs');             // 操作日志
});

// ===== LLM Proxy: 主备切换代理服务 =====

// LLM 代理接口 - 兼容 OpenAI 格式
Route::group('api/llm_proxy', function () {
    Route::post('v1/chat/completions', 'app\api\controller\LlmProxy@chatCompletions'); // 对话接口（主备切换）
    // 未来可扩展：
    // Route::post('v1/completions', 'app\api\controller\LlmProxy@completions');         // 文本补全（主备切换）
    // Route::get('v1/models', 'app\api\controller\LlmProxy@models');                    // 模型列表
});
