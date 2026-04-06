<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;

class Auth extends BaseController
{
    public function login()
    {
        $username = $this->input('username');
        $password = $this->input('password');
        
        if (empty($username) || empty($password)) {
            return $this->json(400, '用户名和密码不能为空');
        }
        
        // 简单验证
        if ($username === 'admin' && $password === 'admin123') {
            $token = md5($username . time() . env('JWT_SECRET'));
            
            return $this->json(200, '登录成功', [
                'token' => $token,
                'user' => [
                    'id' => 1,
                    'username' => $username,
                    'nickname' => '管理员',
                    'avatar' => ''
                ]
            ]);
        }
        
        return $this->json(401, '用户名或密码错误');
    }
    
    public function logout()
    {
        return $this->json(200, '退出成功');
    }
    
    public function info()
    {
        return $this->json(200, '获取成功', [
            'id' => 1,
            'username' => 'admin',
            'nickname' => '管理员',
            'roles' => ['admin'],
            'permissions' => ['*']
        ]);
    }
}
