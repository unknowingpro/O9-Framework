<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny path-prefix router with route groups and per-group middleware.
 * Supports parameterised paths via {name} placeholders.
 *
 * Handler forms (all receive ($request, $params)):
 *   - [Controller::class, 'method']
 *   - Controller::class                       → __invoke()
 *   - 'Admin\FooController@method'            → App\Controllers\Admin\FooController::method()
 *   - any callable / closure
 *
 * Not-found and method-mismatch outcomes are THROWN as HttpResponse so the
 * kernel renders them uniformly (and tests can assert them).
 */
final class Router
{
    /** @var array<int, array{method:string,pattern:string,regex:?string,handler:array<mixed>|callable|string,middleware:array<int,mixed>}> */
    private array $routes = [];
    /** @var array<int, mixed> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function get(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function post(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function put(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function delete(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function patch(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function head(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('HEAD', $path, $handler, $middleware);
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $middleware
     */
    public function options(string $path, array|callable|string $handler, array $middleware = []): void
    {
        $this->add('OPTIONS', $path, $handler, $middleware);
    }

    /** @param array<int,mixed> $middleware */
    public function group(string $prefix, array $middleware, callable $register): void
    {
        $prevPrefix = $this->groupPrefix;
        $prevMw     = $this->groupMiddleware;
        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = array_merge($prevMw, $middleware);
        $register($this);
        $this->groupPrefix = $prevPrefix;
        $this->groupMiddleware = $prevMw;
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<int, mixed>            $mw
     */
    private function add(string $method, string $path, array|callable|string $handler, array $mw): void
    {
        $pattern = '/' . trim($this->groupPrefix . $path, '/');
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            // Precompute once at registration instead of rebuilding the regex on
            // every request: static routes (no {param}) get regex=null and match by
            // string compare; dynamic ones carry a ready-to-use named-group regex.
            'regex'      => str_contains($pattern, '{')
                ? '#^' . preg_replace_callback(
                    '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
                    static fn(array $m): string => '(?P<' . $m[1] . '>[^/]+)',
                    $pattern,
                ) . '$#'
                : null,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $mw),
        ];
    }

    /**
     * The registered routes (for introspection / OpenAPI).
     *
     * @return array<int, array{method:string,pattern:string,regex:?string,handler:array<mixed>|callable|string,middleware:array<int,mixed>}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): void
    {
        $path   = $request->path();
        $method = $request->method();
        // A HEAD request is served by the matching GET route when no explicit
        // HEAD route exists (RFC 7231 §4.3.2) — so health checks and `curl -I`
        // get 200, not 405. An explicit HEAD route still wins because it is
        // matched directly before this fallback is ever considered.
        $fallbackMethod = $method === 'HEAD' ? 'GET' : null;

        $methodMismatch = false;
        $fallback = null;

        foreach ($this->routes as $r) {
            $params = [];
            if ($r['regex'] === null) {
                // Static route → plain string compare, no regex work.
                if ($r['pattern'] !== $path) {
                    continue;
                }
            } elseif (!$this->matches($r['regex'], $path, $params)) {
                continue;
            }
            if ($r['method'] !== $method) {
                if ($fallbackMethod !== null && $r['method'] === $fallbackMethod && $fallback === null) {
                    $fallback = ['route' => $r, 'params' => $params]; // remember but keep scanning for an exact HEAD route
                }
                $methodMismatch = true;
                continue;
            }

            $request->setParams($params);

            foreach ($r['middleware'] as $mw) {
                $this->runMiddleware($mw, $request);
            }
            $this->invoke($r['handler'], $request, $params);
            return;
        }

        // No exact match — serve the remembered GET route for a HEAD request.
        if ($fallback !== null) {
            $request->setParams($fallback['params']);
            foreach ($fallback['route']['middleware'] as $mw) {
                $this->runMiddleware($mw, $request);
            }
            $this->invoke($fallback['route']['handler'], $request, $fallback['params']);
            return;
        }

        if ($methodMismatch) {
            throw new HttpResponse(405, [
                'ok' => false, 'data' => null,
                'error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed'],
            ], ['Allow' => implode(', ', $this->allowedMethods($path))]);
        }
        throw new HttpResponse(404, $request->wantsJson()
            ? ['ok' => false, 'data' => null,
               'error' => ['code' => ApiError::NOT_FOUND, 'message' => 'Route not found: ' . $method . ' ' . $path]]
            : self::notFoundHtml());
    }

    /** Resolve + run one middleware reference (instance | class-string | 'ShortName:arg'). */
    private function runMiddleware(mixed $mw, Request $request): void
    {
        if ($mw instanceof Middleware) {
            $mw->handle($request);
            return;
        }
        if (is_string($mw)) {
            $parts = explode(':', $mw, 2);
            $name  = $parts[0];
            $arg   = $parts[1] ?? null;
            $class = class_exists($name) ? $name : 'App\\Middleware\\' . $name;
            /** @var Middleware $inst */
            $inst = new $class();
            $inst->handle($request, $arg);
            return;
        }
        throw new \InvalidArgumentException('Unresolvable middleware reference');
    }

    /**
     * HTTP methods that DO match this path (for the 405 Allow header).
     *
     * @return list<string>
     */
    private function allowedMethods(string $path): array
    {
        $out = [];
        foreach ($this->routes as $r) {
            $params = [];
            $hit = $r['regex'] === null ? $r['pattern'] === $path : $this->matches($r['regex'], $path, $params);
            if ($hit && !in_array($r['method'], $out, true)) {
                $out[] = $r['method'];
            }
        }
        // A GET route also answers HEAD (see dispatch()), so advertise it.
        if (in_array('GET', $out, true) && !in_array('HEAD', $out, true)) {
            $out[] = 'HEAD';
        }
        return $out;
    }

    /**
     * Match a path against a route's precompiled regex, capturing named params.
     *
     * @param array<string, string> $params
     */
    private function matches(string $regex, string $path, array &$params): bool
    {
        if (!preg_match($regex, $path, $matches)) {
            return false;
        }
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $params[$k] = rawurldecode($v);
            }
        }
        return true;
    }

    /**
     * @param array<mixed>|callable|string $handler
     * @param array<string, string>        $params
     */
    private function invoke(array|callable|string $handler, Request $request, array $params): void
    {
        // 'Admin\FooController@method' string dispatch. Bare (non-FQCN) class
        // names resolve under App\Controllers\.
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fqn = class_exists($class) ? $class : ('App\\Controllers\\' . $class);
            (new $fqn())->$method($request, $params);
            return;
        }
        // Invokable single-action controller: a bare class-string handler is
        // instantiated and its __invoke() is called. Lets routes read as
        //   $router->get('/files', ListFilesController::class);
        if (is_string($handler) && class_exists($handler)) {
            $controller = new $handler();
            if (!is_callable($controller)) {
                throw new \InvalidArgumentException($handler . ' has no __invoke()');
            }
            $controller($request, $params);
            return;
        }
        if (is_callable($handler)) {
            $handler($request, $params);
            return;
        }
        /** @var array{0: class-string, 1: string} $handler */
        [$class, $method] = $handler;
        $instance = new $class();
        $instance->$method($request, $params);
    }

    /** Minimal styled 404 page used when the app ships no pages/not-found view. */
    private static function notFoundHtml(): string
    {
        $tpl = defined('BASE_PATH') ? BASE_PATH . '/app/Views/pages/not-found.php' : '';
        if ($tpl !== '' && is_file($tpl) && function_exists('view')) {
            try {
                return (string) view('pages/not-found');
            } catch (\Throwable) {
                // fall through to the built-in page
            }
        }
        return '<!doctype html><meta charset="utf-8"><title>404</title>'
            . '<div style="font-family:system-ui,sans-serif;padding:48px;text-align:center">'
            . '<h1>404</h1><p>Page not found.</p><a href="/">Back to home</a></div>';
    }
}
