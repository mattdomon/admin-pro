#!/usr/bin/env php
<?php
/**
 * 添加测试数据到数据库
 */

require_once __DIR__ . '/backend/vendor/autoload.php';

// 初始化 ThinkPHP
$app = new \think\App(__DIR__ . '/backend');
$app->initialize();

echo "🎲 添加测试数据...\n";

// 添加测试设备
$devices = [
    ['uuid' => 'TEST_NODE_001', 'name' => '测试节点 1', 'token' => 'token_001', 'status' => 1],
    ['uuid' => 'TEST_NODE_002', 'name' => '测试节点 2', 'token' => 'token_002', 'status' => 0],
];

foreach ($devices as $device) {
    $existing = \app\model\Device::where('uuid', $device['uuid'])->find();
    if ($existing) {
        $existing->save($device);
    } else {
        \app\model\Device::create($device);
    }
    echo "✅ 设备: {$device['name']}\n";
}

// 添加测试任务
$tasks = [
    [
        'id' => 'TASK_TEST_001',
        'device_uuid' => 'TEST_NODE_001', 
        'script_name' => 'hello.py',
        'params_json' => json_encode(['llm_config' => ['model' => 'gpt-4o'], 'objective' => 'Hello World']),
        'status' => 'success'
    ],
    [
        'id' => 'TASK_TEST_002',
        'device_uuid' => 'TEST_NODE_002',
        'script_name' => 'test.py', 
        'params_json' => json_encode(['llm_config' => ['model' => 'claude-3'], 'objective' => 'Test Task']),
        'status' => 'failed',
        'error_traceback' => 'Test error message for debugging'
    ]
];

foreach ($tasks as $task) {
    $existing = \app\model\Task::where('id', $task['id'])->find();
    if ($existing) {
        $existing->save($task);
    } else {
        \app\model\Task::create($task);
    }
    echo "✅ 任务: {$task['id']} ({$task['status']})\n";
}

// 添加测试 LLM 配置
$llms = [
    [
        'provider_type' => 'openai',
        'model_name' => 'gpt-4o',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'sk-test***hidden***',
        'is_fallback' => 0
    ],
    [
        'provider_type' => 'claude',
        'model_name' => 'claude-3-sonnet',
        'base_url' => 'https://api.anthropic.com',
        'api_key' => 'sk-ant***hidden***', 
        'is_fallback' => 1
    ]
];

foreach ($llms as $llm) {
    $existing = \app\model\LlmProvider::where('model_name', $llm['model_name'])->find();
    if ($existing) {
        $existing->save($llm);
    } else {
        \app\model\LlmProvider::create($llm);
    }
    echo "✅ LLM: {$llm['provider_type']}/{$llm['model_name']}\n";
}

echo "✅ 测试数据添加完成！\n";