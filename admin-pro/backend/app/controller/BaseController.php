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
}
