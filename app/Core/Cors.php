<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CORS for the JSON API. Emits Access-Control-* headers on /api/* requests and
 * short-circuits the OPTIONS preflight with 204 (preflight has no matching route,
 * so it must be handled before dispatch). Token-based API → no credentials, so a
 * wildcard origin is safe; an explicit allow-list echoes the matching Origin.
 */
final class Cors
{
    /** Apply CORS to an /api/* request; returns true if the request was a handled preflight. */
    public static function handle(Request $request): bool
    {
        if (!str_starts_with($request->path(), '/api/')) {
            return false;
        }
        $cfg = (array) config('cors', []);

        // Paths that negotiate their own OPTIONS (e.g. tus.io capability
        // discovery) opt out via config('cors.skip_prefixes').
        foreach ((array) ($cfg['skip_prefixes'] ?? []) as $prefix) {
            if ($prefix !== '' && str_starts_with($request->path(), (string) $prefix)) {
                return false;
            }
        }

        $allowed = (string) ($cfg['origins'] ?? '*');
        $origin  = $request->header('origin', '');

        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '') {
            $list = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $list, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
        }
        header('Access-Control-Allow-Methods: ' . (string) ($cfg['methods'] ?? 'GET, POST, PUT, PATCH, DELETE, OPTIONS'));
        header('Access-Control-Allow-Headers: ' . (string) ($cfg['headers'] ?? 'Authorization, Content-Type, Accept, X-Requested-With'));
        header('Access-Control-Max-Age: ' . (string) ($cfg['max_age'] ?? 86400));

        if (strtoupper($request->method()) === 'OPTIONS') {
            http_response_code(204);
            return true;
        }
        return false;
    }
}
