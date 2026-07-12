<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\ReceiptRequest;
use App\Payments\Dto\SubscriptionRequest;
use App\Payments\Dto\SubscriptionResult;
use App\Payments\Dto\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function testPaymentResultOkReflectsStatus(): void
    {
        $this->assertTrue((new PaymentResult(PaymentResult::SUCCEEDED))->ok());
        $this->assertFalse((new PaymentResult(PaymentResult::FAILED))->ok());
        $this->assertFalse((new PaymentResult(PaymentResult::PENDING))->ok());
    }

    public function testSubscriptionResultActiveReflectsStatus(): void
    {
        $this->assertTrue((new SubscriptionResult(SubscriptionResult::ACTIVE))->active());
        $this->assertFalse((new SubscriptionResult(SubscriptionResult::CANCELED))->active());
    }

    public function testPaymentRequestDefaults(): void
    {
        $req = new PaymentRequest(userId: 1, amountCents: 500, idempotencyKey: 'k1');
        $this->assertSame('USD', $req->currency);
        $this->assertSame('topup', $req->kind);
        $this->assertNull($req->refType);
        $this->assertSame([], $req->meta);
    }

    public function testSubscriptionRequestDefaults(): void
    {
        $req = new SubscriptionRequest(userId: 1, tier: 'pro', amountCents: 999, idempotencyKey: 'k2');
        $this->assertSame('USD', $req->currency);
        $this->assertSame('month', $req->interval);
    }

    public function testWebhookEventFields(): void
    {
        $e = new WebhookEvent('invoice.paid', 'ref1', 'verified', 999, ['x' => 1], 'sub_2');
        $this->assertSame('invoice.paid', $e->type);
        $this->assertSame('sub_2', $e->providerSubId);
    }

    public function testReceiptRequestFields(): void
    {
        $r = new ReceiptRequest(1, 'ios', 'token', 'product_1', 'idem1');
        $this->assertSame('ios', $r->platform);
    }
}
