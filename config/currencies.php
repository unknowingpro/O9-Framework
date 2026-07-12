<?php
declare(strict_types=1);

return [
    // The currency SubscriptionPlan/PaymentRequest fall back to when no
    // explicit currency is given.
    'base' => env('CURRENCY_BASE', 'USD'),
];
