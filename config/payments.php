<?php
declare(strict_types=1);

return [
    // Active payment gateway. 'sandbox' is the only gateway that ships in
    // core; register real providers via PaymentGatewayFactory::extend().
    'gateway' => env('PAYMENTS_GATEWAY', 'sandbox'),

    // Currency => gateway name overrides for PaymentRouter::providerForCurrency().
    // Falls back to 'gateway' above when a currency has no explicit mapping.
    'currency_provider' => [],
];
