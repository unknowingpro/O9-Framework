<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Core\Database;
use App\Payments\Dto\PaymentRequest;
use App\Payments\Gateway\SandboxGateway;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymentService;
use App\Payments\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        foreach (['payment_intents', 'store_webhook_events'] as $t) {
            $this->db->pdo()->exec("DROP TABLE IF EXISTS $t");
        }
        $this->db->pdo()->exec(
            'CREATE TABLE payment_intents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, idempotency_key TEXT UNIQUE, provider TEXT,
                provider_ref TEXT, direction TEXT, amount_cents INTEGER, currency TEXT,
                status TEXT, user_id INTEGER, wallet_tx_id INTEGER, ref_type TEXT, ref_id INTEGER,
                meta TEXT, created_at TEXT, updated_at TEXT
            )'
        );
        $this->db->pdo()->exec(
            'CREATE TABLE store_webhook_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, event_uid TEXT, received_at TEXT,
                UNIQUE (provider, event_uid)
            )'
        );
        PaymentService::reset();
        PaymentGatewayFactory::reset();
    }

    protected function tearDown(): void
    {
        PaymentService::reset();
        PaymentGatewayFactory::reset();
    }

    public function testDepositSettlesAndInvokesDepositHookWithTxId(): void
    {
        $seen = null;
        PaymentService::onDepositSettledUsing(function (PaymentRequest $req, $result) use (&$seen): int {
            $seen = [$req->userId, $req->amountCents];
            return 4242;
        });
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'dep-1'));

        $this->assertSame('succeeded', $intent['status']);
        $this->assertSame([1, 500], $seen);
        $this->assertSame(4242, $intent['wallet_tx_id']);
    }

    public function testDepositIsIdempotentOnKey(): void
    {
        $calls = 0;
        PaymentService::onDepositSettledUsing(function () use (&$calls): int {
            $calls++;
            return 1;
        });
        $svc = new PaymentService(new SandboxGateway());
        $first = $svc->deposit(new PaymentRequest(1, 500, 'dep-2'));
        $second = $svc->deposit(new PaymentRequest(1, 500, 'dep-2'));
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $calls); // never settled twice
    }

    public function testDepositWorksWithoutARegisteredHook(): void
    {
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'dep-3'));
        $this->assertSame('succeeded', $intent['status']);
        $this->assertNull($intent['wallet_tx_id']);
    }

    public function testPayoutSettlesAndInvokesPayoutHook(): void
    {
        $seen = null;
        PaymentService::onPayoutSettledUsing(function (PaymentRequest $req, $result) use (&$seen): void {
            $seen = $req->refId;
        });
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->payout(new PaymentRequest(1, 300, 'out-1', refType: 'withdrawal', refId: 55));
        $this->assertSame('succeeded', $intent['status']);
        $this->assertSame(55, $seen);
    }

    public function testConfirmDepositFromWebhookAppliesConfirmedDeposit(): void
    {
        $txId = null;
        PaymentService::onDepositSettledUsing(function () use (&$txId): int { return $txId = 99; });

        $secret = SandboxGateway::TEST_SECRET;
        // Force the intent into a pending redirect state first via a gateway that returns PENDING.
        $svc = new PaymentService(new SandboxGateway());
        // Manually seed a pending intent as if a redirect deposit was started.
        $this->db->raw(
            "INSERT INTO payment_intents (idempotency_key, provider, provider_ref, direction, amount_cents, currency, status, user_id, created_at, updated_at)
             VALUES (?, 'sandbox', 'sub_ref_1', 'in', 500, 'USD', 'pending', 1, ?, ?)",
            ['dep-webhook-1', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        $payload = json_encode(['type' => 'deposit.confirmed', 'sub' => 'sub_ref_1']);
        $sig = hash_hmac('sha256', $payload, $secret);

        $intent = $svc->confirmDepositFromWebhook('sandbox', $payload, ['X-Sandbox-Signature' => $sig]);
        $this->assertSame('succeeded', $intent['status']);
        $this->assertSame(99, $intent['wallet_tx_id']);
    }

    public function testConfirmDepositFromWebhookIsIdempotentAgainstARacingSettle(): void
    {
        $calls = 0;
        PaymentService::onDepositSettledUsing(function () use (&$calls): int { $calls++; return 1; });
        $this->db->raw(
            "INSERT INTO payment_intents (idempotency_key, provider, provider_ref, direction, amount_cents, currency, status, user_id, created_at, updated_at)
             VALUES (?, 'sandbox', 'sub_ref_2', 'in', 500, 'USD', 'pending', 1, ?, ?)",
            ['dep-webhook-2', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        $svc = new PaymentService(new SandboxGateway());
        $payload = json_encode(['type' => 'deposit.confirmed', 'sub' => 'sub_ref_2']);
        $sig = hash_hmac('sha256', $payload, SandboxGateway::TEST_SECRET);

        $svc->confirmDepositFromWebhook('sandbox', $payload, ['X-Sandbox-Signature' => $sig]);
        // A second delivery of the same event finds the intent already settled — no-op.
        $again = $svc->confirmDepositFromWebhook('sandbox', $payload, ['X-Sandbox-Signature' => $sig]);
        $this->assertSame('succeeded', $again['status']);
        $this->assertSame(1, $calls);
    }

    public function testConfirmDepositFromWebhookIgnoresNonTerminalEventTypes(): void
    {
        $svc = new PaymentService(new SandboxGateway());
        $payload = json_encode(['type' => 'something.else', 'sub' => 'sub_x']);
        $sig = hash_hmac('sha256', $payload, SandboxGateway::TEST_SECRET);
        $this->assertSame([], $svc->confirmDepositFromWebhook('sandbox', $payload, ['X-Sandbox-Signature' => $sig]));
    }

    public function testRefundDepositReversesViaHookAndMarksRefunded(): void
    {
        $reversed = null;
        PaymentService::onDepositSettledUsing(fn () => 1);
        PaymentService::onRefundReversedUsing(function (int $userId, int $cents, string $currency) use (&$reversed): void {
            $reversed = [$userId, $cents, $currency];
        });
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'dep-refund-1'));

        $refunded = $svc->refundDeposit($intent['provider_ref']);
        $this->assertSame('refunded', $refunded['status']);
        $this->assertSame([1, 500, 'USD'], $reversed);
    }

    public function testRefundDepositRespectsBalanceGuardWhenRegistered(): void
    {
        PaymentService::onDepositSettledUsing(fn () => 1);
        PaymentService::balanceUsing(fn (int $userId, string $currency): int => 0); // insufficient
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'dep-refund-2'));

        $this->expectException(\RuntimeException::class);
        $svc->refundDeposit($intent['provider_ref']);
    }

    public function testRefundDepositOnUnknownRefReturnsEmpty(): void
    {
        $svc = new PaymentService(new SandboxGateway());
        $this->assertSame([], $svc->refundDeposit('no-such-ref'));
    }

    public function testRefundDepositIsIdempotentOnAnAlreadyRefundedIntent(): void
    {
        PaymentService::onDepositSettledUsing(fn () => 1);
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'dep-refund-3'));
        $svc->refundDeposit($intent['provider_ref']);
        $again = $svc->refundDeposit($intent['provider_ref']);
        $this->assertSame('refunded', $again['status']);
    }

    public function testConfirmDepositOnMissingIntentReturnsEmpty(): void
    {
        $svc = new PaymentService(new SandboxGateway());
        $this->assertSame([], $svc->confirmDeposit('no-such-ref', []));
    }
}
