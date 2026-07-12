<?php
declare(strict_types=1);

return [
    // The currency SubscriptionPlan/PaymentRequest fall back to when no
    // explicit currency is given.
    'base' => env('CURRENCY_BASE', 'USD'),

    /*
     * The currency registry Core\Money works from. Money is ALWAYS stored as an
     * integer number of minor units, and 'minor' is that currency's decimal
     * exponent — how many decimal places it has, i.e. 10 ** minor units make one
     * major unit.
     *
     *   USD  minor 2 → 1234    = $12.34
     *   IRR  minor 0 → 50000   = 50,000 rial  (not subdivided in practice)
     *   USDT minor 6 → 1500000 = 1.5 USDT     (micro-units, per the ERC-20 contract)
     *
     * This is the only place that knows an exponent, so no call site hard-codes
     * `/100` — which silently mis-scales every zero-decimal and three-decimal
     * currency by 100x. Money::assertSupported() throws on anything not listed,
     * so an unconfigured currency fails loudly at the boundary instead of
     * quietly corrupting an amount.
     */
    'supported' => [
        'USD'  => ['minor' => 2, 'name' => 'US Dollar'],
        'EUR'  => ['minor' => 2, 'name' => 'Euro'],
        'GBP'  => ['minor' => 2, 'name' => 'Pound Sterling'],
        'AED'  => ['minor' => 2, 'name' => 'UAE Dirham'],
        'TRY'  => ['minor' => 2, 'name' => 'Turkish Lira'],
        'IRR'  => ['minor' => 0, 'name' => 'Iranian Rial'],
        'IRT'  => ['minor' => 0, 'name' => 'Iranian Toman'],
        'USDT' => ['minor' => 6, 'name' => 'Tether'],
    ],
];
