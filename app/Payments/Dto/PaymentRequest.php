<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/**
 * Immutable money-movement request handed to a PaymentGateway adapter and to
 * PaymentService. `kind` is the caller-defined ledger-movement category the
 * result should be recorded under. `providerRef` carries an externally
 * supplied reference (e.g. an admin-entered payout reference).
 */
final class PaymentRequest
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $userId,
        public readonly int $amountCents,
        public readonly string $idempotencyKey,
        public readonly string $currency = 'USD',
        public readonly string $kind = 'topup',
        public readonly ?string $refType = null,
        public readonly ?int $refId = null,
        public readonly ?string $note = null,
        public readonly ?string $providerRef = null,
        public readonly array $meta = [],
    ) {
    }
}
