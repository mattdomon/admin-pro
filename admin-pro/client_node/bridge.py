#!/usr/bin/env python3
"""
bridge.py - OpenClaw Admin 客户端核心中转器

负责:
1. WebSocket 重连机制
2. 异步非阻塞子进程拉起 (PGID 进程组管理 + 僵尸进程斩杀)
3. 日志防抖限流缓冲 (asyncio.Queue, 满20条或500ms刷新)
4. 路径沙箱限制 (WORKSPACE_DIR 环境变量)
"""

import asyncio
import json
import logging
import os
import signal
import sys
import time
import uuid
from pathlib import Path
from typing import Dict, Optional

import websockets
import aiohttp

# === 配置 ===
SERVER_WS_URL = os.environ.get("SERVER_WS_URL", "ws://127.0.0.1:8282")
NODE_TOKEN = os.environ.get("NODE_TOKEN", "")
NODE_UUID = os.environ.get("NODE_UUID", f"NODE_{uuid.uuid4().hex[:8]}")
WORKSPACE_DIR = Path(os.environ.get("WORKSPACE_DIR", "/app/workspace"))

# 日志缓冲配置
LOG_BATCH_SIZE = 20         # 满 20 条打包发送
LOG_FLUSH_INTERVAL = 0.5    # 每 500ms 刷新一次

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s: %(message)s'
)
logger = logging.getLogger(__name__)


class LogBuffer:
    """
    日志防抖限流缓冲区
    - asyncio.Queue 收集子进程输出
    - 每满 LOG_BATCH_SIZE 条 或 每 LOG_FLUSH_INTERVAL 秒打包发送
    """

    def __init__(self, ws_send_callback):
        self._queue = asyncio.Queue()
        self._send_callback = ws_send_callback
        self._running = False

    async def start(self):
        self._running = True
        await self._flush_loop()

    async def stop(self):
        self._running = False

    async def push(self, task_id: str, level: str, msg: str):
        await self._queue.put({
            "ts": int(time.time()),
            "level": level,
            "msg": msg
        })

    async def _flush_loop(self):
        """主循环: 满足任一条件即打包发送"""
        while self._running:
            logs = []
            try:
                # 等待第一日志
                first_log = await asyncio.wait_for(self._queue.get(), timeout=LOG_FLUSH_INTERVAL)
                logs.append(first_log)

                # 尝试在超时内攒够一批
                while len(logs) < LOG_BATCH_SIZE:
                    try:
                        log = self._queue.get_nowait()
                        logs.append(log)
                    except asyncio.QueueEmpty:
                        await asyncio.sleep(0.05)
            except asyncio.TimeoutError:
                # 超时了也要把已有的发出去
                pass

            if logs:
                await self._send_callback(json.dumps({
                    "action": "cot_logs",
                    "task_id": "flush",  # 会被实际task_id覆盖
                    "logs": logs
                }))


class TaskManager:
    """
    任务管理器 - 负责子进程拉起 + PGID 进程组斩杀
    """

    def __init__(self):
        self._tasks: Dict[str, asyncio.subprocess.Process] = {}
        self._locks: Dict[str, asyncio.Lock] = {}

    async def start_task(self, task_id: str, script_path: Path, params_json: list = None):
        if task_id in self._tasks:
            raise RuntimeError(f"Task {task_id} already running")

        cmd = ["python", "-u", str(script_path)]
        if params_json:
            cmd += params_json

        logger.info(f"▶️  启动任务 {task_id}: {' '.join(cmd)}")

        process = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            # 🔪 关键: 新进程组 (PGID) 隔离
            preexec_fn=os.setsid
        )

        self._tasks[task_id] = process
        logger.info(f"✅ 任务 {task_id} 已启动 (PID: {process.pid})")

        return process

    async def kill_task(self, task_id: str):
        if task_id not in self._tasks:
            logger.warning(f"⚠️ 任务 {task_id} 不存在, 无法终止")
            return False

        process = self._tasks[task_id]

        try:
            # 🔪 PGID 僵尸进程斩杀: 杀掉整个进程树 (包括无头浏览器)
            pgid = os.getpgid(process.pid)
            os.killpg(pgid, signal.SIGTERM)
            logger.info(f"🔪 已发送 SIGTERM 到进程组 {pgid} (任务: {task_id})")

            # 等 3 秒, 如果还没退出就 SIGKILL
            try:
                await asyncio.wait_for(process.wait(), timeout=3.0)
            except asyncio.TimeoutError:
                os.killpg(pgid, signal.SIGKILL)
                logger.info(f"⚠️ SIGKILL 已发送到进程组 {pgid} (任务: {task_id})")
                await process.wait()

            logger.info(f"🛑 任务 {task_id} 已终止")
        except ProcessLookupError:
            logger.warning(f"⚠️ 进程组 {task_id} 已不存在")
        except Exception as e:
            logger.error(f"❌ 终止任务 {task_id} 异常: {e}")

        if task_id in self._tasks:
            del self._tasks[task_id]
        return True

    async def read_output(self, task_id: str, log_buffer: LogBuffer):
        process = self._tasks.get(task_id)
        if not process:
            return

        async def _read_stream(stream, level):
            while True:
                line = await stream.readline()
                if not line:
                    break
                text = line.decode("utf-8", errors="replace").strip()
                if text:
                    await log_buffer.push(task_id, level, text)

        await asyncio.gather(
            _read_stream(process.stdout, "INFO"),
            _read_stream(process.stderr, "ERROR")
        )

        # 等进程结束
        await process.wait()
        return_code = process.returncode

        if task_id in self._tasks:
            del self._tasks[task_id]

        return return_code


class BridgeClient:
    """
    WebSocket 客户端 - 负责与服务端通信
    """

    def __init__(self):
        self.ws = None
        self.task_manager = TaskManager()
        self.log_buffer = None
        self._running = False
        self._reconnect_delay = 1  # 初始重连延迟 1s
        self._max_reconnect_delay = 30  # 最大重连延迟 30s

    async def connect(self):
        logger.info(f"🔗 连接服务器: {SERVER_WS_URL} (UUID: {NODE_UUID})")
        
        extra_headers = {}
        if NODE_TOKEN:
            extra_headers["Authorization"] = f"Bearer {NODE_TOKEN}"

        self.ws = await asyncio.wait_for(
            websockets.connect(
                SERVER_WS_URL,
                additional_headers=extra_headers if extra_headers else None
            ),
            timeout=10
        )
        self._running = True
        self._reconnect_delay = 1  # 重置延迟

        logger.info("✅ 连接成功")

    async def send(self, message: str):
        if self.ws and self.ws.open:
            await self.ws.send(message)

    async def _send_log_batch(self, message: str):
        await self.send(message)

    async def run(self):
        while True:
            try:
                await self.connect()

                # 登录后发送认证消息
                auth_msg = json.dumps({
                    "action": "auth",
                    "uuid": NODE_UUID,
                    "token": NODE_TOKEN
                })
                await self.send(auth_msg)

                # 启动心跳
                asyncio.create_task(self._heartbeat())

                # 启动日志缓冲
                self.log_buffer = LogBuffer(self._send_log_batch)
                asyncio.create_task(self.log_buffer.start())

                # 消息接收循环
                async for message in self.ws:
                    await self._handle_message(message)

            except (websockets.exceptions.ConnectionClosed, ConnectionRefusedError, OSError) as e:
                logger.warning(f"❌ 连接断开 ({type(e).__name__}), {self._reconnect_delay}s 后重连...")
            except Exception as e:
                logger.error(f"💥 未知异常: {e}", exc_info=True)
                self._reconnect_delay = min(self._reconnect_delay * 2, self._max_reconnect_delay)

            # 清理
            self._running = False
            if self.log_buffer:
                await self.log_buffer.stop()

            # 重连延迟 (指数退避)
            await asyncio.sleep(self._reconnect_delay)

    async def _heartbeat(self):
        while self._running:
            try:
                # 获取系统信息
                import psutil
                cpu = psutil.cpu_percent()
                mem = psutil.virtual_memory()
                
                ping_msg = json.dumps({
                    "action": "ping",
                    "uuid": NODE_UUID,
                    "cpu": f"{cpu}%",
                    "mem": f"{mem.used // 1024 // 1024}MB"
                })
                await self.send(ping_msg)
            except ImportError:
                # 如果没有 psutil, 发送简化心跳
                ping_msg = json.dumps({
                    "action": "ping", 
                    "uuid": NODE_UUID
                })
                await self.send(ping_msg)
            except Exception as e:
                logger.warning(f"⚠️ 心跳发送失败: {e}")

            await asyncio.sleep(15)  # 每 15s 心跳

    async def _handle_message(self, message: str):
        try:
            data = json.loads(message)
            action = data.get("action")
            task_id = data.get("task_id")

            if action == "execute":
                await self._handle_execute(task_id, data.get("payload", {}))
            elif action == "kill":
                await self._handle_kill(task_id)
            else:
                logger.debug(f"📭 未知 action: {action}")

        except json.JSONDecodeError:
            logger.warning(f"⚠️ 无效 JSON: {message}")
        except Exception as e:
            logger.error(f"❌ 处理消息失败: {e}", exc_info=True)

    async def _handle_execute(self, task_id: str, payload: dict):
        if not task_id:
            logger.error("❌ 执行指令缺少 task_id")
            return

        target_script = payload.get("target_script", "")
        llm_config = payload.get("llm_config", {})
        objective = payload.get("objective", "")

        # 🔒 路径沙箱: 确保脚本在 WORKSPACE_DIR 内
        script_path = WORKSPACE_DIR / target_script
        try:
            script_path = script_path.resolve()
            if not str(script_path).startswith(str(WORKSPACE_DIR.resolve())):
                logger.error(f"❌ 目录穿越攻击: {target_script}")
                await self.send(json.dumps({
                    "action": "task_result",
                    "task_id": task_id,
                    "status": "failed",
                    "error": "路径越界"
                }))
                return
        except Exception as e:
            logger.error(f"❌ 路径解析失败: {e}")
            await self.send(json.dumps({
                "action": "task_result",
                "task_id": task_id,
                "status": "failed",
                "error": str(e)
            }))
            return

        if not script_path.exists():
            logger.error(f"❌ 脚本不存在: {script_path}")
            await self.send(json.dumps({
                "action": "task_result",
                "task_id": task_id,
                "status": "failed",
                "error": f"Script not found: {target_script}"
            }))
            return

        try:
            process = await self.task_manager.start_task(task_id, script_path)

            # 读取输出, 完成后发送结果
            return_code = await self.task_manager.read_output(task_id, self.log_buffer)

            await self.send(json.dumps({
                "action": "task_result",
                "task_id": task_id,
                "status": "success" if return_code == 0 else "failed",
                "return_code": return_code
            }))

        except Exception as e:
            logger.error(f"❌ 执行任务 {task_id} 失败: {e}", exc_info=True)
            await self.send(json.dumps({
                "action": "task_result",
                "task_id": task_id,
                "status": "failed",
                "error": str(e)
            }))

    async def _handle_kill(self, task_id: str):
        await self.task_manager.kill_task(task_id)
        await self.send(json.dumps({
            "action": "task_killed",
            "task_id": task_id
        }))


async def main():
    logger.info(f"🚀 OpenClaw Client Node 启动 (UUID: {NODE_UUID})")
    
    client = BridgeClient()
    await client.run()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("👋 收到 Ctrl+C, 退出")
        sys.exit(0)
