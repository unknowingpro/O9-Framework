<?php
declare(strict_types=1);

namespace App\Identity\Dto;

/** A normalized verification outcome from a provider webhook or status fetch. */
final class VerificationEvent
{
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const PENDING  = 'pending';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $ref,    // provider session id
        public readonly string $status, // verified | rejected | pending
        public readonly ?string $reason = null,
        public readonly ?int $userId = null, // some providers echo the client reference back
        public readonly array $raw = [],
    ) {
    }

    public function isTerminal(): bool
    {
        return $this->status === self::VERIFIED || $this->status === self::REJECTED;
    }
}
