<?php
declare(strict_types=1);

namespace App\Identity\Provider;

use App\Identity\Dto\VerificationEvent;
use App\Identity\Dto\VerificationSession;
use App\Identity\IdentityVerificationProvider;
use App\Identity\UnsupportedOperation;

/**
 * The built-in, always-available provider: identity is proven by uploading
 * documents in-app, which an admin reviews out of band. There is no external
 * session or webhook — start just routes the user to the upload form.
 */
final class ManualProvider implements IdentityVerificationProvider
{
    public function createSession(int $userId, string $returnUrl): VerificationSession
    {
        return new VerificationSession('manual', '', 'manual', url('/profile/kyc'));
    }

    public function verifyWebhook(string $payload, array $headers): VerificationEvent
    {
        throw new UnsupportedOperation('manual verification has no webhook');
    }

    public function fetchStatus(string $ref): VerificationEvent
    {
        throw new UnsupportedOperation('manual verification has no remote status');
    }

    public function capabilities(): array
    {
        return ['manual'];
    }

    public function name(): string
    {
        return 'manual';
    }
}
