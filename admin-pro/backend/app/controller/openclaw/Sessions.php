<?php
declare(strict_types=1);

namespace app\controller\openclaw;

use app\controller\BaseController;
use think\facade\Log;

class Sessions extends BaseController
{
    /**
     * 获取所有 Agent 的会话列表
     */
    public function list()
    {
        try {
            // 执行 openclaw sessions 命令获取会话数据
            $command = 'openclaw sessions --all-agents --json 2>/dev/null';
            $output = shell_exec($command);
            
            if (!$output) {
                return json([
                    'code' => 500,
                    'message' => '无法获取会话数据'
                ]);
            }
            
            $data = json_decode($output, true);
            if (!$data || !isset($data['sessions'])) {
                return json([
                    'code' => 500,
                    'message' => '会话数据格式错误'
                ]);
            }
            
            // 按 Agent 分组
            $groupedSessions = [];
            foreach ($data['sessions'] as $session) {
                $agentId = $session['agentId'] ?? 'unknown';
                if (!isset($groupedSessions[$agentId])) {
                    $groupedSessions[$agentId] = [];
                }
                
                // 格式化会话信息
                $sessionInfo = [
                    'key' => $session['key'] ?? '',
                    'sessionId' => $session['sessionId'] ?? '',
                    'agentId' => $agentId,
                    'kind' => $session['kind'] ?? '',
                    'model' => $session['model'] ?? '',
                    'modelProvider' => $session['modelProvider'] ?? '',
                    'updatedAt' => $session['updatedAt'] ?? 0,
                    'updatedAtFormatted' => $session['updatedAt'] ? date('Y-m-d H:i:s', intval($session['updatedAt'] / 1000)) : '',
                    'ageMs' => $session['ageMs'] ?? 0,
                    'inputTokens' => $session['inputTokens'] ?? 0,
                    'outputTokens' => $session['outputTokens'] ?? 0,
                    'totalTokens' => $session['totalTokens'] ?? 0,
                    'contextTokens' => $session['contextTokens'] ?? 0,
                    'usage_percent' => $session['contextTokens'] > 0 ? round(($session['totalTokens'] / $session['contextTokens']) * 100, 1) : 0,
                    'systemSent' => $session['systemSent'] ?? false,
                    'abortedLastRun' => $session['abortedLastRun'] ?? false,
                ];
                
                $groupedSessions[$agentId][] = $sessionInfo;
            }
            
            // 按更新时间排序
            foreach ($groupedSessions as &$sessions) {
                usort($sessions, function($a, $b) {
                    return $b['updatedAt'] <=> $a['updatedAt'];
                });
            }
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'total_count' => $data['count'] ?? 0,
                    'agents' => array_keys($groupedSessions),
                    'sessions' => $groupedSessions,
                    'stores' => $data['stores'] ?? []
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取会话列表失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '获取会话列表失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取特定会话的详细内容
     */
    public function detail()
    {
        try {
            $sessionKey = $this->request->param('session_key', '');
            $agentId = $this->request->param('agent_id', '');
            
            if (!$sessionKey || !$agentId) {
                return json([
                    'code' => 400,
                    'message' => '缺少必要参数'
                ]);
            }
            
            // 查找对应的 sessions.json 文件
            $sessionFile = "/Users/hc/.openclaw/agents/{$agentId}/sessions/sessions.json";
            
            if (!file_exists($sessionFile)) {
                return json([
                    'code' => 404,
                    'message' => '会话文件不存在'
                ]);
            }
            
            // 读取会话文件
            $content = file_get_contents($sessionFile);
            $sessionsData = json_decode($content, true);
            
            if (!$sessionsData) {
                return json([
                    'code' => 500,
                    'message' => '会话文件格式错误'
                ]);
            }
            
            // 查找目标会话 - sessions.json 的结构是以 sessionKey 为键的对象
            $targetSession = null;
            if (isset($sessionsData[$sessionKey])) {
                $targetSession = $sessionsData[$sessionKey];
                $targetSession['key'] = $sessionKey; // 添加 key 字段
            }
            
            if (!$targetSession) {
                return json([
                    'code' => 404,
                    'message' => '未找到指定会话'
                ]);
            }
            
            // 尝试读取会话详细内容（JSONL 格式）
            $sessionId = $targetSession['sessionId'] ?? '';
            $sessionDetailFile = "/Users/hc/.openclaw/agents/{$agentId}/sessions/{$sessionId}.jsonl";
            
            $messages = [];
            if (file_exists($sessionDetailFile)) {
                $lines = file($sessionDetailFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $lineData = json_decode($line, true);
                    
                    // 只处理 message 类型的记录
                    if ($lineData && isset($lineData['type']) && $lineData['type'] === 'message') {
                        $messageData = $lineData['message'] ?? [];
                        
                        if ($messageData) {
                            // 处理 content 字段 - 可能是字符串或数组
                            $content = $messageData['content'] ?? '';
                            $processedContent = '';
                            $toolCalls = [];
                            
                            if (is_array($content)) {
                                $textParts = [];
                                foreach ($content as $part) {
                                    if (is_array($part)) {
                                        if ($part['type'] === 'text') {
                                            $textParts[] = $part['text'] ?? '';
                                        } elseif ($part['type'] === 'toolCall') {
                                            $toolCalls[] = [
                                                'id' => $part['id'] ?? '',
                                                'function' => [
                                                    'name' => $part['name'] ?? '',
                                                    'arguments' => $part['arguments'] ?? []
                                                ]
                                            ];
                                        }
                                    }
                                }
                                $processedContent = implode('\n', $textParts);
                            } else {
                                $processedContent = (string)$content;
                            }
                            
                            // 格式化消息
                            $message = [
                                'timestamp' => $lineData['timestamp'] ?? '',
                                'role' => $messageData['role'] ?? '',
                                'content' => $processedContent,
                                'name' => $messageData['name'] ?? '',
                                'tool_calls' => $toolCalls,
                                'tool_call_id' => $messageData['tool_call_id'] ?? '',
                                'model' => $messageData['model'] ?? '',
                                'provider' => $messageData['provider'] ?? ''
                            ];
                            
                            $messages[] = $message;
                        }
                    }
                }
            }
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'session' => $targetSession,
                    'messages' => $messages,
                    'message_count' => count($messages)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取会话详情失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '获取会话详情失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 清理会话数据
     */
    public function cleanup()
    {
        try {
            $command = 'openclaw sessions cleanup 2>/dev/null';
            $output = shell_exec($command);
            
            return json([
                'code' => 200,
                'message' => '清理完成',
                'data' => ['output' => $output]
            ]);
            
        } catch (\Exception $e) {
            Log::error('清理会话失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'message' => '清理会话失败: ' . $e->getMessage()
            ]);
        }
    }
}