<?php
declare(strict_types=1);

namespace App\Identity\Dto;

/**
 * The result of starting a verification. `mode` is 'manual' (use the in-app
 * upload form) or 'redirect' (send the user to $redirectUrl, a provider-hosted
 * flow). `ref` is the provider's session id, correlated later by the webhook.
 */
final class VerificationSession
{
    public function __construct(
        public readonly string $provider,
        public readonly string $ref,
        public readonly string $mode, // 'manual' | 'redirect'
        public readonly ?string $redirectUrl = null,
    ) {
    }
}
