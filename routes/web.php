<?php
declare(strict_types=1);

/**
 * Web (server-rendered) routes. $router is in scope when required by the
 * kernel; returning a closure that receives it is also supported.
 *
 * @var \App\Core\Router $router
 */

use App\Core\Response;

// Placeholder until the sample PageController lands with the app layer.
$router->get('/', function (): never {
    Response::html('<!doctype html><meta charset="utf-8"><title>O9</title>'
        . '<div style="font-family:system-ui,sans-serif;padding:48px;text-align:center">'
        . '<h1>O9 Framework</h1><p>It runs. Routes live in <code>routes/</code>.</p></div>');
});
