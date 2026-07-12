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
    // Base64 32-byte key. Security\Crypto reads it via env() directly (fail-
    // closed if absent); Hashid reads it here to salt its token alphabet.
    'key' => env('APP_KEY', ''),
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

    // Path prefix that routes to routes/bot.php instead of routes/web.php.
    'bot_route_prefix' => env('BOT_ROUTE_PREFIX', '/webhook'),

    // Shared secret for Controllers\Admin\CronController's HTTP-triggered
    // schedule:run fallback. Empty (default) refuses every request.
    'cron_secret' => env('CRON_SECRET', ''),
];
