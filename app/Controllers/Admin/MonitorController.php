<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\Metrics;
use App\Core\Request;
use App\Core\Response;

/** Admin metrics/events — register behind ['Auth:admin']. */
final class MonitorController extends BaseController
{
    /** Prometheus text-exposition endpoint. */
    public function metrics(Request $request): never
    {
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo Metrics::render(Metrics::collect());
        exit;
    }

    /** JSON dashboard summary — a View component demo (admin/dashboard.php reads this shape). */
    public function summary(Request $request): never
    {
        Response::ok(['samples' => Metrics::collect()]);
    }
}
