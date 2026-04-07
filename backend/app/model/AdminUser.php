<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 后台用户模型
 */
class AdminUser extends Model
{
    protected $name = 'admin_users';
    protected $pk   = 'id';
    protected $autoWriteTimestamp = false;

    protected $hidden = ['password'];

    /**
     * 验证密码
     */
    public function checkPassword(string $password): bool
    {
        $salt = env('SALT', 'openclaw_salt');
        return $this->password === hash('sha256', $password . $salt);
    }
}
