<?php
declare(strict_types=1);

namespace App\Payments;

use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\ReceiptRequest;
use App\Payments\Dto\WebhookEvent;
use App\Payments\Dto\SubscriptionRequest;
use App\Payments\Dto\SubscriptionResult;

/**
 * The payment port: external-rail money I/O only. Adapters talk to a
 * provider and return a PaymentResult — they MUST NOT mutate application
 * ledger/wallet state directly. PaymentService is the only code that bridges
 * a successful result into the app's own ledger. Adapters throw
 * UnsupportedOperation for operations they do not implement; callers can
 * check capabilities() first to branch without exceptions.
 */
interface PaymentGateway
{
    /** Money in (deposit / charge). */
    public function deposit(PaymentRequest $req): PaymentResult;

    /** Money out (payout to a seller). */
    public function payout(PaymentRequest $req): PaymentResult;

    /** Reverse a prior movement by its provider reference. */
    public function refund(string $providerRef, ?int $cents = null, ?string $reason = null): PaymentResult;

    /** Validate a client-initiated in-app-purchase receipt (Apple / Google). */
    public function redeemReceipt(ReceiptRequest $req): PaymentResult;

    /**
     * Verify + normalize an async provider webhook payload.
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(string $payload, array $headers): WebhookEvent;

    /**
     * Confirm a redirect/two-phase deposit on callback.
     *
     * @param array<string, mixed> $params
     */
    public function verifyDeposit(string $providerRef, array $params): PaymentResult;

    /** Start a recurring subscription with the provider. */
    public function createSubscription(SubscriptionRequest $req): SubscriptionResult;

    /** Cancel a provider subscription (default: at period end, not immediately). */
    public function cancelSubscription(string $providerSubId, bool $atPeriodEnd = true): SubscriptionResult;

    /** @return list<string> supported ops: 'deposit','payout','refund','receipt','webhook','verify','subscriptions'. */
    public function capabilities(): array;

    /** Stable provider key, e.g. 'sandbox', 'stripe'. */
    public function name(): string;
}
