<?php
declare(strict_types=1);

return [
    // Active connection: 'sqlite' (zero-config default) or 'mysql'.
    'default' => env('DB_DRIVER', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', storage_path('database/o9.sqlite')),
        ],
        'mysql' => [
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'o9'),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => env('DB_CHARSET', 'utf8mb4'),
        ],
    ],

    // Numbered .sql files applied in order by MigrationsService / `console migrate`.
    'migrations_path' => env('DB_MIGRATIONS_PATH', base_path('setup/database/migrations')),
];
