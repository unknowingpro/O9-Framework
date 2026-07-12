<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\Request;

/** Admin web panel sample — register behind ['Auth:admin']. */
final class AdminWebController extends BaseController
{
    public function dashboard(Request $request): never
    {
        $this->render('admin/dashboard', ['user' => $this->user()], 'admin/layout');
    }
}
