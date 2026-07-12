<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/**
 * Immutable in-app-purchase receipt validation request (Apple / Google).
 * Defined so the port shape is forward-compatible even for gateways (like
 * SandboxGateway/LedgerGateway) that don't support receipts (they throw
 * UnsupportedOperation).
 */
final class ReceiptRequest
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $userId,
        public readonly string $platform,
        public readonly string $receiptToken,
        public readonly string $productId,
        public readonly string $idempotencyKey,
        public readonly array $meta = [],
    ) {
    }
}
