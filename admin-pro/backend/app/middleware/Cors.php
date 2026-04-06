<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * CORS 跨域中间件
 */
class Cors
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 处理预检请求
        if ($request->method() === 'OPTIONS') {
            $response = response('', 200);
        } else {
            $response = $next($request);
        }

        // 设置 CORS 头
        $response->header([
            'Access-Control-Allow-Origin'      => $this->getAllowedOrigin($request),
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control',
            'Access-Control-Max-Age'           => '86400',
        ]);

        return $response;
    }

    /**
     * 获取允许的源
     *
     * @param Request $request
     * @return string
     */
    private function getAllowedOrigin(Request $request): string
    {
        $origin = $request->header('Origin', '');
        
        // 开发环境允许的源
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:5175',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://127.0.0.1:5175',
        ];

        // 如果请求的源在允许列表中，返回该源
        if (in_array($origin, $allowedOrigins)) {
            return $origin;
        }

        // 生产环境或默认情况，返回第一个允许的源
        return $allowedOrigins[0];
    }
}