<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;

/** Auth-gated admin API sample — register behind ['Auth:admin']. */
final class AdminApiController extends BaseController
{
    public function whoami(Request $request): never
    {
        Response::ok(['user' => $this->user()]);
    }
}
