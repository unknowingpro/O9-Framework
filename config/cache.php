<?php
declare(strict_types=1);

return [
    // 'redis' | 'file' | 'array'. Redis transparently degrades to the file
    // store when unreachable (with a throttled log warning).
    'driver' => env('CACHE_DRIVER', 'file'),

    // Key namespace — lets several apps share one Redis without collisions.
    'prefix' => env('CACHE_PREFIX', 'o9:'),

    // TTL (seconds) used by Cache::remember() when the caller passes null.
    'default_ttl' => (int) env('CACHE_TTL', 3600),

    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'timeout'  => 1.0,
        'password' => env('REDIS_PASSWORD', ''),
        'db'       => (int) env('REDIS_DB', 0),
    ],

    // PHP session storage: 'file' (native) or 'redis' (stateless app tier).
    'session' => [
        'driver' => filter_var(env('SESSION_REDIS', 'false'), FILTER_VALIDATE_BOOL) ? 'redis' : 'file',
        'ttl'    => 1209600, // 14 days
    ],
];
