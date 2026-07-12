<?php
declare(strict_types=1);

namespace App\Payments\Dto;

/**
 * Immutable normalized webhook event produced by a PaymentGateway adapter's
 * verifyWebhook(). `providerRef` is the correlation key (usually the
 * provider subscription id). `providerSubId` is the provider's canonical
 * subscription id when an event reveals one distinct from `providerRef`
 * (some checkout flows expose a session id first, then the real
 * subscription id) — the handler re-keys the stored subscription to it.
 * Null for providers that emit the canonical id from the start.
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $type,
        public readonly string $providerRef,
        public readonly string $status,
        public readonly ?int $amountCents = null,
        public readonly array $raw = [],
        public readonly ?string $providerSubId = null,
    ) {
    }
}
