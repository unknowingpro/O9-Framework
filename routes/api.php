<?php
declare(strict_types=1);

/**
 * JSON API routes (spec: /api/v1 namespaces, enveloped responses).
 *
 * @var \App\Core\Router $router
 */

use App\Core\Response;

// Placeholder until the sample Api\HealthController lands with the app layer.
$router->get('/api/health', function (): never {
    Response::ok([
        'status' => 'ok',
        'app'    => (string) config('app.name', 'O9'),
        'time'   => date('c'),
    ]);
});
