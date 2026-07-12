<?php
declare(strict_types=1);

namespace App\Subscriptions;

/** Reads config/subscriptions.php: prices, interval lengths, validation. */
final class SubscriptionPlan
{
    private const DAYS = ['month' => 30, 'year' => 365];

    public static function priceCents(string $tier, string $interval, ?string $currency = null): int
    {
        $currency = $currency ?? (string) config('currencies.base', 'USD');
        $prices = (array) config('subscriptions.prices', []);
        return (int) ($prices[$tier][$interval][$currency] ?? 0);
    }

    public static function intervalDays(string $interval): int
    {
        return self::DAYS[$interval] ?? 30;
    }

    public static function isValidInterval(string $interval): bool
    {
        return isset(self::DAYS[$interval]);
    }

    /** A tier that costs money in the base currency (non-zero at any interval). */
    public static function isPaidTier(string $tier): bool
    {
        $base = (string) config('currencies.base', 'USD');
        return self::priceCents($tier, 'month', $base) > 0 || self::priceCents($tier, 'year', $base) > 0;
    }

    public static function graceDays(): int
    {
        return (int) config('subscriptions.grace_days', 3);
    }
}
