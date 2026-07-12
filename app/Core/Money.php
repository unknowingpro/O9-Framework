<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Currency-aware minor-unit math.
 *
 * All money is stored as an INTEGER number of minor units of its currency —
 * cents for USD/EUR, whole rial for IRR, micro-units for USDT. Floats never
 * hold money: 0.1 + 0.2 !== 0.3 in binary floating point, and that error
 * compounds through totals and refunds.
 *
 * This class is the single place that knows each currency's decimal exponent
 * (from config/currencies.php), so nothing anywhere hard-codes `/100` — a habit
 * that silently corrupts every zero-decimal currency (IRR) by 100x and every
 * six-decimal one (USDT) by 10,000x.
 */
final class Money
{
    public static function base(): string
    {
        return (string) config('currencies.base', 'USD');
    }

    /** @return array<string, array<string, mixed>> */
    private static function registry(): array
    {
        /** @var array<string, array<string, mixed>> */
        return (array) config('currencies.supported', []);
    }

    public static function isSupported(string $currency): bool
    {
        return isset(self::registry()[$currency]);
    }

    public static function assertSupported(string $currency): void
    {
        if (!self::isSupported($currency)) {
            throw new RuntimeException('unsupported currency: ' . $currency);
        }
    }

    /** @return list<string> the configured currency codes. */
    public static function supported(): array
    {
        return array_keys(self::registry());
    }

    /** The currency's decimal exponent (USD 2, IRR 0, USDT 6). */
    public static function minorExponent(string $currency): int
    {
        self::assertSupported($currency);

        return (int) (self::registry()[$currency]['minor'] ?? 2);
    }

    /** Major units (12.34 dollars) → integer minor units (1234). */
    public static function toMinor(float|string $major, string $currency): int
    {
        $factor = 10 ** self::minorExponent($currency);

        return (int) round(((float) $major) * $factor);
    }

    /** Integer minor units (1234) → major units (12.34). Display only — never accumulate in float. */
    public static function fromMinor(int $minor, string $currency): float
    {
        $factor = 10 ** self::minorExponent($currency);

        return $minor / $factor;
    }

    /** Human string with the right number of decimals: '12.34 USD', '50,000 IRR'. */
    public static function format(int $minor, string $currency): string
    {
        $exp = self::minorExponent($currency);

        return number_format(self::fromMinor($minor, $currency), $exp) . ' ' . $currency;
    }
}
