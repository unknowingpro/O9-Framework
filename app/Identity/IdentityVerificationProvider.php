<?php
declare(strict_types=1);

namespace App\Identity;

use App\Identity\Dto\VerificationEvent;
use App\Identity\Dto\VerificationSession;

/**
 * The identity-verification port (parallel to App\Payments\PaymentGateway).
 * Adapters talk to a KYC provider (or, for ManualProvider, route to an in-app
 * admin-review flow) and return normalized DTOs — they MUST NOT mutate any
 * user state directly. IdentityVerificationService is the only code that
 * bridges a verified result onto the app. Adapters throw UnsupportedOperation
 * for ops they don't implement; callers can check capabilities() first.
 */
interface IdentityVerificationProvider
{
    /** Begin a verification for $userId; returns a manual or redirect session. */
    public function createSession(int $userId, string $returnUrl): VerificationSession;

    /**
     * Verify + normalize an async provider webhook (signature-checked inside).
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(string $payload, array $headers): VerificationEvent;

    /** Fetch the current decision for a session (confirm on redirect return). */
    public function fetchStatus(string $ref): VerificationEvent;

    /** @return list<string> supported ops: 'manual', 'redirect', 'webhook'. */
    public function capabilities(): array;

    /** Stable provider key, e.g. 'manual', 'stripe_identity', 'generic'. */
    public function name(): string;
}
