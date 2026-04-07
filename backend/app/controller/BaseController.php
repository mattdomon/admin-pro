<?php
declare(strict_types=1);

namespace app\controller;

use think\App;
use think\Request;

/**
 * 基础控制器
 */
abstract class BaseController
{
    /**
     * App实例
     */
    protected App $app;

    /**
     * Request实例
     */
    protected Request $request;

    /**
     * 是否批量验证
     */
    protected bool $batchValidate = false;

    /**
     * 控制器中间件
     */
    protected array $middleware = [];

    /**
     * 构造方法
     */
    public function __construct(?App $app = null)
    {
        $this->app = $app ?? app();
        $this->request = $this->app->request;
        $this->initialize();
    }

    /**
     * 初始化
     */
    protected function initialize()
    {}

    /**
     * 返回JSON
     */
    protected function json(int $code = 200, string $message = 'success', $data = null)
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 获取输入数据
     */
    protected function input(string $name = '', $default = null)
    {
        return input($name, $default);
    }
    
    /**
     * 获取当前登录用户ID
     */
    protected function getUserId(): ?int
    {
        $token = trim((string) $this->input('token', ''));
        if (empty($token)) {
            // 从 Header 获取 Authorization Bearer
            $auth = $this->request->header('Authorization', '');
            if (preg_match('/Bearer\s+(\S+)/', $auth, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (empty($token)) return null;
        
        $info = cache("token:{$token}");
        return is_array($info) ? (int)$info['user_id'] : null;
    }
}
