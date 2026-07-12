<?php
declare(strict_types=1);

namespace App\Identity;

use App\Identity\Provider\ManualProvider;
use RuntimeException;

/**
 * Builds the configured identity provider (parallel to PaymentGatewayFactory).
 * Only 'manual' ships in core — real KYC integrations (Stripe Identity, a
 * generic webhook-based provider, etc.) are app-specific and register
 * themselves via extend(), the same driver/factory/fallback pattern used
 * throughout the framework.
 */
final class IdentityProviderFactory
{
    /** @var array<string, callable(): IdentityVerificationProvider> */
    private static array $custom = [];

    public static function make(?string $name = null): IdentityVerificationProvider
    {
        $name = $name ?? self::active();
        if (isset(self::$custom[$name])) {
            return (self::$custom[$name])();
        }
        return match ($name) {
            'manual' => new ManualProvider(),
            default  => throw new RuntimeException('unknown identity provider: ' . $name),
        };
    }

    /** Register an additional provider by name. @param callable(): IdentityVerificationProvider $maker */
    public static function extend(string $name, callable $maker): void
    {
        self::$custom[$name] = $maker;
    }

    /** The configured active provider — config('identity.mode'), default 'manual'. */
    public static function active(): string
    {
        $mode = (string) config('identity.mode', 'manual');
        return $mode !== '' ? $mode : 'manual';
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$custom = [];
    }
}
