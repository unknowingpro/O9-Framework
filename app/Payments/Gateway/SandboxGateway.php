<?php
declare(strict_types=1);

namespace App\Payments\Gateway;

use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\ReceiptRequest;
use App\Payments\Dto\SubscriptionRequest;
use App\Payments\Dto\SubscriptionResult;
use App\Payments\Dto\WebhookEvent;
use App\Payments\PaymentGateway;
use App\Payments\UnsupportedOperation;
use RuntimeException;

/**
 * A fake provider that implements the full recurring surface in-process, for
 * tests and staging/demo. createSubscription "activates" immediately with a
 * fake provider id + a near-future period end; verifyWebhook accepts a
 * payload signed (HMAC-SHA256) with TEST_SECRET. No real money or network I/O.
 */
final class SandboxGateway implements PaymentGateway
{
    /** Fixed signing secret for sandbox webhook payloads (test/staging only). */
    public const TEST_SECRET = 'sandbox_webhook_secret';

    public function deposit(PaymentRequest $req): PaymentResult
    {
        return new PaymentResult(PaymentResult::SUCCEEDED, 'sandbox:' . $req->idempotencyKey);
    }

    public function payout(PaymentRequest $req): PaymentResult
    {
        $ref = $req->providerRef !== null && $req->providerRef !== '' ? $req->providerRef : 'sandbox:' . $req->idempotencyKey;
        return new PaymentResult(PaymentResult::SUCCEEDED, $ref);
    }

    public function refund(string $providerRef, ?int $cents = null, ?string $reason = null): PaymentResult
    {
        return new PaymentResult(PaymentResult::SUCCEEDED, $providerRef);
    }

    public function redeemReceipt(ReceiptRequest $req): PaymentResult
    {
        return new PaymentResult(PaymentResult::SUCCEEDED, 'sandbox:' . $req->idempotencyKey);
    }

    public function verifyWebhook(string $payload, array $headers): WebhookEvent
    {
        $given = (string) ($headers['X-Sandbox-Signature'] ?? '');
        $want  = hash_hmac('sha256', $payload, self::TEST_SECRET);
        if (!hash_equals($want, $given)) {
            throw new RuntimeException('sandbox webhook signature mismatch');
        }
        $data = json_decode($payload, true);
        $data = is_array($data) ? $data : [];
        return new WebhookEvent(
            type: (string) ($data['type'] ?? 'unknown'),
            providerRef: (string) ($data['sub'] ?? ''),
            status: 'verified',
            amountCents: isset($data['amount_cents']) ? (int) $data['amount_cents'] : null,
            raw: $data,
        );
    }

    public function verifyDeposit(string $providerRef, array $params): PaymentResult
    {
        throw new UnsupportedOperation($this->name() . ' does not support redirect deposits');
    }

    public function createSubscription(SubscriptionRequest $req): SubscriptionResult
    {
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify($req->interval === 'year' ? '+1 year' : '+1 month')
            ->format('Y-m-d H:i:s');
        return new SubscriptionResult(
            status: SubscriptionResult::ACTIVE,
            providerSubId: 'sub_sbx_' . bin2hex(random_bytes(6)),
            approvalUrl: null,
            currentPeriodEnd: $end,
            raw: ['tier' => $req->tier, 'amount_cents' => $req->amountCents],
        );
    }

    public function cancelSubscription(string $providerSubId, bool $atPeriodEnd = true): SubscriptionResult
    {
        return new SubscriptionResult(status: SubscriptionResult::CANCELED, providerSubId: $providerSubId);
    }

    public function capabilities(): array
    {
        return ['deposit', 'payout', 'refund', 'receipt', 'webhook', 'subscriptions'];
    }

    public function name(): string
    {
        return 'sandbox';
    }
}
