<?php
declare(strict_types=1);

return [
    // 'log' (audit-log only, no delivery attempt), 'mail' (PHP mail()),
    // 'smtp', or 'mailgun'.
    'driver' => env('MAIL_DRIVER', 'log'),

    'from_address' => env('MAIL_FROM_ADDRESS', ''),
    'from_name'    => env('MAIL_FROM_NAME', ''),

    'smtp' => [
        'host'       => env('SMTP_HOST', ''),
        'port'       => (int) env('SMTP_PORT', 587),
        'encryption' => env('SMTP_ENCRYPTION', 'tls'), // 'tls' | 'ssl' | 'none'
        'username'   => env('SMTP_USERNAME', ''),
        'password'   => env('SMTP_PASSWORD', ''),
    ],

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN', ''),
        'secret'   => env('MAILGUN_SECRET', ''),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],
];
