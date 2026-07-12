<?php
declare(strict_types=1);

namespace Tests\Subscriptions\Fixtures {

    use App\Payments\Dto\PaymentRequest;
    use App\Payments\Dto\PaymentResult;
    use App\Payments\Dto\ReceiptRequest;
    use App\Payments\Dto\SubscriptionRequest;
    use App\Payments\Dto\SubscriptionResult;
    use App\Payments\Dto\WebhookEvent;
    use App\Payments\PaymentGateway;
    use App\Payments\UnsupportedOperation;

    /** A one-shot (non-native-recurring) gateway — no 'subscriptions' capability. */
    final class OneShotGateway implements PaymentGateway
    {
        public function deposit(PaymentRequest $req): PaymentResult { return new PaymentResult(PaymentResult::SUCCEEDED, 'os:' . $req->idempotencyKey); }
        public function payout(PaymentRequest $req): PaymentResult { return new PaymentResult(PaymentResult::SUCCEEDED, 'os:' . $req->idempotencyKey); }
        public function refund(string $providerRef, ?int $cents = null, ?string $reason = null): PaymentResult { return new PaymentResult(PaymentResult::SUCCEEDED, $providerRef); }
        public function redeemReceipt(ReceiptRequest $req): PaymentResult { return new PaymentResult(PaymentResult::SUCCEEDED); }
        public function verifyWebhook(string $payload, array $headers): WebhookEvent { throw new UnsupportedOperation('no webhooks'); }
        public function verifyDeposit(string $providerRef, array $params): PaymentResult { throw new UnsupportedOperation('no verify'); }
        public function createSubscription(SubscriptionRequest $req): SubscriptionResult { throw new UnsupportedOperation('one-shot only'); }
        public function cancelSubscription(string $providerSubId, bool $atPeriodEnd = true): SubscriptionResult { throw new UnsupportedOperation('one-shot only'); }
        public function capabilities(): array { return ['deposit', 'payout', 'refund']; }
        public function name(): string { return 'one-shot'; }
    }
}

namespace Tests\Subscriptions {

use App\Core\Database;
use App\Payments\Dto\WebhookEvent;
use App\Payments\Gateway\SandboxGateway;
use App\Subscriptions\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Tests\Subscriptions\Fixtures\OneShotGateway;

final class SubscriptionServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        foreach (['user_subscriptions', 'store_webhook_events'] as $t) {
            $this->db->pdo()->exec("DROP TABLE IF EXISTS $t");
        }
        $this->db->pdo()->exec(
            'CREATE TABLE user_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER UNIQUE, tier TEXT, status TEXT,
                source TEXT, provider TEXT, provider_sub_id TEXT, billing_interval TEXT,
                price_cents INTEGER, current_period_end TEXT, scheduled_tier TEXT,
                scheduled_interval TEXT, scheduled_at TEXT, canceled_at TEXT, started_at TEXT, updated_at TEXT
            )'
        );
        $this->db->pdo()->exec(
            'CREATE TABLE store_webhook_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, event_uid TEXT, received_at TEXT,
                UNIQUE (provider, event_uid)
            )'
        );
        SubscriptionService::reset();
    }

    protected function tearDown(): void
    {
        SubscriptionService::reset();
    }

    public function testStatusForWithNoSubscriptionIsBasicNone(): void
    {
        $status = (new SubscriptionService(new SandboxGateway()))->statusFor(1);
        $this->assertSame('basic', $status['tier']);
        $this->assertSame('none', $status['status']);
        $this->assertFalse($status['active']);
    }

    public function testSubscribeRejectsUnpaidTierOrInvalidInterval(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $this->expectException(\RuntimeException::class);
        $svc->subscribe(1, 'basic', 'month'); // basic is not a paid tier
    }

    public function testSubscribeWithNativeRecurringGatewayActivatesImmediately(): void
    {
        $svc = new SubscriptionService(new SandboxGateway()); // capabilities() includes 'subscriptions'
        $row = $svc->subscribe(1, 'pro', 'month');
        $this->assertSame('active', $row['status']);
        $this->assertSame('pro', $row['tier']);
        $this->assertSame('sandbox', $row['provider']);
        $this->assertNotNull($row['provider_sub_id']);
    }

    public function testSubscribeIsIdempotentForAnAlreadyActiveMatchingSubscription(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $first = $svc->subscribe(1, 'pro', 'month');
        $second = $svc->subscribe(1, 'pro', 'month');
        $this->assertSame($first['provider_sub_id'], $second['provider_sub_id']);
    }

    public function testSubscribeWithOneShotGatewayChargesViaHook(): void
    {
        $charged = null;
        SubscriptionService::chargeUsing(function (int $userId, int $cents, string $note) use (&$charged): void {
            $charged = [$userId, $cents, $note];
        });
        $svc = new SubscriptionService(new OneShotGateway());
        $row = $svc->subscribe(7, 'pro', 'month');
        $this->assertSame('active', $row['status']);
        $this->assertSame([7, 999, 'pro/month'], $charged);
    }

    public function testSubscribeWithoutChargeHookStillActivates(): void
    {
        // No charge hook registered — subscribe() must not throw; the app is
        // responsible for its own billing side effects.
        $svc = new SubscriptionService(new OneShotGateway());
        $row = $svc->subscribe(9, 'pro', 'month');
        $this->assertSame('active', $row['status']);
    }

    public function testCancelSchedulesLapseAtPeriodEnd(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $svc->subscribe(1, 'pro', 'month');
        $svc->cancel(1);
        $status = $svc->statusFor(1);
        $this->assertSame('canceled', $status['status']);
        $this->assertSame('basic', $status['scheduled_tier']);
    }

    public function testCancelOnNoSubscriptionIsANoOp(): void
    {
        (new SubscriptionService(new SandboxGateway()))->cancel(999);
        $this->addToAssertionCount(1);
    }

    public function testActivateExternalSetsIapSourceWithoutCharging(): void
    {
        $charged = false;
        SubscriptionService::chargeUsing(function () use (&$charged): void { $charged = true; });
        $svc = new SubscriptionService(new OneShotGateway());
        $row = $svc->activateExternal(3, 'pro', 'month', 'apple', 'sub_apple_1');
        $this->assertSame('iap', $row['source']);
        $this->assertSame('active', $row['status']);
        $this->assertFalse($charged); // store already collected payment
    }

    public function testRenewDueLapsesPastDueSubscriptionsPastGrace(): void
    {
        $svc = new SubscriptionService(new OneShotGateway());
        $this->db->raw(
            "INSERT INTO user_subscriptions (user_id, tier, status, billing_interval, price_cents, current_period_end, started_at, updated_at)
             VALUES (1, 'pro', 'past_due', 'month', 999, ?, ?, ?)",
            [gmdate('Y-m-d H:i:s', time() - 10 * 86400), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        $n = $svc->renewDue();
        $this->assertSame(1, $n);
        $this->assertSame('basic', $svc->statusFor(1)['tier']);
    }

    public function testRenewDueRenewsActiveSubscriptionOnSuccessfulCharge(): void
    {
        SubscriptionService::chargeUsing(function (): void {}); // charge succeeds silently
        $svc = new SubscriptionService(new OneShotGateway());
        $this->db->raw(
            "INSERT INTO user_subscriptions (user_id, tier, status, billing_interval, price_cents, current_period_end, started_at, updated_at)
             VALUES (1, 'pro', 'active', 'month', 999, ?, ?, ?)",
            [gmdate('Y-m-d H:i:s', time() - 1), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        $svc->renewDue();
        $status = $svc->statusFor(1);
        $this->assertSame('active', $status['status']);
        $this->assertTrue($status['active']);
    }

    public function testRenewDueMarksPastDueOnFailedCharge(): void
    {
        SubscriptionService::chargeUsing(function (): void {
            throw new \RuntimeException('insufficient funds');
        });
        $svc = new SubscriptionService(new OneShotGateway());
        $this->db->raw(
            "INSERT INTO user_subscriptions (user_id, tier, status, billing_interval, price_cents, current_period_end, started_at, updated_at)
             VALUES (1, 'pro', 'active', 'month', 999, ?, ?, ?)",
            [gmdate('Y-m-d H:i:s', time() - 1), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        $svc->renewDue();
        $this->assertSame('past_due', $svc->statusFor(1)['status']);
    }

    private function providerSubId(int $userId): string
    {
        $row = $this->db->raw('SELECT provider_sub_id FROM user_subscriptions WHERE user_id = ?', [$userId])->fetch();
        return (string) $row['provider_sub_id'];
    }

    public function testHandleWebhookEventAppliesInvoicePaid(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $svc->subscribe(1, 'pro', 'month');
        $subId = $this->providerSubId(1);
        $this->assertNotSame('', $subId);

        $svc->handleWebhookEvent(new WebhookEvent('invoice.paid', $subId, 'verified'));
        $this->assertSame('active', $svc->statusFor(1)['status']);
    }

    public function testHandleWebhookEventPaymentFailedNotifiesAndMarksPastDue(): void
    {
        $notified = null;
        SubscriptionService::notifyUsing(function (int $userId, string $type, array $meta) use (&$notified): void {
            $notified = [$userId, $type];
        });
        $svc = new SubscriptionService(new SandboxGateway());
        $svc->subscribe(1, 'pro', 'month');
        $subId = $this->providerSubId(1);

        $svc->handleWebhookEvent(new WebhookEvent('payment_failed', $subId, 'verified'));
        $this->assertSame('past_due', $svc->statusFor(1)['status']);
        $this->assertSame([1, 'subscription_payment_failed'], $notified);
    }

    public function testHandleWebhookEventUnknownSubIsANoOp(): void
    {
        (new SubscriptionService(new SandboxGateway()))->handleWebhookEvent(new WebhookEvent('invoice.paid', 'no-such-sub', 'verified'));
        $this->addToAssertionCount(1);
    }

    public function testProviderSubOwnerResolvesOwningUser(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $svc->subscribe(5, 'pro', 'month');
        $subId = $this->providerSubId(5);
        $this->assertSame(5, $svc->providerSubOwner($subId));
        $this->assertNull($svc->providerSubOwner('no-such-ref'));
        $this->assertNull($svc->providerSubOwner(''));
    }
}

}
