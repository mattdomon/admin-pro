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

# 系统监控相关
try:
    import psutil
    PSUTIL_AVAILABLE = True
except ImportError:
    PSUTIL_AVAILABLE = False
    logger.warning("psutil not available, system metrics collection disabled")

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
        
        # 系统监控
        self.metrics_enabled = PSUTIL_AVAILABLE
        self.last_metrics = {}
        
        # 确保必要目录存在
        os.makedirs('logs', exist_ok=True)
        os.makedirs('data/tasks', exist_ok=True)
        
        # ── 阶段三：SaaS 鉴权状态 ────────────────────────────────────────
        # node_key 从 bridge.json 读取；为空时代表未绑定，节点将停留在未鉴权状态
        self.node_key: str = self.config.get("node_key", "")
        # 标识当前是否需要热重载（写入新 key 后置 True，_connect_websocket 检测后重连）
        self._reload_flag: bool = False
        # ────────────────────────────────────────────────────────────────

        logger.info(f"Bridge Service 初始化完成 - Node ID: {self.node_id}")
        if not self.metrics_enabled:
            logger.warning("系统监控功能已禁用 - 请安装 psutil: pip install psutil")

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
        
        # 启动各类型任务处理器、WebSocket 连接和系统监控
        await asyncio.gather(
            self._start_task_processor(),
            self._start_status_monitor(),
            self._start_cleanup_worker(),
            self._start_websocket_client(),  # WebSocket 客户端
            self._collect_system_metrics(),  # 系统监控采集器
            self._start_local_ui(),          # 阶段三：本地 WebUI (8890)
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
        """处理AI任务，调用AI API"""
        payload = task.payload
        model_path = payload.get('model_path', '')
        messages   = payload.get('messages', [])
        base_url   = payload.get('base_url', '')
        api_key    = payload.get('api_key', '')
        api_type   = payload.get('api_type', 'openai')

        if not base_url or not api_key:
            raise ValueError("base_url 和 api_key 不能为空")
        if not model_path:
            raise ValueError("model_path 不能为空")

        model_id = model_path.split('/')[-1] if '/' in model_path else model_path
        timeout = aiohttp.ClientTimeout(total=120)

        if api_type == 'anthropic':
            headers = {
                'Content-Type': 'application/json',
                'x-api-key': api_key,
                'anthropic-version': '2023-06-01',
            }
            body = {'model': model_id, 'messages': messages, 'max_tokens': 4096}
            url = base_url.rstrip('/') + '/v1/messages'
        else:
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {api_key}',
            }
            body = {'model': model_id, 'messages': messages, 'max_tokens': 4096}
            url = base_url.rstrip('/') + '/v1/chat/completions'

        async with aiohttp.ClientSession(timeout=timeout) as session:
            async with session.post(url, headers=headers, json=body, ssl=False) as resp:
                if resp.status != 200:
                    text = await resp.text()
                    raise ValueError(f"AI API 返回 {resp.status}: {text[:300]}")
                data = await resp.json()

        if api_type == 'anthropic':
            content = ''.join(c.get('text', '') for c in data.get('content', []))
        else:
            content = data.get('choices', [{}])[0].get('message', {}).get('content', '')

        return {
            'content': content,
            'model': model_path,
            'usage': data.get('usage', {}),
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

    async def _collect_system_metrics(self):
        """系统指标采集器 - 每5秒采集一次系统数据"""
        if not self.metrics_enabled:
            logger.info("系统监控已禁用 - psutil 不可用")
            return
            
        logger.info("系统监控采集器已启动")
        
        while self.running:
            try:
                # 采集系统指标
                cpu_percent = psutil.cpu_percent(interval=1)
                memory = psutil.virtual_memory()
                disk = psutil.disk_usage('/')
                
                # 网络IO (增量计算)
                net_io = psutil.net_io_counters()
                
                # 计算网络速率 (需要上次数据)
                net_speed = {"sent_per_sec": 0, "recv_per_sec": 0}
                if hasattr(self, '_last_net_io'):
                    time_delta = 5.0  # 5秒间隔
                    sent_delta = net_io.bytes_sent - self._last_net_io.bytes_sent
                    recv_delta = net_io.bytes_recv - self._last_net_io.bytes_recv
                    net_speed = {
                        "sent_per_sec": sent_delta / time_delta,
                        "recv_per_sec": recv_delta / time_delta
                    }
                self._last_net_io = net_io
                
                # 组装监控数据
                metrics = {
                    "timestamp": time.time(),
                    "cpu": {
                        "usage_percent": round(cpu_percent, 2),
                        "count": psutil.cpu_count()
                    },
                    "memory": {
                        "total": memory.total,
                        "used": memory.used, 
                        "available": memory.available,
                        "usage_percent": round(memory.percent, 2)
                    },
                    "disk": {
                        "total": disk.total,
                        "used": disk.used,
                        "free": disk.free,
                        "usage_percent": round((disk.used / disk.total) * 100, 2)
                    },
                    "network": {
                        "bytes_sent": net_io.bytes_sent,
                        "bytes_recv": net_io.bytes_recv,
                        "sent_per_sec": round(net_speed["sent_per_sec"], 2),
                        "recv_per_sec": round(net_speed["recv_per_sec"], 2)
                    },
                    "tasks": {
                        "total": len(self.tasks),
                        "running": sum(1 for t in self.tasks.values() if t.status == TaskStatus.RUNNING),
                        "pending": sum(1 for t in self.tasks.values() if t.status == TaskStatus.PENDING),
                        "completed": sum(1 for t in self.tasks.values() if t.status == TaskStatus.SUCCESS),
                        "failed": sum(1 for t in self.tasks.values() if t.status == TaskStatus.FAILED)
                    }
                }
                
                self.last_metrics = metrics
                
                # 通过WebSocket广播给所有客户端
                await self._broadcast_metrics(metrics)
                
                # 每分钟发送一次历史数据到后端 (用于持久化)
                if int(time.time()) % 60 == 0:
                    await self._save_metrics_to_backend(metrics)
                
                await asyncio.sleep(5)  # 5秒采集间隔
                
            except Exception as e:
                logger.error(f"系统监控采集错误: {e}")
                await asyncio.sleep(10)  # 出错后10秒重试

    async def _broadcast_metrics(self, metrics: Dict[str, Any]):
        """广播系统指标到WebSocket客户端"""
        if not self.ws_connected or not self.ws_client:
            return
            
        try:
            message = {
                "type": "sys_metrics",
                "data": metrics,
                "node_id": self.node_id
            }
            
            await self.ws_client.send(json.dumps(message))
            logger.debug(f"系统指标已广播: CPU {metrics['cpu']['usage_percent']}%")
            
        except Exception as e:
            logger.error(f"指标广播失败: {e}")

    async def _save_metrics_to_backend(self, metrics: Dict[str, Any]):
        """发送监控数据到后端进行持久化存储"""
        try:
            # 发送到后端API进行数据库存储
            async with aiohttp.ClientSession() as session:
                backend_url = "http://localhost:8000/api/monitor/saveMetrics"
                
                # 简化数据结构，只保存关键指标用于趋势分析
                payload = {
                    "timestamp": int(metrics["timestamp"]),
                    "cpu_percent": metrics["cpu"]["usage_percent"],
                    "memory_percent": metrics["memory"]["usage_percent"],
                    "disk_percent": metrics["disk"]["usage_percent"],
                    "network_sent": metrics["network"]["sent_per_sec"],
                    "network_recv": metrics["network"]["recv_per_sec"],
                    "tasks_total": metrics["tasks"]["total"],
                    "tasks_running": metrics["tasks"]["running"],
                    "tasks_failed": metrics["tasks"]["failed"]
                }
                
                async with session.post(backend_url, json=payload) as resp:
                    if resp.status == 200:
                        logger.debug("监控数据已保存到后端")
                    else:
                        logger.warning(f"监控数据保存失败: {resp.status}")
                        
        except Exception as e:
            logger.error(f"监控数据保存到后端失败: {e}")

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
        """建立 WebSocket 连接并维持

        SaaS 阶段三改造要点：
          1. 连接成功后立刻发送 node_key 鉴权包（原来的 register 消息已替换）
          2. 检测 _reload_flag，为 True 时主动断开并用新 key 重连
          3. node_key 为空时不连接，轮询等待用户登录
        """
        gateway_url = self.config.get('cloud_gateway',
                       self.config.get('openclaw', {}).get('gateway_url', 'ws://localhost:8282'))

        max_backoff = 300
        base_delay  = 1

        while self.running:

            # ── 未配置 node_key：轮询等待登录 ─────────────────────
            if not self.node_key:
                logger.info("⏳ node_key 未配置，等待本地登录... (访问 http://localhost:8890)")
                await asyncio.sleep(3)
                # 尝试从文件重读（用户可能在等待期间完成登录）
                self._reload_config_from_file()
                continue

            try:
                logger.info(f"正在连接到云端网关: {gateway_url}")

                async with websockets.connect(
                    gateway_url,
                    ping_interval=20,
                    ping_timeout=10,
                    close_timeout=10
                ) as websocket:
                    self.ws_client              = websocket
                    self.ws_connected           = True
                    self.ws_reconnect_attempts  = 0

                    # ── 鉴权包：首个报文必须是 auth ───────────────
                    await websocket.send(json.dumps({
                        "type":     "auth",
                        "node_key": self.node_key,
                    }))
                    logger.info(f"🔑 已发送鉴权包 node_key=...{self.node_key[-4:]}")

                    # 启动心跳任务
                    heartbeat_task = asyncio.create_task(
                        self._websocket_heartbeat(websocket)
                    )

                    try:
                        async for message in websocket:
                            # ── 热重载：写入新 key 后主动断开 ─────────
                            if self._reload_flag:
                                self._reload_flag = False
                                self._reload_config_from_file()
                                logger.info("🔄 热重载：断开旧连接，将用新 node_key 重连")
                                break  # 跳出内层循环，重连
                            await self._handle_websocket_message(message)

                    except websockets.exceptions.ConnectionClosed:
                        logger.warning("WebSocket 连接已关闭")
                    finally:
                        heartbeat_task.cancel()
                        self.ws_connected = False
                        self.ws_client    = None

            except Exception as e:
                self.ws_connected          = False
                self.ws_client             = None
                self.ws_reconnect_attempts += 1

                delay       = min(base_delay * (2 ** self.ws_reconnect_attempts), max_backoff)
                jitter      = random.uniform(0.5, 1.5)
                actual_delay = delay * jitter

                logger.warning(
                    f"WebSocket 连接失败 (第{self.ws_reconnect_attempts}次): {e}. "
                    f"{actual_delay:.1f}秒后重试"
                )
                await asyncio.sleep(actual_delay)
                
    async def _register_node(self, websocket):
        """向服务器注册节点（保留兼容旧逻辑）"""
        register_msg = {
            "type": "register",
            "node_id": self.node_id,
            "timestamp": time.time(),
            "version": "1.0",
            "capabilities": ["task_processing", "script_execution"]
        }
        await websocket.send(json.dumps(register_msg))
        logger.info(f"✨ 已发送节点注册: {self.node_id}")

    # ═══════════════════════════════════════════════════════════════
    # 阶段三：SaaS 核心协程
    # ═══════════════════════════════════════════════════════════════

    def _reload_config_from_file(self):
        """从 bridge.json 热重载配置（不重启进程）"""
        try:
            with open(self.config_path, 'r', encoding='utf-8') as f:
                new_cfg = json.load(f)
            self.config   = new_cfg
            self.node_key = new_cfg.get('node_key', '')
            logger.info(f"🔄 配置已热重载，node_key=...{self.node_key[-4:] if self.node_key else '(空)'}")
        except Exception as e:
            logger.error(f"热重载配置失败: {e}")

    async def _cloud_login_and_get_key(self, username: str, password: str, node_name: str) -> dict:
        """
        代理登录云端并获取 node_key，全程三步：
          1. POST /api/auth/login        → 拿 Token
          2. POST /api/nodes/generate    → 拿 node_key
          3. 原子写入 config/bridge.json → 触发热重载

        成功返回: {'ok': True, 'node_key': '...', 'node_name': '...'}
        失败返回: {'ok': False, 'error': '...'}
        """
        cloud_base = self.config.get('cloud_base_url', 'http://localhost:8000')

        try:
            async with aiohttp.ClientSession() as session:

                # ── Step 1: 登录 ──────────────────────────────────────
                async with session.post(
                    f'{cloud_base}/api/auth/login',
                    json={'username': username, 'password': password},
                    timeout=aiohttp.ClientTimeout(total=10)
                ) as resp:
                    body = await resp.json()
                    if body.get('code') != 200:
                        return {'ok': False, 'error': body.get('message', '登录失败')}

                    token = body['data']['token']
                    user  = body['data']['user']
                    logger.info(f"✅ 云端登录成功: user_id={user['id']}, username={user['username']}")

                # ── Step 2: 生成 node_key ─────────────────────────────
                async with session.post(
                    f'{cloud_base}/api/nodes/generate',
                    json={'node_name': node_name or f'bridge-{self.node_id[-6:]}'},
                    headers={'Authorization': f'Bearer {token}'},
                    timeout=aiohttp.ClientTimeout(total=10)
                ) as resp:
                    body = await resp.json()
                    if body.get('code') != 200:
                        return {'ok': False, 'error': body.get('message', '获取 node_key 失败')}

                    node_key  = body['data']['node_key']
                    node_name = body['data']['node_name']
                    logger.info(f"✅ node_key 已获取: ...{node_key[-4:]} ({node_name})")

            # ── Step 3: 原子写入 bridge.json ──────────────────────────
            new_cfg = dict(self.config)  # 浅拷贝，保留旧字段
            new_cfg['node_key']       = node_key
            new_cfg['node_name']      = node_name
            new_cfg['cloud_base_url'] = cloud_base
            # 从 cloud_base_url 推导 cloud_gateway WS 地址
            # http://host:8000  →  ws://host:8282
            # https://host      →  wss://host:8282
            import re as _re
            _m = _re.match(r'(https?)://([^:/]+)', cloud_base)
            if _m:
                _scheme = 'wss' if _m.group(1) == 'https' else 'ws'
                _host   = _m.group(2)
                _derived_gw = f'{_scheme}://{_host}:8282'
            else:
                _derived_gw = 'ws://localhost:8282'

            new_cfg['cloud_gateway'] = self.config.get('cloud_gateway', _derived_gw)

            tmp_path = self.config_path + '.tmp'
            with open(tmp_path, 'w', encoding='utf-8') as f:
                json.dump(new_cfg, f, indent=2, ensure_ascii=False)
            os.replace(tmp_path, self.config_path)  # 原子替换，防损坏

            logger.info(f"💾 bridge.json 已写入，触发热重载")

            # ── Step 4: 设置热重载标志，_connect_websocket 检测到后断开重连 ──
            self._reload_flag = True

            return {'ok': True, 'node_key': node_key, 'node_name': node_name}

        except aiohttp.ClientConnectorError:
            return {'ok': False, 'error': f'无法连接云端: {cloud_base}，请检查地址'}
        except asyncio.TimeoutError:
            return {'ok': False, 'error': '云端请求超时，请稍后重试'}
        except Exception as e:
            logger.error(f"_cloud_login_and_get_key 异常: {e}")
            return {'ok': False, 'error': str(e)}

    async def _start_local_ui(self):
        """
        本地 WebUI 服务（8890 端口）

        路由：
          GET  /              — 登录/状态页面（纯 HTML，无文件挂载）
          POST /api/local/login  — 代理云端登录，写 node_key，触发热重载
          GET  /api/local/status — 返回 WS 连接状态，供前端轮询
        """
        from aiohttp import web

        # ── HTML 模板（内联，无需静态文件） ───────────────────────────
        HTML = """\
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bridge 本地配置面板</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       background:#0f172a;color:#e2e8f0;min-height:100vh;
       display:flex;align-items:center;justify-content:center}
  .card{background:#1e293b;border-radius:12px;padding:36px 40px;
        width:400px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
  h1{font-size:1.3rem;font-weight:700;margin-bottom:4px;color:#f8fafc}
  .sub{font-size:.85rem;color:#94a3b8;margin-bottom:28px}
  label{display:block;font-size:.8rem;color:#94a3b8;margin-bottom:6px}
  input{width:100%;padding:10px 14px;background:#0f172a;border:1px solid #334155;
        border-radius:8px;color:#e2e8f0;font-size:.95rem;outline:none}
  input:focus{border-color:#6366f1}
  .field{margin-bottom:16px}
  button{width:100%;padding:12px;background:#6366f1;color:#fff;
         border:none;border-radius:8px;font-size:1rem;
         font-weight:600;cursor:pointer;margin-top:8px;transition:.2s}
  button:hover{background:#4f46e5}
  button:disabled{background:#334155;cursor:not-allowed}
  .status{margin-top:20px;padding:12px;border-radius:8px;
          font-size:.85rem;display:none}
  .status.ok {background:#064e3b;color:#6ee7b7;border:1px solid #065f46}
  .status.err{background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
  .status.inf{background:#1e3a5f;color:#93c5fd;border:1px solid #1e40af}
  .badge{display:inline-block;padding:3px 10px;border-radius:99px;
         font-size:.75rem;font-weight:600}
  .badge.online {background:#065f46;color:#6ee7b7}
  .badge.offline{background:#7f1d1d;color:#fca5a5}
  .badge.pending{background:#713f12;color:#fcd34d}
  .ws-bar{margin-top:24px;padding:14px;background:#0f172a;
          border-radius:8px;font-size:.82rem;color:#94a3b8}
  .ws-bar .row{display:flex;justify-content:space-between;margin-bottom:6px}
  .ws-bar .row:last-child{margin-bottom:0}
</style>
</head>
<body>
<div class="card">
  <h1>🔗 Bridge 本地配置面板</h1>
  <p class="sub">输入云端账号，完成节点绑定</p>

  <div class="field">
    <label>云端地址</label>
    <input id="baseUrl" type="text" value="http://localhost:8000" placeholder="http://your-cloud.com">
  </div>
  <div class="field">
    <label>账号</label>
    <input id="username" type="text" placeholder="admin">
  </div>
  <div class="field">
    <label>密码</label>
    <input id="password" type="password" placeholder="••••••••">
  </div>
  <div class="field">
    <label>节点备注名</label>
    <input id="nodeName" type="text" placeholder="我的本地服务器">
  </div>
  <button id="btn" onclick="doLogin()">绑定到云端</button>
  <div class="status" id="msg"></div>

  <div class="ws-bar" id="wsBar">
    <div class="row"><span>连接状态</span><span id="wsStatus">—</span></div>
    <div class="row"><span>节点 Key</span><span id="wsKey">—</span></div>
    <div class="row"><span>云端</span><span id="wsCloud">—</span></div>
  </div>
</div>

<script>
async function doLogin(){
  const btn=document.getElementById('btn');
  const msg=document.getElementById('msg');
  btn.disabled=true; btn.textContent='绑定中...';
  msg.style.display='none';
  const body={
    base_url: document.getElementById('baseUrl').value.trim(),
    username: document.getElementById('username').value.trim(),
    password: document.getElementById('password').value,
    node_name:document.getElementById('nodeName').value.trim()
  };
  try{
    const r=await fetch('/api/local/login',{
      method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify(body)
    });
    const d=await r.json();
    if(d.ok){
      show(msg,'ok','✅ 绑定成功！节点 Key: ...'+d.node_key.slice(-4)+'  正在重连云端...');
    }else{
      show(msg,'err','❌ '+d.error);
    }
  }catch(e){
    show(msg,'err','❌ 请求失败: '+e.message);
  }
  btn.disabled=false; btn.textContent='绑定到云端';
}

function show(el,cls,text){
  el.className='status '+cls;
  el.textContent=text;
  el.style.display='block';
}

async function pollStatus(){
  try{
    const r=await fetch('/api/local/status');
    const d=await r.json();
    const badge=(d.ws_connected)?
      '<span class="badge online">● 在线</span>':
      '<span class="badge offline">● 离线</span>';
    document.getElementById('wsStatus').innerHTML=badge;
    document.getElementById('wsKey').textContent=d.node_key_suffix||'(未绑定)';
    document.getElementById('wsCloud').textContent=d.cloud_status||'—';

    // 断连时显示红幅警告
    const bar=document.getElementById('wsBar');
    bar.style.border=d.ws_connected?'1px solid #1e40af':'2px solid #dc2626';
  }catch(_){}
  setTimeout(pollStatus,3000);
}
pollStatus();
</script>
</body></html>
"""

        async def handle_index(request):
            return web.Response(text=HTML, content_type='text/html')

        async def handle_login(request):
            """代理登录云端，写入 bridge.json，触发热重载"""
            try:
                data      = await request.json()
                base_url  = data.get('base_url', '').rstrip('/')
                username  = data.get('username', '')
                password  = data.get('password', '')
                node_name = data.get('node_name', '')

                if not all([base_url, username, password]):
                    return web.json_response({'ok': False, 'error': '地址/账号/密码不能为空'})

                # 临时更新 cloud_base_url，让 _cloud_login_and_get_key 使用
                self.config['cloud_base_url'] = base_url

                result = await self._cloud_login_and_get_key(username, password, node_name)
                return web.json_response(result)

            except Exception as e:
                return web.json_response({'ok': False, 'error': str(e)})

        async def handle_status(request):
            """返回实时连接状态（供本地页面轮询）"""
            suffix = ''
            if self.node_key:
                suffix = '...' + self.node_key[-4:]  # 只展示后4位

            cloud_url = self.config.get('cloud_base_url', '')
            return web.json_response({
                'ws_connected':    self.ws_connected,
                'node_key_suffix': suffix,
                'cloud_status':    'online' if self.ws_connected else 'offline',
                'cloud_url':       cloud_url,
                'uptime_s':        int(time.time() - self._start_time) if hasattr(self, '_start_time') else 0,
            })

        # ── 启动 Web 服务器 ───────────────────────────────────────────
        app = web.Application()
        app.router.add_get('/',                 handle_index)
        app.router.add_post('/api/local/login', handle_login)
        app.router.add_get('/api/local/status', handle_status)

        runner = web.AppRunner(app)
        await runner.setup()
        site = web.TCPSite(runner, '0.0.0.0', 8890)
        await site.start()

        self._start_time = time.time()
        logger.info("🌐 本地 WebUI 已启动: http://localhost:8890")

        # 保持协程存活
        while self.running:
            await asyncio.sleep(1)
        
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
    bridge = BridgeService()

    def signal_handler(signum, frame):
        logger.info(f"收到信号 {signum}，正在优雅停止...")
        asyncio.create_task(bridge.stop())

    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    try:
        # bridge.start() 内部已包含：
        #   _start_task_processor / _start_status_monitor / _start_cleanup_worker
        #   _start_websocket_client / _collect_system_metrics
        #   _start_local_ui  (8890 端口，阶段三新增)
        await bridge.start()
    except KeyboardInterrupt:
        logger.info("收到中断信号，正在停止服务...")
    finally:
        await bridge.stop()

if __name__ == "__main__":
    asyncio.run(main())