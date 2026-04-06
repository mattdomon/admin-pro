<?php
declare(strict_types=1);

namespace app\controller;

use think\Response;

/**
 * 脚本管理控制器
 * 
 * 管理 OpenClaw Python 脚本的 CRUD、执行、模板等功能
 */
class ScriptCtrl extends BaseController
{
    /**
     * 脚本存储目录
     */
    private const SCRIPT_DIR = '/storage/openclaw_scripts/';
    
    /**
     * 获取脚本列表
     */
    public function list(): Response
    {
        try {
            $scriptDir = app()->getRootPath() . self::SCRIPT_DIR;
            
            if (!is_dir($scriptDir)) {
                mkdir($scriptDir, 0755, true);
            }
            
            $scripts = [];
            $files = glob($scriptDir . '*.py');
            
            foreach ($files as $file) {
                $filename = basename($file);
                $content = file_get_contents($file);
                $stats = stat($file);
                
                // 解析脚本信息
                $info = $this->parseScriptInfo($content);
                
                $scripts[] = [
                    'name' => $filename,
                    'title' => $info['title'] ?? $filename,
                    'description' => $info['description'] ?? '',
                    'author' => $info['author'] ?? '',
                    'version' => $info['version'] ?? '1.0.0',
                    'size' => $stats['size'],
                    'modified_time' => date('Y-m-d H:i:s', $stats['mtime']),
                    'line_count' => substr_count($content, "\n") + 1,
                ];
            }
            
            return $this->json(200, "success", $scripts);
            
        } catch (\Exception $e) {
            return $this->json(500, '获取脚本列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取脚本内容
     */
    public function get(): Response
    {
        $name = $this->request->param('name');
        
        if (!$name || !preg_match('/^[\w\-\.]+\.py$/', $name)) {
            return $this->json(500, '无效的脚本名称');
        }
        
        try {
            $scriptPath = app()->getRootPath() . self::SCRIPT_DIR . $name;
            
            if (!file_exists($scriptPath)) {
                return $this->json(500, '脚本文件不存在');
            }
            
            $content = file_get_contents($scriptPath);
            $stats = stat($scriptPath);
            $info = $this->parseScriptInfo($content);
            
            return $this->success([
                'name' => $name,
                'content' => $content,
                'info' => $info,
                'size' => $stats['size'],
                'modified_time' => date('Y-m-d H:i:s', $stats['mtime']),
            ]);
            
        } catch (\Exception $e) {
            return $this->json(500, '读取脚本失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 保存脚本
     */
    public function save(): Response
    {
        $name = $this->request->param('name');
        $content = $this->request->param('content', '');
        
        if (!$name || !preg_match('/^[\w\-\.]+\.py$/', $name)) {
            return $this->json(500, '无效的脚本名称');
        }
        
        if (empty($content)) {
            return $this->json(500, '脚本内容不能为空');
        }
        
        try {
            $scriptDir = app()->getRootPath() . self::SCRIPT_DIR;
            $scriptPath = $scriptDir . $name;
            
            if (!is_dir($scriptDir)) {
                mkdir($scriptDir, 0755, true);
            }
            
            // 语法检查
            $syntaxCheck = $this->checkPythonSyntax($content);
            if (!$syntaxCheck['valid']) {
                return $this->json(500, 'Python 语法错误: ' . $syntaxCheck['error']);
            }
            
            // 原子写入
            $tempFile = $scriptPath . '.tmp';
            file_put_contents($tempFile, $content, LOCK_EX);
            rename($tempFile, $scriptPath);
            
            // 设置执行权限
            chmod($scriptPath, 0755);
            
            return $this->success([
                'message' => '脚本保存成功',
                'name' => $name,
                'size' => strlen($content),
            ]);
            
        } catch (\Exception $e) {
            return $this->json(500, '保存脚本失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除脚本
     */
    public function delete(): Response
    {
        $name = $this->request->param('name');
        
        if (!$name || !preg_match('/^[\w\-\.]+\.py$/', $name)) {
            return $this->json(500, '无效的脚本名称');
        }
        
        try {
            $scriptPath = app()->getRootPath() . self::SCRIPT_DIR . $name;
            
            if (!file_exists($scriptPath)) {
                return $this->json(500, '脚本文件不存在');
            }
            
            unlink($scriptPath);
            
            return $this->json(200, "success", ['message' => '脚本删除成功']);
            
        } catch (\Exception $e) {
            return $this->json(500, '删除脚本失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取脚本模板
     */
    public function templates(): Response
    {
        $templates = [
            [
                'name' => 'hello.py',
                'title' => 'Hello World',
                'description' => '简单的 Hello World 脚本',
                'content' => $this->getHelloTemplate(),
            ],
            [
                'name' => 'openclaw_basic.py', 
                'title' => 'OpenClaw 基础模板',
                'description' => '包含 OpenClaw 常用功能的基础模板',
                'content' => $this->getOpenClawTemplate(),
            ],
            [
                'name' => 'web_scraper.py',
                'title' => '网页抓取模板', 
                'description' => '使用 requests 和 BeautifulSoup 的网页抓取脚本',
                'content' => $this->getWebScraperTemplate(),
            ]
        ];
        
        return $this->json(200, "success", $templates);
    }
    
    /**
     * 解析脚本元信息
     */
    private function parseScriptInfo(string $content): array
    {
        $info = [];
        
        // 解析文档字符串中的信息
        if (preg_match('/"""([^"]*?)"""/s', $content, $matches)) {
            $docstring = $matches[1];
            
            if (preg_match('/@title\s+(.+)/i', $docstring, $m)) {
                $info['title'] = trim($m[1]);
            }
            if (preg_match('/@description\s+(.+)/i', $docstring, $m)) {
                $info['description'] = trim($m[1]);
            }
            if (preg_match('/@author\s+(.+)/i', $docstring, $m)) {
                $info['author'] = trim($m[1]);
            }
            if (preg_match('/@version\s+(.+)/i', $docstring, $m)) {
                $info['version'] = trim($m[1]);
            }
        }
        
        return $info;
    }
    
    /**
     * 检查 Python 语法
     */
    private function checkPythonSyntax(string $content): array
    {
        // 创建临时文件
        $tempFile = sys_get_temp_dir() . '/syntax_check_' . uniqid() . '.py';
        file_put_contents($tempFile, $content);
        
        // 使用 python -m py_compile 检查语法
        $output = [];
        $returnCode = 0;
        exec("python3 -m py_compile '$tempFile' 2>&1", $output, $returnCode);
        
        // 清理临时文件
        unlink($tempFile);
        
        return [
            'valid' => $returnCode === 0,
            'error' => $returnCode === 0 ? null : implode("\n", $output),
        ];
    }
    
    /**
     * Hello World 模板
     */
    private function getHelloTemplate(): string
    {
        return <<<'PYTHON'
#!/usr/bin/env python3
"""
@title Hello World 脚本
@description 简单的 Hello World 示例脚本
@author OpenClaw Admin
@version 1.0.0
"""

import sys
import time

def main():
    print("🎉 Hello from OpenClaw Admin!")
    print(f"⏰ Current time: {time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"🐍 Python version: {sys.version}")
    
    # 模拟一些工作
    for i in range(1, 6):
        print(f"📊 Processing step {i}/5...")
        time.sleep(1)
    
    print("✅ Task completed successfully!")
    return 0

if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
PYTHON;
    }
    
    /**
     * OpenClaw 基础模板
     */
    private function getOpenClawTemplate(): string
    {
        return <<<'PYTHON'
#!/usr/bin/env python3
"""
@title OpenClaw 基础脚本模板
@description 包含 OpenClaw 常用功能的基础脚本模板
@author OpenClaw Admin
@version 1.0.0
"""

import os
import sys
import json
import logging
import argparse
from pathlib import Path

# 设置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s'
)
logger = logging.getLogger(__name__)

class OpenClawScript:
    """OpenClaw 脚本基类"""
    
    def __init__(self):
        self.workspace = Path(os.environ.get('WORKSPACE_DIR', '/app/workspace'))
        self.config = self.load_config()
    
    def load_config(self) -> dict:
        """加载配置文件"""
        config_file = self.workspace / 'config.json'
        if config_file.exists():
            with open(config_file, 'r') as f:
                return json.load(f)
        return {}
    
    def save_result(self, result: dict):
        """保存执行结果"""
        result_file = self.workspace / 'result.json'
        with open(result_file, 'w') as f:
            json.dump(result, f, indent=2, ensure_ascii=False)
        logger.info(f"结果已保存到: {result_file}")
    
    def run(self, objective: str, **kwargs) -> dict:
        """主要执行逻辑"""
        logger.info(f"🚀 开始执行任务: {objective}")
        
        try:
            # 在这里实现你的具体逻辑
            result = self.execute_task(objective, **kwargs)
            
            logger.info("✅ 任务执行成功")
            return {
                'status': 'success',
                'result': result,
                'message': '任务执行成功'
            }
            
        except Exception as e:
            logger.error(f"❌ 任务执行失败: {e}")
            return {
                'status': 'failed',
                'error': str(e),
                'message': '任务执行失败'
            }
    
    def execute_task(self, objective: str, **kwargs) -> dict:
        """具体的任务执行逻辑 - 子类需要重写此方法"""
        # 示例实现
        import time
        
        logger.info(f"📋 目标: {objective}")
        logger.info(f"📊 参数: {kwargs}")
        
        # 模拟工作
        for i in range(3):
            logger.info(f"🔄 处理阶段 {i+1}/3...")
            time.sleep(2)
        
        return {
            'objective': objective,
            'processed_at': time.strftime('%Y-%m-%d %H:%M:%S'),
            'parameters': kwargs
        }

def main():
    parser = argparse.ArgumentParser(description='OpenClaw 基础脚本')
    parser.add_argument('--objective', required=True, help='任务目标')
    parser.add_argument('--config', help='配置文件路径')
    
    args = parser.parse_args()
    
    script = OpenClawScript()
    result = script.run(args.objective)
    script.save_result(result)
    
    return 0 if result['status'] == 'success' else 1

if __name__ == "__main__":
    sys.exit(main())
PYTHON;
    }
    
    /**
     * 网页抓取模板
     */
    private function getWebScraperTemplate(): string
    {
        return <<<'PYTHON'
#!/usr/bin/env python3
"""
@title 网页抓取脚本
@description 使用 requests 和 BeautifulSoup 抓取网页内容
@author OpenClaw Admin
@version 1.0.0
"""

import requests
from bs4 import BeautifulSoup
import json
import time
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def scrape_website(url: str) -> dict:
    """抓取网站内容"""
    try:
        logger.info(f"🌐 正在抓取: {url}")
        
        headers = {
            'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
        }
        
        response = requests.get(url, headers=headers, timeout=10)
        response.raise_for_status()
        
        soup = BeautifulSoup(response.content, 'html.parser')
        
        # 提取基本信息
        title = soup.find('title')
        title_text = title.get_text().strip() if title else '无标题'
        
        # 提取所有链接
        links = []
        for link in soup.find_all('a', href=True):
            links.append({
                'text': link.get_text().strip(),
                'href': link['href']
            })
        
        # 提取所有图片
        images = []
        for img in soup.find_all('img', src=True):
            images.append({
                'alt': img.get('alt', ''),
                'src': img['src']
            })
        
        return {
            'url': url,
            'title': title_text,
            'links': links[:10],  # 只返回前10个链接
            'images': images[:10],  # 只返回前10个图片
            'scraped_at': time.strftime('%Y-%m-%d %H:%M:%S')
        }
        
    except Exception as e:
        logger.error(f"❌ 抓取失败: {e}")
        raise

def main():
    url = input("请输入要抓取的网址: ").strip()
    
    if not url.startswith(('http://', 'https://')):
        url = 'https://' + url
    
    try:
        result = scrape_website(url)
        
        print("\n" + "="*50)
        print(f"📄 标题: {result['title']}")
        print(f"🔗 链接数量: {len(result['links'])}")
        print(f"🖼️  图片数量: {len(result['images'])}")
        print("="*50)
        
        # 保存结果
        with open('scrape_result.json', 'w', encoding='utf-8') as f:
            json.dump(result, f, indent=2, ensure_ascii=False)
        
        print("✅ 结果已保存到 scrape_result.json")
        return 0
        
    except Exception as e:
        logger.error(f"❌ 脚本执行失败: {e}")
        return 1

if __name__ == "__main__":
    exit(main())
PYTHON;
    }
}