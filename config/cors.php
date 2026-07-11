<?php
declare(strict_types=1);

return [
    // '*' for a public token-based API, or a comma-separated origin allow-list.
    'origins' => env('CORS_ORIGINS', '*'),
    'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    'headers' => 'Authorization, Content-Type, Accept, X-Requested-With',
    'max_age' => 86400,

    // Path prefixes that negotiate their own OPTIONS (e.g. tus.io capability
    // discovery) and must bypass generic CORS handling entirely.
    'skip_prefixes' => [],
];
