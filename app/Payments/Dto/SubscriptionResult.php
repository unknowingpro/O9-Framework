<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/**
 * Immutable result of a subscription create/cancel. `approvalUrl` is set by
 * redirect-flow providers (caller must send the user there). `currentPeriodEnd`
 * is a 'Y-m-d H:i:s' UTC string when the provider reports it.
 */
final class SubscriptionResult
{
    public const ACTIVE   = 'active';
    public const PENDING  = 'pending';
    public const CANCELED = 'canceled';
    public const FAILED   = 'failed';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerSubId = null,
        public readonly ?string $approvalUrl = null,
        public readonly ?string $currentPeriodEnd = null,
        public readonly array $raw = [],
    ) {
    }

    public function active(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
