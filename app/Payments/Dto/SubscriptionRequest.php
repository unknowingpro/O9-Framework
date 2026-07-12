<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/**
 * Immutable request to start a recurring subscription with a provider.
 * `interval` is the billing cadence ('month' | 'year'). `returnUrl` is used
 * by redirect-flow providers to come back after approval.
 */
final class SubscriptionRequest
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $userId,
        public readonly string $tier,
        public readonly int $amountCents,
        public readonly string $idempotencyKey,
        public readonly string $currency = 'USD',
        public readonly string $interval = 'month',
        public readonly ?string $returnUrl = null,
        public readonly array $meta = [],
    ) {
    }
}
