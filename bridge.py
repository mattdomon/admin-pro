#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Bridge Service - OpenClaw与外部服务的桥梁调度器
统一管理所有外部服务的接口调用，提供标准化的任务队列和状态管理
"""

import os
import sys
import json
import time
import logging
import asyncio
import signal
import random
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict
from enum import Enum
import websockets
import aiohttp

# 配置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('logs/bridge.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class TaskStatus(Enum):
    """任务状态枚举"""
    PENDING = "pending"      # 等待处理
    RUNNING = "running"      # 执行中
    SUCCESS = "success"      # 成功完成
    FAILED = "failed"        # 执行失败
    TIMEOUT = "timeout"      # 超时
    CANCELLED = "cancelled"  # 已取消

@dataclass
class Task:
    """任务数据结构"""
    id: str
    type: str  # 任务类型：openclaw, ai, script, webhook
    payload: Dict[str, Any]
    status: TaskStatus = TaskStatus.PENDING
    created_at: float = 0
    started_at: Optional[float] = None
    completed_at: Optional[float] = None
    result: Optional[Dict[str, Any]] = None
    error: Optional[str] = None
    retries: int = 0
    max_retries: int = 3
    timeout: int = 300  # 5分钟超时

    def __post_init__(self):
        if self.created_at == 0:
            self.created_at = time.time()

class BridgeService:
    """桥梁服务主类"""
    
    def __init__(self, config_path: str = "config/bridge.json"):
        self.config_path = config_path
        self.config = self._load_config()
        self.tasks: Dict[str, Task] = {}
        self.running = False
        self.workers = {}
        
        # WebSocket 连接管理
        self.ws_client = None
        self.ws_connected = False
        self.ws_reconnect_attempts = 0
        self.node_id = f"bridge_client_{int(time.time())}"
        
        # 确保必要目录存在
        os.makedirs('logs', exist_ok=True)
        os.makedirs('data/tasks', exist_ok=True)
        
        logger.info(f"Bridge Service 初始化完成 - Node ID: {self.node_id}")

    def _load_config(self) -> Dict[str, Any]:
        """加载配置文件"""
        try:
            if os.path.exists(self.config_path):
                with open(self.config_path, 'r', encoding='utf-8') as f:
                    return json.load(f)
        except Exception as e:
            logger.warning(f"配置文件加载失败，使用默认配置: {e}")
        
        # 默认配置
        return {
            "workers": {
                "openclaw": {"enabled": True, "max_concurrent": 5},
                "ai": {"enabled": True, "max_concurrent": 3},
                "script": {"enabled": True, "max_concurrent": 2},
                "webhook": {"enabled": True, "max_concurrent": 10}
            },
            "timeouts": {
                "openclaw": 600,    # OpenClaw任务10分钟超时
                "ai": 300,          # AI任务5分钟超时
                "script": 1800,     # 脚本任务30分钟超时
                "webhook": 60       # Webhook任务1分钟超时
            },
            "retry": {
                "max_retries": 3,
                "retry_delay": 5
            }
        }

    async def start(self):
        """启动服务"""
        self.running = True
        logger.info("Bridge Service 正在启动...")
        
        # 启动各类型任务处理器和 WebSocket 连接
        await asyncio.gather(
            self._start_task_processor(),
            self._start_status_monitor(),
            self._start_cleanup_worker(),
            self._start_websocket_client()  # 新增 WebSocket 客户端
        )

    async def stop(self):
        """停止服务"""
        logger.info("Bridge Service 正在停止...")
        self.running = False
        
        # 等待所有任务完成或超时
        timeout = 30
        start_time = time.time()
        
        while self._has_running_tasks() and (time.time() - start_time) < timeout:
            await asyncio.sleep(1)
        
        logger.info("Bridge Service 已停止")

    def _has_running_tasks(self) -> bool:
        """检查是否有运行中的任务"""
        return any(task.status == TaskStatus.RUNNING for task in self.tasks.values())

    async def submit_task(self, task_type: str, payload: Dict[str, Any], 
                         task_id: Optional[str] = None, **kwargs) -> str:
        """提交新任务"""
        if not task_id:
            task_id = f"{task_type}_{int(time.time() * 1000)}"
        
        task = Task(
            id=task_id,
            type=task_type,
            payload=payload,
            timeout=kwargs.get('timeout', self.config['timeouts'].get(task_type, 300)),
            max_retries=kwargs.get('max_retries', self.config['retry']['max_retries'])
        )
        
        self.tasks[task_id] = task
        logger.info(f"任务已提交: {task_id} ({task_type})")
        
        # 保存任务到磁盘
        await self._save_task(task)
        
        return task_id

    async def get_task_status(self, task_id: str) -> Optional[Dict[str, Any]]:
        """获取任务状态"""
        task = self.tasks.get(task_id)
        if not task:
            return None
        
        return {
            "id": task.id,
            "type": task.type,
            "status": task.status.value,
            "created_at": task.created_at,
            "started_at": task.started_at,
            "completed_at": task.completed_at,
            "result": task.result,
            "error": task.error,
            "retries": task.retries
        }

    async def _start_task_processor(self):
        """任务处理器主循环"""
        logger.info("任务处理器已启动")
        
        while self.running:
            try:
                # 处理待执行任务
                pending_tasks = [
                    task for task in self.tasks.values() 
                    if task.status == TaskStatus.PENDING
                ]
                
                for task in pending_tasks:
                    if await self._can_process_task(task):
                        asyncio.create_task(self._process_task(task))
                
                await asyncio.sleep(1)  # 1秒检查间隔
                
            except Exception as e:
                logger.error(f"任务处理器错误: {e}")
                await asyncio.sleep(5)

    async def _can_process_task(self, task: Task) -> bool:
        """检查是否可以处理任务（并发控制）"""
        task_type = task.type
        worker_config = self.config['workers'].get(task_type, {})
        
        if not worker_config.get('enabled', True):
            return False
        
        # 检查并发限制
        max_concurrent = worker_config.get('max_concurrent', 5)
        running_count = sum(
            1 for t in self.tasks.values()
            if t.type == task_type and t.status == TaskStatus.RUNNING
        )
        
        return running_count < max_concurrent

    async def _process_task(self, task: Task):
        """处理单个任务"""
        logger.info(f"开始处理任务: {task.id} ({task.type})")
        
        task.status = TaskStatus.RUNNING
        task.started_at = time.time()
        
        # 发送任务开始通知
        await self._notify_task_status_change(task, "started")
        
        try:
            # 根据任务类型分发到对应处理器
            if task.type == "openclaw":
                result = await self._process_openclaw_task(task)
            elif task.type == "ai":
                result = await self._process_ai_task(task)
            elif task.type == "script":
                result = await self._process_script_task(task)
            elif task.type == "webhook":
                result = await self._process_webhook_task(task)
            else:
                raise ValueError(f"未知任务类型: {task.type}")
            
            task.status = TaskStatus.SUCCESS
            task.result = result
            task.completed_at = time.time()
            
            logger.info(f"任务完成: {task.id}")
            
            # 发送成功通知
            await self._notify_task_status_change(task, "success")
            
        except asyncio.TimeoutError:
            task.status = TaskStatus.TIMEOUT
            task.error = "任务执行超时"
            task.completed_at = time.time()
            logger.warning(f"任务超时: {task.id}")
            
            # 发送超时通知
            await self._notify_task_status_change(task, "timeout")
            
        except Exception as e:
            task.error = str(e)
            task.retries += 1
            
            if task.retries <= task.max_retries:
                task.status = TaskStatus.PENDING  # 重试
                logger.warning(f"任务失败，将重试: {task.id} (重试次数: {task.retries})")
                await asyncio.sleep(self.config['retry']['retry_delay'])
            else:
                task.status = TaskStatus.FAILED
                task.completed_at = time.time()
                logger.error(f"任务失败: {task.id} - {e}")
                
                # 发送失败通知（包含详细错误信息）
                await self._notify_task_status_change(task, "failed")
        
        finally:
            await self._save_task(task)

    async def _process_openclaw_task(self, task: Task) -> Dict[str, Any]:
        """处理OpenClaw任务"""
        payload = task.payload
        action = payload.get('action')
        
        if action == "send_message":
            # 发送消息到指定会话
            return await self._openclaw_send_message(payload)
        elif action == "get_status":
            # 获取OpenClaw状态
            return await self._openclaw_get_status()
        elif action == "manage_agent":
            # Agent管理操作
            return await self._openclaw_manage_agent(payload)
        else:
            raise ValueError(f"未知OpenClaw操作: {action}")

    async def _process_ai_task(self, task: Task) -> Dict[str, Any]:
        """处理AI任务"""
        payload = task.payload
        model = payload.get('model', 'claude-3-5-haiku-latest')
        
        # 这里集成各种AI服务
        # 示例：调用Claude API
        return {
            "model": model,
            "response": "AI处理结果示例",
            "usage": {"tokens": 100}
        }

    async def _process_script_task(self, task: Task) -> Dict[str, Any]:
        """处理脚本任务"""
        payload = task.payload
        script_path = payload.get('script_path')
        args = payload.get('args', [])
        
        if not script_path:
            raise ValueError("script_path 参数不能为空")
        
        # 构建命令参数
        cmd_args = [sys.executable, script_path] + args
        cwd = os.path.dirname(script_path) if script_path else None
        
        process = None
        try:
            # 使用异步子进程，避免阻塞事件循环
            process = await asyncio.create_subprocess_exec(
                *cmd_args,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                cwd=cwd
            )
            
            logger.info(f"脚本进程已启动: PID={process.pid}, 脚本={script_path}")
            
            # 使用 wait_for 实现超时控制
            try:
                stdout, stderr = await asyncio.wait_for(
                    process.communicate(), 
                    timeout=task.timeout
                )
                
                return {
                    "returncode": process.returncode,
                    "stdout": stdout.decode('utf-8', errors='replace'),
                    "stderr": stderr.decode('utf-8', errors='replace'),
                    "pid": process.pid
                }
                
            except asyncio.TimeoutError:
                logger.warning(f"脚本执行超时，正在终止进程: PID={process.pid}")
                
                # 超时时必须显式杀掉子进程，防止僵尸进程
                try:
                    process.kill()
                    # 等待进程真正退出，避免僵尸进程
                    await asyncio.wait_for(process.wait(), timeout=5.0)
                except asyncio.TimeoutError:
                    logger.error(f"强制终止进程失败: PID={process.pid}")
                except ProcessLookupError:
                    # 进程已经不存在了，正常情况
                    pass
                
                raise asyncio.TimeoutError(f"脚本执行超时 ({task.timeout}秒)")
                
        except FileNotFoundError:
            raise ValueError(f"脚本文件不存在: {script_path}")
        except PermissionError:
            raise ValueError(f"脚本文件权限不足: {script_path}")
        except Exception as e:
            # 如果出现其他异常，也要确保清理子进程
            if process and process.returncode is None:
                try:
                    process.kill()
                    await asyncio.wait_for(process.wait(), timeout=2.0)
                except:
                    pass
            raise e

    async def _process_webhook_task(self, task: Task) -> Dict[str, Any]:
        """处理Webhook任务"""
        payload = task.payload
        url = payload.get('url')
        method = payload.get('method', 'POST')
        data = payload.get('data', {})
        
        import aiohttp
        
        async with aiohttp.ClientSession() as session:
            async with session.request(method, url, json=data) as response:
                return {
                    "status": response.status,
                    "response": await response.text(),
                    "headers": dict(response.headers)
                }

    async def _openclaw_send_message(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """通过 WebSocket 发送消息到 OpenClaw"""
        session_key = payload.get('session_key')
        message = payload.get('message')
        
        if not self.ws_connected:
            raise ConnectionError("WebSocket 连接未建立")
            
        ws_message = {
            "type": "send_message",
            "session_key": session_key,
            "message": message,
            "timestamp": time.time()
        }
        
        success = await self.send_websocket_message(ws_message)
        if not success:
            raise RuntimeError("发送 WebSocket 消息失败")
            
        logger.info(f"发送消息到会话 {session_key}: {message}")
        return {"status": "sent", "session_key": session_key, "ws_connected": True}

    async def _openclaw_get_status(self) -> Dict[str, Any]:
        """获取 OpenClaw 状态"""
        if not self.ws_connected:
            return {
                "status": "disconnected",
                "ws_connected": False,
                "reconnect_attempts": self.ws_reconnect_attempts
            }
            
        ws_message = {
            "type": "get_status",
            "timestamp": time.time()
        }
        
        success = await self.send_websocket_message(ws_message)
        
        return {
            "status": "connected" if success else "error",
            "ws_connected": self.ws_connected,
            "node_id": self.node_id,
            "reconnect_attempts": self.ws_reconnect_attempts
        }

    async def _openclaw_manage_agent(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """管理 OpenClaw Agent"""
        agent_id = payload.get('agent_id')
        action = payload.get('action')  # start, stop, restart, status
        
        if not self.ws_connected:
            raise ConnectionError("WebSocket 连接未建立")
            
        ws_message = {
            "type": "manage_agent",
            "agent_id": agent_id,
            "action": action,
            "timestamp": time.time()
        }
        
        success = await self.send_websocket_message(ws_message)
        if not success:
            raise RuntimeError(f"Agent {action} 操作失败")
            
        logger.info(f"Agent管理: {agent_id} - {action}")
        return {"agent_id": agent_id, "action": action, "status": "success"}

    async def _start_status_monitor(self):
        """状态监控器"""
        logger.info("状态监控器已启动")
        
        while self.running:
            try:
                # 检查超时任务
                current_time = time.time()
                for task in self.tasks.values():
                    if (task.status == TaskStatus.RUNNING and 
                        task.started_at and 
                        current_time - task.started_at > task.timeout):
                        
                        task.status = TaskStatus.TIMEOUT
                        task.error = "任务执行超时"
                        task.completed_at = current_time
                        logger.warning(f"任务超时: {task.id}")
                
                await asyncio.sleep(10)  # 10秒检查间隔
                
            except Exception as e:
                logger.error(f"状态监控器错误: {e}")
                await asyncio.sleep(30)

    async def _start_cleanup_worker(self):
        """清理工作器"""
        logger.info("清理工作器已启动")
        
        while self.running:
            try:
                # 清理完成的旧任务（24小时前）
                current_time = time.time()
                cleanup_before = current_time - 24 * 3600  # 24小时前
                
                tasks_to_remove = [
                    task_id for task_id, task in self.tasks.items()
                    if (task.status in [TaskStatus.SUCCESS, TaskStatus.FAILED, TaskStatus.TIMEOUT] and
                        task.completed_at and task.completed_at < cleanup_before)
                ]
                
                for task_id in tasks_to_remove:
                    del self.tasks[task_id]
                    # 删除磁盘文件
                    task_file = f"data/tasks/{task_id}.json"
                    if os.path.exists(task_file):
                        os.remove(task_file)
                
                if tasks_to_remove:
                    logger.info(f"清理了 {len(tasks_to_remove)} 个旧任务")
                
                await asyncio.sleep(3600)  # 1小时清理间隔
                
            except Exception as e:
                logger.error(f"清理工作器错误: {e}")
                await asyncio.sleep(1800)  # 出错后30分钟重试

    async def _save_task(self, task: Task):
        """保存任务到磁盘"""
        try:
            task_file = f"data/tasks/{task.id}.json"
            task_data = asdict(task)
            task_data['status'] = task.status.value  # 转换枚举为字符串
            
            with open(task_file, 'w', encoding='utf-8') as f:
                json.dump(task_data, f, ensure_ascii=False, indent=2)
        except Exception as e:
            logger.error(f"保存任务失败 {task.id}: {e}")

    async def _start_websocket_client(self):
        """启动 WebSocket 客户端连接"""
        logger.info("WebSocket 客户端正在启动...")
        
        while self.running:
            try:
                await self._connect_websocket()
            except Exception as e:
                logger.error(f"WebSocket 客户端错误: {e}")
                await asyncio.sleep(5)  # 等待5秒后重试

    async def _connect_websocket(self):
        """建立 WebSocket 连接并维持"""
        gateway_url = self.config['openclaw']['gateway_url']
        
        # 指数退避重连机制
        max_backoff = 300  # 最大5分钟
        base_delay = 1
        
        while self.running:
            try:
                logger.info(f"正在连接到 WebSocket 网关: {gateway_url}")
                
                async with websockets.connect(
                    gateway_url,
                    ping_interval=20,  # 20秒 ping 间隔
                    ping_timeout=10,   # 10秒 ping 超时
                    close_timeout=10   # 10秒关闭超时
                ) as websocket:
                    self.ws_client = websocket
                    self.ws_connected = True
                    self.ws_reconnect_attempts = 0
                    
                    logger.info("✅ WebSocket 连接成功!")
                    
                    # 立即发送节点注册消息
                    await self._register_node(websocket)
                    
                    # 启动心跳保活任务
                    heartbeat_task = asyncio.create_task(
                        self._websocket_heartbeat(websocket)
                    )
                    
                    try:
                        # 监听消息
                        async for message in websocket:
                            await self._handle_websocket_message(message)
                    except websockets.exceptions.ConnectionClosed:
                        logger.warning("WebSocket 连接已关闭")
                    finally:
                        heartbeat_task.cancel()
                        self.ws_connected = False
                        self.ws_client = None
                        
            except Exception as e:
                self.ws_connected = False
                self.ws_client = None
                self.ws_reconnect_attempts += 1
                
                # 计算退避延迟
                delay = min(base_delay * (2 ** self.ws_reconnect_attempts), max_backoff)
                # 添加随机抖动防止雷群效应
                jitter = random.uniform(0.5, 1.5)
                actual_delay = delay * jitter
                
                logger.warning(
                    f"WebSocket 连接失败 (第{self.ws_reconnect_attempts}次): {e}. "
                    f"{actual_delay:.1f}秒后重试"
                )
                
                await asyncio.sleep(actual_delay)
                
    async def _register_node(self, websocket):
        """向服务器注册节点"""
        register_msg = {
            "type": "register",
            "node_id": self.node_id,
            "timestamp": time.time(),
            "version": "1.0",
            "capabilities": ["task_processing", "script_execution"]
        }
        
        await websocket.send(json.dumps(register_msg))
        logger.info(f"✨ 已发送节点注册: {self.node_id}")
        
    async def _websocket_heartbeat(self, websocket):
        """心跳保活任务"""
        logger.info("❤️ 心跳保活任务已启动")
        
        while self.running and self.ws_connected:
            try:
                await asyncio.sleep(20)  # 每20秒发送一次心跳
                
                if not self.ws_connected:
                    break
                    
                ping_msg = {
                    "type": "ping",
                    "node_id": self.node_id,
                    "timestamp": time.time()
                }
                
                await websocket.send(json.dumps(ping_msg))
                logger.debug(f"📡 已发送心跳: {self.node_id}")
                
            except websockets.exceptions.ConnectionClosed:
                logger.warning("心跳任务: WebSocket 连接已关闭")
                break
            except Exception as e:
                logger.error(f"心跳任务错误: {e}")
                break
                
        logger.info("❤️ 心跳保活任务已停止")
        
    async def _handle_websocket_message(self, message):
        """处理 WebSocket 消息"""
        try:
            data = json.loads(message)
            msg_type = data.get('type')
            
            if msg_type == 'pong':
                logger.debug(f"🏓 收到心跳响应: {data.get('node_id', 'unknown')}")
            elif msg_type == 'task_request':
                # 处理远程任务请求
                await self._handle_remote_task_request(data)
            elif msg_type == 'register_response':
                logger.info(f"✅ 节点注册成功: {data.get('message', 'OK')}")
            else:
                logger.info(f"📨 收到消息: {message}")
                
        except json.JSONDecodeError:
            logger.warning(f"无效的 JSON 消息: {message}")
        except Exception as e:
            logger.error(f"处理消息错误: {e}")
            
    async def _handle_remote_task_request(self, data):
        """处理远程任务请求"""
        try:
            task_type = data.get('task_type')
            payload = data.get('payload', {})
            request_id = data.get('request_id')
            
            logger.info(f"收到远程任务请求: {task_type} (ID: {request_id})")
            
            # 提交任务到本地队列
            task_id = await self.submit_task(task_type, payload)
            
            # 发送响应
            response = {
                "type": "task_response",
                "request_id": request_id,
                "task_id": task_id,
                "status": "accepted"
            }
            
            if self.ws_client:
                await self.ws_client.send(json.dumps(response))
                
        except Exception as e:
            logger.error(f"处理远程任务失败: {e}")

    async def send_websocket_message(self, message: Dict[str, Any]) -> bool:
        """发送 WebSocket 消息"""
        if not self.ws_connected or not self.ws_client:
            logger.warning("无法发送消息: WebSocket 未连接")
            return False
            
        try:
            await self.ws_client.send(json.dumps(message))
            return True
        except Exception as e:
            logger.error(f"发送 WebSocket 消息失败: {e}")
            return False

    async def _notify_task_status_change(self, task: Task, event_type: str):
        """通知任务状态变化"""
        try:
            # 构造通知消息
            notification = {
                "type": "task_notification",
                "event_type": event_type,  # started, success, failed, timeout
                "task_id": task.id,
                "task_type": task.type,
                "status": task.status.value,
                "timestamp": time.time(),
                "node_id": self.node_id
            }
            
            # 添加不同事件类型的特定信息
            if event_type == "started":
                notification["message"] = f"任务开始执行: {task.id[:8]}..."
                notification["script_name"] = task.payload.get('script_path', '')
                
            elif event_type == "success":
                notification["message"] = f"任务执行成功: {task.id[:8]}..."
                notification["result"] = task.result
                notification["execution_time"] = task.completed_at - task.started_at if task.started_at else 0
                
            elif event_type == "failed":
                notification["message"] = f"任务执行失败: {task.id[:8]}..."
                notification["error"] = task.error
                notification["error_detail"] = {
                    "task_id": task.id,
                    "task_type": task.type,
                    "script_path": task.payload.get('script_path', ''),
                    "error_message": task.error,
                    "retries": task.retries,
                    "max_retries": task.max_retries
                }
                
            elif event_type == "timeout":
                notification["message"] = f"任务执行超时: {task.id[:8]}..."
                notification["timeout_duration"] = task.timeout
            
            # 发送通知
            success = await self.send_websocket_message(notification)
            
            if success:
                logger.info(f"✅ 任务通知已发送: {event_type} - {task.id[:8]}...")
            else:
                logger.warning(f"⚠️ 任务通知发送失败: {event_type} - {task.id[:8]}...")
                
        except Exception as e:
            logger.error(f"发送任务通知失败: {e}")

    def get_stats(self) -> Dict[str, Any]:
        """获取服务统计信息"""
        stats = {
            "total_tasks": len(self.tasks),
            "by_status": {},
            "by_type": {}
        }
        
        for task in self.tasks.values():
            # 按状态统计
            status = task.status.value
            stats["by_status"][status] = stats["by_status"].get(status, 0) + 1
            
            # 按类型统计
            task_type = task.type
            stats["by_type"][task_type] = stats["by_type"].get(task_type, 0) + 1
        
        return stats

# HTTP API 服务器
class BridgeAPI:
    """Bridge Service HTTP API"""
    
    def __init__(self, bridge: BridgeService, port: int = 9999):
        self.bridge = bridge
        self.port = port
        self.app = None

    async def start_server(self):
        """启动HTTP API服务器"""
        from aiohttp import web
        
        app = web.Application()
        
        # 路由配置
        app.router.add_post('/api/tasks', self.create_task)
        app.router.add_get('/api/tasks/{task_id}', self.get_task)
        app.router.add_get('/api/stats', self.get_stats)
        app.router.add_get('/api/health', self.health_check)
        
        # 启动服务器
        runner = web.AppRunner(app)
        await runner.setup()
        
        site = web.TCPSite(runner, 'localhost', self.port)
        await site.start()
        
        logger.info(f"Bridge API 服务器已启动: http://localhost:{self.port}")

    async def create_task(self, request):
        """创建任务API"""
        from aiohttp import web
        
        try:
            data = await request.json()
            task_type = data.get('type')
            payload = data.get('payload', {})
            
            task_id = await self.bridge.submit_task(task_type, payload)
            
            return web.json_response({
                "success": True,
                "task_id": task_id
            })
        except Exception as e:
            return web.json_response({
                "success": False,
                "error": str(e)
            }, status=400)

    async def get_task(self, request):
        """获取任务状态API"""
        from aiohttp import web
        
        task_id = request.match_info['task_id']
        status = await self.bridge.get_task_status(task_id)
        
        if not status:
            return web.json_response({
                "success": False,
                "error": "任务不存在"
            }, status=404)
        
        return web.json_response({
            "success": True,
            "task": status
        })

    async def get_stats(self, request):
        """获取统计信息API"""
        from aiohttp import web
        
        stats = self.bridge.get_stats()
        return web.json_response({
            "success": True,
            "stats": stats
        })

    async def health_check(self, request):
        """健康检查API"""
        from aiohttp import web
        
        return web.json_response({
            "success": True,
            "status": "healthy",
            "timestamp": time.time()
        })

# 主程序入口
async def main():
    """主程序"""
    # 信号处理
    bridge = BridgeService()
    api = BridgeAPI(bridge)
    
    def signal_handler(signum, frame):
        logger.info(f"收到信号 {signum}，正在优雅停止...")
        asyncio.create_task(bridge.stop())
    
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    try:
        # 启动服务
        await asyncio.gather(
            bridge.start(),
            api.start_server()
        )
    except KeyboardInterrupt:
        logger.info("收到中断信号，正在停止服务...")
    finally:
        await bridge.stop()

if __name__ == "__main__":
    asyncio.run(main())