<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return $this->json(200, 'Admin Pro API', [
            'version' => '1.0.0',
            'name' => 'Admin Pro'
        ]);
    }
    
    public function hello()
    {
        $name = $this->input('name', 'World');
        return $this->json(200, 'Hello ' . $name);
    }
}
