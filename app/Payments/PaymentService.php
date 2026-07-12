<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Database;
use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Gateway\SandboxGateway;

/**
 * Orchestrates external money movement. Owns idempotency + the
 * payment_intents lifecycle — the framework's only opinion about payments
 * bookkeeping. It never mutates a ledger/wallet directly: the framework has
 * no ledger system, so every ledger effect is an injectable hook (the same
 * pattern as Auth/Lang/Subscriptions), wired in app/bootstrap.php:
 *
 *   PaymentService::onDepositSettledUsing(
 *       fn (PaymentRequest $req, PaymentResult $r) => (new WalletService())->credit(
 *           $req->userId, $req->amountCents, $req->kind, $req->refType, $req->refId, $req->note
 *       ) // returns an app-defined ledger tx id, persisted on the intent
 *   );
 *   PaymentService::onPayoutSettledUsing(
 *       fn (PaymentRequest $req, PaymentResult $r) => (new WalletService())->markPaid(...)
 *   );
 *   PaymentService::onRefundReversedUsing(
 *       fn (int $userId, int $cents, string $currency) => (new WalletService())->debit($userId, $cents, 'refund', ...)
 *   );
 *   PaymentService::balanceUsing(fn (int $userId, string $currency) => (new WalletService())->balance($userId, $currency));
 *
 * Idempotency semantics: an idempotency_key maps to exactly one intent. A
 * repeat call with the same key returns that existing intent as-is, whatever
 * its status — it never performs a second settlement. A caller that wants to
 * retry a 'failed' attempt must supply a fresh key. The intent is persisted
 * as 'pending' BEFORE the external gateway call (so a real async provider's
 * charge is always recoverable by its provider_ref).
 */
final class PaymentService
{
    private readonly Database $db;

    /** @var (callable(PaymentRequest, PaymentResult): int)|null returns an app ledger tx id */
    private static $onDepositSettled = null;
    /** @var (callable(PaymentRequest, PaymentResult): void)|null */
    private static $onPayoutSettled = null;
    /** @var (callable(int, int, string): void)|null */
    private static $onRefundReversed = null;
    /** @var (callable(int, string): int)|null */
    private static $balanceProvider = null;

    public function __construct(private readonly PaymentGateway $gateway = new SandboxGateway())
    {
        $this->db = Database::getInstance();
    }

    /** @param (callable(PaymentRequest, PaymentResult): int)|null $fn */
    public static function onDepositSettledUsing(?callable $fn): void
    {
        self::$onDepositSettled = $fn;
    }

    /** @param (callable(PaymentRequest, PaymentResult): void)|null $fn */
    public static function onPayoutSettledUsing(?callable $fn): void
    {
        self::$onPayoutSettled = $fn;
    }

    /** @param (callable(int, int, string): void)|null $fn */
    public static function onRefundReversedUsing(?callable $fn): void
    {
        self::$onRefundReversed = $fn;
    }

    /** @param (callable(int, string): int)|null $fn */
    public static function balanceUsing(?callable $fn): void
    {
        self::$balanceProvider = $fn;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$onDepositSettled = null;
        self::$onPayoutSettled = null;
        self::$onRefundReversed = null;
        self::$balanceProvider = null;
    }

    /**
     * Money in. Idempotent on $req->idempotencyKey. On gateway success, calls
     * the onDepositSettled hook (crediting the app's ledger) and links the
     * resulting tx id to the intent. Returns the payment_intents row.
     *
     * @return array<string, mixed>
     */
    public function deposit(PaymentRequest $req): array
    {
        $existing = $this->findByKey($req->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        $intentId = $this->createIntent('in', $req);
        if ($intentId === 0) {
            return $this->findByKey($req->idempotencyKey) ?? throw new \RuntimeException('payment intent race');
        }
        $result = $this->gateway->deposit($req);

        if ($result->status === PaymentResult::PENDING) {
            $this->markPending($intentId, $result);
            $row = $this->findIntent($intentId);
            $row['approval_url'] = $result->approvalUrl;
            return $row;
        }
        if (!$result->ok()) {
            $this->failIntent($intentId, $result);
            return $this->findIntent($intentId);
        }

        $this->db->transaction(function () use ($intentId, $req, $result): void {
            $txId = self::$onDepositSettled !== null ? (self::$onDepositSettled)($req, $result) : null;
            $this->settleIntent($intentId, $result, $txId);
        });
        return $this->findIntent($intentId);
    }

    /**
     * Money out. Idempotent on $req->idempotencyKey. On gateway success, calls
     * the onPayoutSettled hook so the app can record the executed payout
     * (e.g. flip a withdrawal request to paid) using $req->refType/$req->refId.
     *
     * @return array<string, mixed>
     */
    public function payout(PaymentRequest $req): array
    {
        $existing = $this->findByKey($req->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        $intentId = $this->createIntent('out', $req);
        if ($intentId === 0) {
            return $this->findByKey($req->idempotencyKey) ?? throw new \RuntimeException('payment intent race');
        }
        $result = $this->gateway->payout($req);

        if (!$result->ok()) {
            $this->failIntent($intentId, $result);
            return $this->findIntent($intentId);
        }

        $this->db->transaction(function () use ($intentId, $req, $result): void {
            if (self::$onPayoutSettled !== null) {
                (self::$onPayoutSettled)($req, $result);
            }
            $this->settleIntent($intentId, $result, null);
        });
        return $this->findIntent($intentId);
    }

    /**
     * Confirm a redirect deposit on callback: verify with the intent's
     * provider and, on success, settle via the deposit hook. Idempotent — an
     * already-settled intent is returned unchanged.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function confirmDeposit(string $providerRef, array $params): array
    {
        $intent = $this->db->raw(
            "SELECT * FROM payment_intents WHERE provider_ref = ? AND direction = 'in' LIMIT 1",
            [$providerRef]
        )->fetch();
        if (!$intent) {
            return [];
        }
        if ($intent['status'] !== 'pending') {
            return $intent;
        }
        $gateway = PaymentGatewayFactory::make((string) $intent['provider']);
        $params = $params + [
            '_amount_cents' => (int) $intent['amount_cents'],
            '_currency'     => (string) ($intent['currency'] ?: 'USD'),
        ];
        $result = $gateway->verifyDeposit($providerRef, $params);
        $id = (int) $intent['id'];
        if ($result->status === PaymentResult::PENDING) {
            return $this->findIntent($id);
        }
        if (!$result->ok()) {
            $this->failIntent($id, $result);
            return $this->findIntent($id);
        }
        return $this->applyDepositSuccess($intent, $result);
    }

    /**
     * Confirm a deposit from a verified async provider webhook. The
     * provider's verifyWebhook authenticates the payload (signature) — that
     * is the only gateway call, so this performs no network I/O on the
     * credit path. Idempotent.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function confirmDepositFromWebhook(string $provider, string $rawBody, array $headers): array
    {
        $gateway = PaymentGatewayFactory::make($provider);
        $event   = $gateway->verifyWebhook($rawBody, $headers); // throws on a bad/forged signature
        if (!in_array($event->type, ['deposit.confirmed', 'deposit.failed'], true)) {
            return [];
        }
        $intent = $this->db->raw(
            "SELECT * FROM payment_intents WHERE provider_ref = ? AND direction = 'in' LIMIT 1",
            [$event->providerRef]
        )->fetch();
        if (!$intent || $intent['status'] !== 'pending') {
            return $intent ?: [];
        }
        if ($event->type === 'deposit.failed') {
            $this->failIntent((int) $intent['id'], new PaymentResult(PaymentResult::FAILED, $event->providerRef));
            return $this->findIntent((int) $intent['id']);
        }
        return $this->applyDepositSuccess($intent, new PaymentResult(PaymentResult::SUCCEEDED, $event->providerRef));
    }

    /**
     * Refund a settled deposit: reverse it at the provider AND, via the
     * onRefundReversed hook, in the app's ledger. Full refund only. Only a
     * 'succeeded' 'in' intent is refundable; idempotent. When balanceUsing()
     * is registered, the balance is checked BEFORE the external call so the
     * provider is never refunded while the reversal can't be applied; without
     * a registered balance hook this guard is skipped.
     *
     * @return array<string, mixed> the intent.
     */
    public function refundDeposit(string $providerRef, ?string $reason = null): array
    {
        $intent = $this->db->raw(
            "SELECT * FROM payment_intents WHERE provider_ref = ? AND direction = 'in' LIMIT 1",
            [$providerRef]
        )->fetch();
        if (!$intent) {
            return [];
        }
        if ($intent['status'] !== 'succeeded') {
            return $intent;
        }
        $amount = (int) $intent['amount_cents'];
        $currency = (string) ($intent['currency'] ?: 'USD');
        $userId = (int) $intent['user_id'];
        if (self::$balanceProvider !== null && (self::$balanceProvider)($userId, $currency) < $amount) {
            throw new \RuntimeException('insufficient balance to reverse this deposit');
        }
        $gateway = PaymentGatewayFactory::make((string) $intent['provider']);
        if (!in_array('refund', $gateway->capabilities(), true)) {
            throw new UnsupportedOperation((string) $intent['provider'] . ': refund not supported');
        }
        $result = $gateway->refund($providerRef, $amount, $reason);
        if (!$result->ok()) {
            throw new \RuntimeException('gateway refund failed');
        }
        $id = (int) $intent['id'];
        $this->db->transaction(function () use ($userId, $id, $amount, $currency): void {
            if (self::$onRefundReversed !== null) {
                (self::$onRefundReversed)($userId, $amount, $currency);
            }
            $this->db->raw('UPDATE payment_intents SET status = ?, updated_at = ? WHERE id = ?', ['refunded', gmdate('Y-m-d H:i:s'), $id]);
        });
        return $this->findIntent($id);
    }

    /**
     * Reconcile pending deposit intents against their provider — recover
     * confirmations whose webhook was missed. Selects pending 'in' intents
     * idle for at least $minAgeSeconds (optionally a single $provider),
     * re-verifies each, and on a confirmed result settles via the normal
     * path. Non-confirmed results are LEFT pending. Partial-failure isolated:
     * a provider error on one intent is swallowed (retried next run).
     *
     * @return int number of intents resolved (settled or expired).
     */
    public function reconcilePendingDeposits(int $minAgeSeconds = 0, ?string $provider = null, ?PaymentGateway $gateway = null): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minAgeSeconds);
        $sql = "SELECT * FROM payment_intents WHERE direction = 'in' AND status = 'pending' AND updated_at <= ?";
        $args = [$cutoff];
        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $args[] = $provider;
        }
        $resolved = 0;
        foreach ($this->db->raw($sql, $args)->fetchAll() as $intent) {
            try {
                if ($this->reconcileOne($intent, $gateway)) {
                    $resolved++;
                }
            } catch (\Throwable) {
                continue; // leave it pending; a later run retries
            }
        }
        return $resolved;
    }

    /** @param array<string, mixed> $intent */
    private function reconcileOne(array $intent, ?PaymentGateway $gateway): bool
    {
        $gw = $gateway ?? PaymentGatewayFactory::make((string) $intent['provider']);
        if (!in_array('verify', $gw->capabilities(), true)) {
            return false;
        }
        $result = $gw->verifyDeposit((string) $intent['provider_ref'], [
            '_amount_cents' => (int) $intent['amount_cents'],
            '_currency'     => (string) ($intent['currency'] ?: 'USD'),
        ]);
        if ($result->ok()) {
            return $this->applyDepositSuccess($intent, $result) !== [];
        }
        if ($result->status === PaymentResult::FAILED) {
            return $this->failPendingIntent((int) $intent['id'], $result);
        }
        return false;
    }

    /**
     * Settle a confirmed deposit via the onDepositSettled hook, atomically.
     * The claim + settle run as ONE transaction whose first statement is a
     * conditional UPDATE that CLAIMS the row: if another path already moved
     * it off pending, the claim affects 0 rows and we abort — so a deposit is
     * never double-settled. Returns [] when the claim was lost.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function applyDepositSuccess(array $intent, PaymentResult $result): array
    {
        $id = (int) $intent['id'];
        $req = new PaymentRequest(
            userId: (int) $intent['user_id'],
            amountCents: (int) $intent['amount_cents'],
            idempotencyKey: (string) $intent['idempotency_key'],
            currency: (string) ($intent['currency'] ?: 'USD'),
        );
        $claimed = $this->db->transaction(function () use ($id, $req, $result): bool {
            if (!$this->claimPendingIntent($id)) {
                return false;
            }
            $txId = self::$onDepositSettled !== null ? (self::$onDepositSettled)($req, $result) : null;
            $this->settleIntent($id, $result, $txId);
            return true;
        });
        return $claimed ? $this->findIntent($id) : [];
    }

    private function claimPendingIntent(int $id): bool
    {
        return $this->db->raw(
            "UPDATE payment_intents SET status = 'settling', updated_at = ? WHERE id = ? AND status = 'pending'",
            [gmdate('Y-m-d H:i:s'), $id]
        )->rowCount() === 1;
    }

    private function failPendingIntent(int $id, PaymentResult $result): bool
    {
        return $this->db->raw(
            "UPDATE payment_intents SET status = 'failed', provider_ref = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            [$result->providerRef, gmdate('Y-m-d H:i:s'), $id]
        )->rowCount() === 1;
    }

    /* ── intent persistence (this table only) ────────────────────────────── */

    /** @return array<string, mixed>|null */
    private function findByKey(string $key): ?array
    {
        $row = $this->db->raw('SELECT * FROM payment_intents WHERE idempotency_key = ? LIMIT 1', [$key])->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function findIntent(int $id): array
    {
        return $this->db->raw('SELECT * FROM payment_intents WHERE id = ?', [$id])->fetch() ?: [];
    }

    /** @return int the new intent id, or 0 if the idempotency key was already taken (race). */
    private function createIntent(string $direction, PaymentRequest $req): int
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->raw(
                "INSERT INTO payment_intents
                    (idempotency_key, provider, provider_ref, direction, amount_cents,
                     currency, status, user_id, wallet_tx_id, ref_type, ref_id, meta,
                     created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, ?, 'pending', ?, NULL, ?, ?, ?, ?, ?)",
                [
                    $req->idempotencyKey, $this->gateway->name(), $direction, $req->amountCents,
                    $req->currency, $req->userId, $req->refType, $req->refId,
                    $req->meta === [] ? null : json_encode($req->meta), $now, $now,
                ]
            );
        } catch (\PDOException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                return 0; // UNIQUE(idempotency_key) collision — concurrent duplicate
            }
            throw $e;
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function settleIntent(int $id, PaymentResult $result, ?int $txId): void
    {
        $this->db->raw(
            'UPDATE payment_intents SET status = ?, provider_ref = ?, wallet_tx_id = ?, updated_at = ? WHERE id = ?',
            [$result->status, $result->providerRef, $txId, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    private function failIntent(int $id, PaymentResult $result): void
    {
        $this->db->raw(
            "UPDATE payment_intents SET status = 'failed', provider_ref = ?, updated_at = ? WHERE id = ?",
            [$result->providerRef, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    /** Record a redirect deposit as pending: set provider_ref + concrete provider (chain winner). */
    private function markPending(int $id, PaymentResult $result): void
    {
        $provider = (string) ($result->raw['_provider'] ?? '');
        if ($provider !== '') {
            $this->db->raw('UPDATE payment_intents SET provider = ?, provider_ref = ?, updated_at = ? WHERE id = ?',
                [$provider, $result->providerRef, gmdate('Y-m-d H:i:s'), $id]);
        } else {
            $this->db->raw('UPDATE payment_intents SET provider_ref = ?, updated_at = ? WHERE id = ?',
                [$result->providerRef, gmdate('Y-m-d H:i:s'), $id]);
        }
    }
}
