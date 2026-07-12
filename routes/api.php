<?php
declare(strict_types=1);

/**
 * JSON API routes. All under /api/v1/. Auth via Bearer JWT (Middleware\Auth
 * also accepts a resumed web session for browser fetch calls). Structure:
 * one versioned group with VersionGate applied to the whole group (inert
 * unless an admin enables the force-update gate), public routes first, then
 * an authenticated sub-group, then an admin sub-group scoped by role.
 *
 * @var \App\Core\Router $router
 */

use App\Controllers\Admin\AdminApiController;
use App\Controllers\Admin\CronController;
use App\Controllers\Admin\MonitorController;
use App\Controllers\Api\HealthController;
use App\Controllers\Api\PushController;
use App\Controllers\Api\SyncController;
use App\Middleware\Auth;
use App\Middleware\RateLimit;
use App\Middleware\VersionGate;

$router->group('/api/v1', [VersionGate::class], function ($router): void {

    // ── Public ───────────────────────────────────────────────────────────
    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/health/ready', [HealthController::class, 'ready']);

    // HTTP-triggered cron fallback — its own shared-secret check, not
    // session/JWT auth, so it lives outside the Auth group.
    $router->get('/cron/run', [CronController::class, 'run']);

    // ── Authenticated ────────────────────────────────────────────────────
    // A coarse global per-IP write cap backs every endpoint in this group
    // (one shared bucket via the 'api' scope); tighter per-route limits
    // (e.g. [new RateLimit(20, 60)]) stack on top for hot paths.
    $router->group('', [Auth::class, new RateLimit((int) config('app.rate_limit.global', 240), 60, 'api')], function ($router): void {
        $router->post('/push/subscribe',   [PushController::class, 'subscribe']);
        $router->post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
        $router->post('/push/test',        [PushController::class, 'test']);
        $router->get('/sync/users',        [SyncController::class, 'users']);

        // ── Admin (role-scoped) ─────────────────────────────────────────
        $router->group('/admin', ['Auth:admin'], function ($router): void {
            $router->get('/whoami',          [AdminApiController::class, 'whoami']);
            $router->get('/monitor/metrics', [MonitorController::class, 'metrics']);
            $router->get('/monitor/summary', [MonitorController::class, 'summary']);
        });
    });
});
