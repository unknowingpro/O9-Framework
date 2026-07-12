<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Builds an OpenAPI 3 document by introspecting the Router's registered
 * routes. The hand-rolled router has no per-operation annotations, so this
 * produces an accurate skeleton — every path + method, path params, bearer
 * security for Auth-gated routes, tags by resource, and the shared
 * {ok,data,error,meta} envelope / error-code / pagination components — that
 * can be hand-enriched (subclass and override operation() to add summaries,
 * request bodies, or extra query params per endpoint).
 */
class OpenApiGenerator
{
    /** @return array<string, mixed> */
    public function generate(Router $router): array
    {
        $paths = [];
        foreach ($router->routes() as $route) {
            $path = $route['pattern'];
            if (!str_starts_with($path, '/api/')) {
                continue; // document the JSON API only
            }
            $method = strtolower($route['method']);
            $paths[$path][$method] = $this->operation($path, $route);
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title'   => (string) config('app.name', 'API') . ' API',
                'version' => (string) config('app.version', '1'),
            ],
            'servers' => [['url' => rtrim((string) config('app.url', ''), '/') . '/api/v1']],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
                'schemas' => $this->schemas(),
            ],
            'paths' => $paths,
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    protected function operation(string $path, array $route): array
    {
        $op = [
            'operationId' => $this->operationId($route['method'], $path),
            'tags'        => [$this->tag($path)],
            'responses'   => [
                '200'     => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                'default' => ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ];
        $params = $this->pathParams($path);
        if ($params !== []) {
            $op['parameters'] = $params;
        }
        if ($this->isAuthed(array_values((array) $route['middleware']))) {
            $op['security'] = [['bearerAuth' => []]];
        }
        return $op;
    }

    /**
     * Route middleware is usually registered as a class-string or
     * 'ShortName:arg' string (see Router), so those forms are what's
     * detected here. An Auth middleware passed as a pre-built instance
     * isn't recognized by this heuristic — override isAuthed() if your app
     * relies on that pattern.
     *
     * @param list<mixed> $middleware
     */
    protected function isAuthed(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && ($m === 'Auth' || str_starts_with($m, 'Auth:') || $m === 'App\\Middleware\\Auth')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    protected function pathParams(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $m);
        return array_map(static fn (string $name): array => [
            'name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'],
        ], $m[1]);
    }

    protected function tag(string $path): string
    {
        // /api/v1/{tag}/... -> the resource segment.
        $parts = array_values(array_filter(explode('/', $path)));
        return $parts[2] ?? 'misc'; // [api, v1, <tag>, ...]
    }

    protected function operationId(string $method, string $path): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', trim(str_replace('/api/v1', '', $path), '/'));
        return strtolower($method) . '_' . trim((string) $slug, '_');
    }

    /** @return array<string, mixed> */
    protected function schemas(): array
    {
        return [
            'Envelope' => [
                'type' => 'object',
                'properties' => [
                    'ok'    => ['type' => 'boolean'],
                    'data'  => ['nullable' => true],
                    'error' => ['nullable' => true, '$ref' => '#/components/schemas/ErrorBody'],
                    'meta'  => ['type' => 'object', 'properties' => ['pagination' => ['$ref' => '#/components/schemas/Pagination']]],
                ],
                'required' => ['ok', 'data', 'error'],
            ],
            'Error' => [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean', 'enum' => [false]], 'data' => ['nullable' => true], 'error' => ['$ref' => '#/components/schemas/ErrorBody']],
            ],
            'ErrorBody' => [
                'type' => 'object',
                'properties' => [
                    'code'    => ['type' => 'string', 'enum' => ApiError::codes()],
                    'message' => ['type' => 'string'],
                    'details' => ['type' => 'object', 'nullable' => true],
                ],
            ],
            'Pagination' => [
                'type' => 'object',
                'properties' => [
                    'page'     => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'count'    => ['type' => 'integer'],
                    'has_more' => ['type' => 'boolean'],
                    'total'    => ['type' => 'integer', 'nullable' => true],
                    'pages'    => ['type' => 'integer', 'nullable' => true],
                ],
            ],
        ];
    }
}
