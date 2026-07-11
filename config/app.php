<?php
declare(strict_types=1);

return [
    // ── Identity ─────────────────────────────────────────────────────────
    'name'     => env('APP_NAME', 'O9'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', ''),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // ── Locales ──────────────────────────────────────────────────────────
    'default_locale'  => env('APP_DEFAULT_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    // ── Security ─────────────────────────────────────────────────────────
    'jwt' => [
        'secret' => env('JWT_SECRET', ''),
        'ttl'    => (int) env('JWT_TTL', 86400),
        'algo'   => 'HS256',
    ],
    'session_name' => env('SESSION_NAME', 'o9_session'),

    // ── Kernel gates (each optional; see Core/App boot order) ───────────
    'force_https' => (bool) env('FORCE_HTTPS', true),
    'maintenance' => (bool) env('MAINTENANCE_MODE', false),
    'geoblock'    => array_filter(explode(',', (string) env('GEOBLOCK_COUNTRIES', ''))),

    // ── Rate limits ──────────────────────────────────────────────────────
    'rate_limit' => [
        'global' => (int) env('RATE_LIMIT_GLOBAL', 240),
        'auth'   => (int) env('RATE_LIMIT_AUTH', 5),
    ],
];
