<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\ReceiptRequest;
use App\Payments\Dto\SubscriptionRequest;
use App\Payments\Gateway\SandboxGateway;
use App\Payments\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class SandboxGatewayTest extends TestCase
{
    private SandboxGateway $gw;

    protected function setUp(): void
    {
        $this->gw = new SandboxGateway();
    }

    public function testNameAndCapabilities(): void
    {
        $this->assertSame('sandbox', $this->gw->name());
        $this->assertSame(['deposit', 'payout', 'refund', 'receipt', 'webhook', 'subscriptions'], $this->gw->capabilities());
    }

    public function testDepositSucceeds(): void
    {
        $r = $this->gw->deposit(new PaymentRequest(1, 500, 'idem-1'));
        $this->assertTrue($r->ok());
        $this->assertSame('sandbox:idem-1', $r->providerRef);
    }

    public function testPayoutUsesProviderRefWhenGiven(): void
    {
        $r = $this->gw->payout(new PaymentRequest(1, 500, 'idem-2', providerRef: 'manual-ref'));
        $this->assertSame('manual-ref', $r->providerRef);
    }

    public function testRefundAndReceiptSucceed(): void
    {
        $this->assertTrue($this->gw->refund('ref-1')->ok());
        $this->assertTrue($this->gw->redeemReceipt(new ReceiptRequest(1, 'ios', 'tok', 'p1', 'idem-3'))->ok());
    }

    public function testVerifyWebhookAcceptsCorrectlySignedPayload(): void
    {
        $payload = json_encode(['type' => 'invoice.paid', 'sub' => 'sub_1', 'amount_cents' => 500]);
        $sig = hash_hmac('sha256', $payload, SandboxGateway::TEST_SECRET);
        $event = $this->gw->verifyWebhook($payload, ['X-Sandbox-Signature' => $sig]);
        $this->assertSame('invoice.paid', $event->type);
        $this->assertSame('sub_1', $event->providerRef);
        $this->assertSame(500, $event->amountCents);
    }

    public function testVerifyWebhookRejectsBadSignature(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->gw->verifyWebhook('{}', ['X-Sandbox-Signature' => 'wrong']);
    }

    public function testVerifyDepositIsUnsupported(): void
    {
        $this->expectException(UnsupportedOperation::class);
        $this->gw->verifyDeposit('ref', []);
    }

    public function testCreateSubscriptionActivatesWithAPeriodEnd(): void
    {
        $res = $this->gw->createSubscription(new SubscriptionRequest(1, 'pro', 999, 'idem-4', interval: 'year'));
        $this->assertTrue($res->active());
        $this->assertStringStartsWith('sub_sbx_', $res->providerSubId);
        $this->assertNotNull($res->currentPeriodEnd);
        // ~1 year out
        $this->assertGreaterThan(time() + 360 * 86400, strtotime((string) $res->currentPeriodEnd));
    }

    public function testCancelSubscription(): void
    {
        $res = $this->gw->cancelSubscription('sub_x');
        $this->assertSame('canceled', $res->status);
        $this->assertSame('sub_x', $res->providerSubId);
    }
}
