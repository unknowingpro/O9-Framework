<?php
declare(strict_types=1);

namespace App\Payments;

use App\Payments\Gateway\SandboxGateway;
use RuntimeException;

/**
 * Builds the active PaymentGateway adapter. Only 'sandbox' ships in core —
 * real providers (Stripe, PayPal, regional gateways) are app-specific and
 * register themselves via extend(), the same driver/factory/fallback
 * pattern used throughout the framework (see Cache, Storage, Identity).
 */
final class PaymentGatewayFactory
{
    /** @var array<string, callable(): PaymentGateway> */
    private static array $custom = [];

    public static function make(?string $name = null): PaymentGateway
    {
        $name = $name ?? self::active();
        if (isset(self::$custom[$name])) {
            return (self::$custom[$name])();
        }
        return match ($name) {
            'sandbox' => new SandboxGateway(),
            default   => throw new RuntimeException('unknown or unavailable payment gateway: ' . $name),
        };
    }

    /** Register an additional gateway by name. @param callable(): PaymentGateway $maker */
    public static function extend(string $name, callable $maker): void
    {
        self::$custom[$name] = $maker;
    }

    /** The configured active gateway — config('payments.gateway'), default 'sandbox'. */
    public static function active(): string
    {
        $name = (string) config('payments.gateway', 'sandbox');
        return $name !== '' ? $name : 'sandbox';
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$custom = [];
    }
}
