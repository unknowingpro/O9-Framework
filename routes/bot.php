<?php
declare(strict_types=1);

/**
 * Telegram bot routes. Matched when the request path starts with
 * config('app.bot_route_prefix') (default '/webhook') — see App::loadRoutes().
 *
 * @var \App\Core\Router $router
 */

use App\Controllers\Bot\WebAppController;
use App\Controllers\Bot\WebhookController;

$router->post('/webhook/telegram', [WebhookController::class, 'receive']);
$router->post('/webhook/webapp/auth', [WebAppController::class, 'auth']);
