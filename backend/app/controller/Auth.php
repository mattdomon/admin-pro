<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;
use app\model\AdminUser;

/**
 * 认证控制器
 *
 * POST /api/auth/login   — 登录，返回 Token
 * POST /api/auth/logout  — 退出
 * GET  /api/auth/info    — 获取当前用户信息
 */
class Auth extends BaseController
{
    /**
     * 登录
     *
     * 返回格式���
     * {
     *   "token": "xxx",
     *   "user": { "id": 1, "username": "admin", "nickname": "管理员" }
     * }
     */
    public function login()
    {
        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password');

        if (empty($username) || empty($password)) {
            return $this->json(400, '用户名和密码不能为空');
        }

        // 从数据库查找用户
        $user = AdminUser::where('username', $username)
                         ->where('status', 1)
                         ->find();

        if (!$user || !$user->checkPassword($password)) {
            return $this->json(401, '用户名或密码错误');
        }

        // 生成 Token：payload = user_id + timestamp + secret
        $secret = env('JWT_SECRET', 'openclaw_secret');
        $token  = hash('sha256', $user->id . $username . time() . $secret);

        // 将 Token 写入 session 缓存（简单实现，生产可用 Redis）
        cache("token:{$token}", [
            'user_id'  => $user->id,
            'username' => $user->username,
        ], 86400); // 24小时

        return $this->json(200, '登录成功', [
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname ?: $user->username,
                'avatar'   => '',
            ],
        ]);
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        $token = $this->getToken();
        if ($token) {
            cache("token:{$token}", null); // 清除缓存
        }
        return $this->json(200, '退出成功');
    }

    /**
     * 获取当前用户信息
     */
    public function info()
    {
        $userInfo = $this->getCurrentUser();
        if (!$userInfo) {
            return $this->json(401, '未登录或 Token 已过期');
        }

        $user = AdminUser::find($userInfo['user_id']);
        if (!$user) {
            return $this->json(401, '用户不存在');
        }

        return $this->json(200, '获取成功', [
            'id'          => $user->id,
            'username'    => $user->username,
            'nickname'    => $user->nickname ?: $user->username,
            'roles'       => ['admin'],
            'permissions' => ['*'],
        ]);
    }

    // ─────────────────────────────────────────
    // 工具方法
    // ─────────────────────────────────────────

    /**
     * 从请求头获取 Token
     */
    protected function getToken(): string
    {
        $auth = request()->header('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return (string) $this->input('token', '');
    }

    /**
     * 获取当前登录用户信息（从缓存）
     * 返回 ['user_id' => int, 'username' => string] 或 null
     */
    public function getCurrentUser(): ?array
    {
        $token = $this->getToken();
        if (empty($token)) return null;

        $info = cache("token:{$token}");
        return is_array($info) ? $info : null;
    }
}
