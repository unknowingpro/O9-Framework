<?php
declare(strict_types=1);

return [
    // Days a past_due subscription is retried before lapsing to 'basic'.
    'grace_days' => (int) env('SUBSCRIPTIONS_GRACE_DAYS', 3),

    // tier => interval => currency => price in the smallest currency unit (cents).
    'prices' => [
        'pro' => ['month' => ['USD' => 999], 'year' => ['USD' => 9999]],
    ],
];
