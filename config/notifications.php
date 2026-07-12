<?php
declare(strict_types=1);

return [
    'webhook' => [
        // Default URL for WebhookChannel when none is passed at construction
        // or per-call via $meta['webhook_url'].
        'default_url' => env('NOTIFICATION_WEBHOOK_URL', ''),
    ],
];
