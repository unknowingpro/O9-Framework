<?php
declare(strict_types=1);

/**
 * Web (server-rendered) routes.
 *
 * @var \App\Core\Router $router
 */

use App\Controllers\Admin\AdminWebController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Web\PageController;
use App\Middleware\VerifyCsrf;

// ── Public pages ─────────────────────────────────────────────────────────
$router->get('/', [PageController::class, 'home'], [VerifyCsrf::class]);
$router->get('/about', [PageController::class, 'about'], [VerifyCsrf::class]);

// ── Admin panel ──────────────────────────────────────────────────────────
$router->group('/admin', ['Auth:admin', VerifyCsrf::class], function ($router): void {
    $router->get('/', [AdminWebController::class, 'dashboard']);
    // {path} matches one segment (Router params don't span '/') — nested
    // storage paths need either a flat naming scheme or a custom route per
    // depth; this sample keeps it to one segment.
    $router->get('/media/{path}', [MediaController::class, 'show']);
});
