<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/** Immutable result returned by a PaymentGateway adapter. */
final class PaymentResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';
    public const PENDING   = 'pending';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerRef = null,
        public readonly array $raw = [],
        public readonly ?string $approvalUrl = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
