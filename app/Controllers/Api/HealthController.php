<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * /health liveness/readiness probe. Liveness (index) is cheap and always
 * 200 while the process can respond at all; readiness additionally checks
 * the database connection, so an orchestrator can distinguish "process is
 * up" from "process can actually serve traffic".
 */
final class HealthController extends BaseController
{
    public function index(Request $request): never
    {
        Response::ok([
            'status' => 'ok',
            'app'    => (string) config('app.name', 'O9'),
            'env'    => (string) config('app.env', 'production'),
            'time'   => date('c'),
        ]);
    }

    public function ready(Request $request): never
    {
        $checks = ['db' => false];
        try {
            Database::getInstance()->raw('SELECT 1');
            $checks['db'] = true;
        } catch (\Throwable) {
            // leave false — reported below
        }
        $ready = !in_array(false, $checks, true);
        Response::json(['ok' => $ready, 'data' => ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], 'error' => null], $ready ? 200 : 503);
    }
}
