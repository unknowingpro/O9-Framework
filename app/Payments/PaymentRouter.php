<?php
declare(strict_types=1);

namespace App\Payments;

/**
 * Selects a checkout's gateway for a given currency, via a config map
 * (config('payments.currency_provider')) with PaymentGatewayFactory::active()
 * as the fallback. Pure decision logic — returns a provider name; the
 * factory constructs the chosen adapter elsewhere.
 *
 * Country -> currency resolution and per-tier price-availability checks are
 * app-specific (they need a country/currency dataset and pricing config this
 * framework doesn't ship) — apps that need them layer that logic on top of
 * providerForCurrency().
 */
final class PaymentRouter
{
    public static function providerForCurrency(string $currency): string
    {
        $map = (array) config('payments.currency_provider', []);
        $provider = (string) ($map[$currency] ?? '');
        return $provider !== '' ? $provider : PaymentGatewayFactory::active();
    }
}
