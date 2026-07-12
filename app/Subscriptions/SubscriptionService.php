<?php
declare(strict_types=1);

namespace App\Subscriptions;

use App\Core\Database;
use App\Core\Events;
use App\Payments\Dto\SubscriptionRequest;
use App\Payments\Dto\WebhookEvent;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayFactory;
use RuntimeException;

/**
 * Subscription billing lifecycle. Writes user_subscriptions directly for
 * billing-driven changes (tier + status + period + provider + scheduled
 * cols); EntitlementService::tierOf remains the sole tier-resolution reader.
 * Branches on the active gateway's capabilities(): native-recurring
 * providers react to webhook events; one-shot providers charge on
 * subscribe/renew and renew via the daily cron (renewDue).
 *
 * The framework has no wallet/ledger system, so charging and notifying are
 * injectable hooks (the same pattern as Auth/Lang), typically wired in
 * app/bootstrap.php:
 *
 *   SubscriptionService::chargeUsing(
 *       fn (int $userId, int $cents, string $note) => (new WalletService())->debit($userId, $cents, 'subscription', 'subscription', null, $note)
 *   );
 *   SubscriptionService::notifyUsing(
 *       fn (int $userId, string $type, array $meta) => (new NotificationService())->notify($userId, null, $type, 'subscription', null, $meta)
 *   );
 *
 * Without a registered charge hook, one-shot tiers activate unpaid (no-op
 * charge) — fine for native-recurring gateways, which never call it.
 */
final class SubscriptionService
{
    private Database $db;
    private PaymentGateway $gateway;

    /** @var (callable(int, int, string): void)|null throws on insufficient funds/failure */
    private static $charger = null;
    /** @var (callable(int, string, array<string, mixed>): void)|null */
    private static $notifier = null;

    public function __construct(?PaymentGateway $gateway = null)
    {
        $this->db = Database::getInstance();
        $this->gateway = $gateway ?? PaymentGatewayFactory::make();
    }

    /** @param (callable(int, int, string): void)|null $fn */
    public static function chargeUsing(?callable $fn): void
    {
        self::$charger = $fn;
    }

    /** @param (callable(int, string, array<string, mixed>): void)|null $fn */
    public static function notifyUsing(?callable $fn): void
    {
        self::$notifier = $fn;
    }

    private function charge(int $userId, int $cents, string $note): void
    {
        if ($cents > 0 && self::$charger !== null) {
            (self::$charger)($userId, $cents, $note);
        }
    }

    /** @param array<string, mixed> $meta */
    private function notify(int $userId, string $type, array $meta = []): void
    {
        if (self::$notifier !== null) {
            (self::$notifier)($userId, $type, $meta);
        }
    }

    private function nativeRecurring(): bool
    {
        return in_array('subscriptions', $this->gateway->capabilities(), true);
    }

    /** @return array<string, mixed>|null the user's subscription row, or null. */
    private function load(int $userId): ?array
    {
        $row = $this->db->raw('SELECT * FROM user_subscriptions WHERE user_id = ? LIMIT 1', [$userId])->fetch();
        return $row ?: null;
    }

    /**
     * Client-facing subscription snapshot. No row -> basic/none. `active` = a
     * non-basic 'active' tier whose period hasn't lapsed.
     *
     * @return array<string, mixed>
     */
    public function statusFor(int $userId): array
    {
        $row = $this->load($userId);
        if (!$row) {
            return [
                'tier' => 'basic', 'status' => 'none', 'source' => null, 'provider' => null,
                'billing_interval' => null, 'current_period_end' => null,
                'scheduled_tier' => null, 'scheduled_at' => null, 'active' => false,
            ];
        }
        $end = (string) ($row['current_period_end'] ?? '');
        $notLapsed = $end === '' || strtotime($end . ' UTC') > time();
        return [
            'tier'               => (string) $row['tier'],
            'status'             => (string) $row['status'],
            'source'             => $row['source'] ?? null,
            'provider'           => $row['provider'] ?? null,
            'billing_interval'   => $row['billing_interval'] ?? null,
            'current_period_end' => $row['current_period_end'] ?? null,
            'scheduled_tier'     => $row['scheduled_tier'] ?? null,
            'scheduled_at'       => $row['scheduled_at'] ?? null,
            'active'             => (string) $row['status'] === 'active' && (string) $row['tier'] !== 'basic' && $notLapsed,
        ];
    }

    private function periodEnd(string $interval, ?string $from = null): string
    {
        $base = $from !== null ? strtotime($from) : false;
        $base = $base !== false ? $base : time();
        return gmdate('Y-m-d H:i:s', $base + SubscriptionPlan::intervalDays($interval) * 86400);
    }

    /**
     * Upsert the subscription row with the given fields (billing-driven).
     *
     * @param array<string, mixed> $fields
     */
    private function upsert(int $userId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $exists = $this->db->raw('SELECT id FROM user_subscriptions WHERE user_id = ? LIMIT 1', [$userId])->fetch();
        if ($exists) {
            $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($fields)));
            $this->db->raw("UPDATE user_subscriptions SET $set WHERE user_id = ?", [...array_values($fields), $userId]);
        } else {
            $fields['user_id']    = $userId;
            $fields['started_at'] = $fields['started_at'] ?? gmdate('Y-m-d H:i:s');
            $cols = implode(', ', array_keys($fields));
            $ph   = implode(', ', array_fill(0, count($fields), '?'));
            $this->db->raw("INSERT INTO user_subscriptions ($cols) VALUES ($ph)", array_values($fields));
        }
    }

    /**
     * Start (or confirm) a subscription to $tier at $interval. Idempotent: a
     * matching active subscription is returned without re-charging.
     *
     * @return array<string, mixed> the subscription row (or ['approval_url'=>..] for redirect flows).
     */
    public function subscribe(int $userId, string $tier, string $interval, ?string $returnUrl = null): array
    {
        if (!SubscriptionPlan::isPaidTier($tier) || !SubscriptionPlan::isValidInterval($interval)) {
            throw new RuntimeException('invalid subscription tier/interval');
        }
        $existing = $this->load($userId);
        if ($existing && $existing['status'] === 'active' && $existing['tier'] === $tier
            && $existing['billing_interval'] === $interval) {
            return $existing;
        }
        $price    = SubscriptionPlan::priceCents($tier, $interval);
        $provider = $this->gateway->name();

        if ($this->nativeRecurring()) {
            $res = $this->gateway->createSubscription(new SubscriptionRequest(
                userId: $userId, tier: $tier, amountCents: $price,
                idempotencyKey: 'sub:' . $userId . ':' . $tier . ':' . $interval,
                interval: $interval, returnUrl: $returnUrl,
            ));
            if ($res->approvalUrl !== null) {
                $this->upsert($userId, [
                    'tier' => $tier, 'status' => 'past_due', 'source' => 'purchase',
                    'provider' => $provider, 'provider_sub_id' => $res->providerSubId,
                    'billing_interval' => $interval, 'price_cents' => $price,
                ]);
                return ['approval_url' => $res->approvalUrl] + ($this->load($userId) ?? []);
            }
            $this->upsert($userId, [
                'tier' => $tier, 'status' => 'active', 'source' => 'purchase',
                'provider' => $provider, 'provider_sub_id' => $res->providerSubId,
                'billing_interval' => $interval, 'price_cents' => $price,
                'current_period_end' => $res->currentPeriodEnd ?? $this->periodEnd($interval),
                'scheduled_tier' => null, 'scheduled_interval' => null, 'scheduled_at' => null, 'canceled_at' => null,
            ]);
            return $this->load($userId) ?? [];
        }

        $this->db->transaction(function () use ($userId, $tier, $interval, $price, $provider): void {
            $this->charge($userId, $price, $tier . '/' . $interval);
            $this->upsert($userId, [
                'tier' => $tier, 'status' => 'active', 'source' => 'purchase',
                'provider' => $provider, 'provider_sub_id' => null,
                'billing_interval' => $interval, 'price_cents' => $price,
                'current_period_end' => $this->periodEnd($interval),
                'scheduled_tier' => null, 'scheduled_interval' => null, 'scheduled_at' => null, 'canceled_at' => null,
            ]);
        });
        return $this->load($userId) ?? [];
    }

    /**
     * Change tier/interval. Upgrade (higher price): immediate + prorated charge
     * for the price difference, charged now. Downgrade (lower price): scheduled
     * for the current period end (no charge now).
     *
     * @return array<string, mixed> the updated row.
     */
    public function changeTier(int $userId, string $newTier, string $interval): array
    {
        if (!SubscriptionPlan::isValidInterval($interval)) {
            throw new RuntimeException('invalid interval');
        }
        $sub = $this->load($userId);
        if (!$sub || $sub['status'] !== 'active') {
            throw new RuntimeException('no active subscription to change');
        }
        $newPrice = SubscriptionPlan::priceCents($newTier, $interval);
        $curPrice = (int) $sub['price_cents'];

        if ($newPrice > $curPrice) { // upgrade — immediate, prorated
            $periodDays    = max(1, SubscriptionPlan::intervalDays((string) $sub['billing_interval']));
            $remainingSecs = max(0, strtotime((string) $sub['current_period_end']) - time());
            $remainingDays = (int) round($remainingSecs / 86400);
            $credit = (int) floor($remainingDays / $periodDays * $curPrice);
            $credit = max(0, min($credit, $curPrice));
            $charge = max(0, $newPrice - $credit);
            $this->db->transaction(function () use ($userId, $newTier, $interval, $newPrice, $charge): void {
                if (!$this->nativeRecurring() && $charge > 0) {
                    $this->charge($userId, $charge, 'upgrade ' . $newTier);
                }
                $this->upsert($userId, [
                    'tier' => $newTier, 'status' => 'active', 'billing_interval' => $interval,
                    'price_cents' => $newPrice, 'current_period_end' => $this->periodEnd($interval),
                    'scheduled_tier' => null, 'scheduled_interval' => null, 'scheduled_at' => null,
                ]);
            });
            return $this->load($userId) ?? [];
        }

        // downgrade — schedule at current period end (no charge now).
        $this->upsert($userId, [
            'scheduled_tier'     => $newTier,
            'scheduled_interval' => $interval,
            'scheduled_at'       => (string) $sub['current_period_end'],
        ]);
        return $this->load($userId) ?? [];
    }

    /** Cancel at period end: keep access until current_period_end, then lapse to basic. */
    public function cancel(int $userId): void
    {
        $sub = $this->load($userId);
        if (!$sub || $sub['status'] === 'canceled') {
            return;
        }
        if ($this->nativeRecurring() && !empty($sub['provider_sub_id'])) {
            $this->gateway->cancelSubscription((string) $sub['provider_sub_id'], true);
        }
        $this->upsert($userId, [
            'status'             => 'canceled',
            'canceled_at'        => gmdate('Y-m-d H:i:s'),
            'scheduled_tier'     => 'basic',
            'scheduled_interval' => null,
            'scheduled_at'       => (string) ($sub['current_period_end'] ?? gmdate('Y-m-d H:i:s')),
        ]);
    }

    /**
     * Activate a subscription that was paid for OUT OF BAND (an external
     * store: Apple/Google IAP). No charge happens here — the store already
     * collected payment; we only reflect the entitlement. Idempotent on
     * re-redeem of the same receipt.
     *
     * @return array<string, mixed>
     */
    public function activateExternal(int $userId, string $tier, string $interval, string $provider, string $providerSubId, ?string $periodEnd = null): array
    {
        if (!SubscriptionPlan::isPaidTier($tier) || !SubscriptionPlan::isValidInterval($interval)) {
            throw new RuntimeException('invalid subscription tier/interval');
        }
        $this->upsert($userId, [
            'tier' => $tier, 'status' => 'active', 'source' => 'iap',
            'provider' => $provider, 'provider_sub_id' => $providerSubId,
            'billing_interval' => $interval, 'price_cents' => SubscriptionPlan::priceCents($tier, $interval),
            'current_period_end' => $periodEnd ?? $this->periodEnd($interval),
            'scheduled_tier' => null, 'scheduled_interval' => null, 'scheduled_at' => null, 'canceled_at' => null,
        ]);
        Events::dispatch('subscription.activated', ['user_id' => $userId, 'tier' => $tier, 'interval' => $interval, 'provider' => $provider, 'source' => 'iap']);
        return $this->load($userId) ?? [];
    }

    /**
     * Process subscriptions whose period has ended (cron, daily). For each:
     * apply a due scheduled change; else attempt renewal (rebill for
     * one-shot); a failed renewal -> past_due, and past_due beyond grace ->
     * lapse to basic. Idempotent and safe to re-run.
     *
     * @return int rows processed.
     */
    public function renewDue(?string $now = null): int
    {
        $nowTs = $now !== null ? strtotime($now) : false;
        $nowTs = $nowTs !== false ? $nowTs : time();
        $nowStr = gmdate('Y-m-d H:i:s', $nowTs);
        $rows = $this->db->raw(
            "SELECT user_id FROM user_subscriptions
              WHERE status IN ('active','past_due') AND current_period_end IS NOT NULL
                AND current_period_end <= ?",
            [$nowStr]
        )->fetchAll();

        $n = 0;
        foreach ($rows as $r) {
            $userId = (int) $r['user_id'];
            $outcome = null; // 'payment_failed' | 'ended' — emitted after commit
            $this->db->transaction(function () use ($userId, $nowTs, $nowStr, &$outcome): void {
                $sub = $this->load($userId);
                if (!$sub) {
                    return;
                }
                if (!empty($sub['scheduled_tier']) && !empty($sub['scheduled_at'])
                    && strtotime((string) $sub['scheduled_at']) <= $nowTs) {
                    $target = (string) $sub['scheduled_tier'];
                    if ($target === 'basic') {
                        $this->lapseToBasic($userId);
                    } else {
                        $interval = (string) ($sub['scheduled_interval'] ?: $sub['billing_interval'] ?: 'month');
                        $this->renewAtTier($userId, $target, $interval, $nowStr);
                    }
                    return;
                }
                if ($sub['status'] === 'past_due') {
                    $graceCut = $nowTs - SubscriptionPlan::graceDays() * 86400;
                    if (strtotime((string) $sub['current_period_end']) <= $graceCut) {
                        $this->lapseToBasic($userId);
                        $outcome = 'ended';
                    } elseif (!$this->nativeRecurring()) {
                        $this->renewAtTier($userId, (string) $sub['tier'], (string) ($sub['billing_interval'] ?: 'month'), $nowStr);
                    }
                    return;
                }
                $this->renewAtTier($userId, (string) $sub['tier'], (string) ($sub['billing_interval'] ?: 'month'), $nowStr);
                if (($this->load($userId)['status'] ?? '') === 'past_due') {
                    $outcome = 'payment_failed';
                }
            });
            if ($outcome === 'payment_failed') {
                $this->notify($userId, 'subscription_payment_failed', []);
            } elseif ($outcome === 'ended') {
                $this->notify($userId, 'subscription_ended', ['reason' => 'dunning']);
            }
            $n++;
        }
        return $n;
    }

    /** Charge + extend at the given tier (one-shot); on failure -> past_due. */
    private function renewAtTier(int $userId, string $tier, string $interval, string $nowStr): void
    {
        $price = SubscriptionPlan::priceCents($tier, $interval);
        if (!$this->nativeRecurring() && $price > 0) {
            try {
                $this->charge($userId, $price, 'renew ' . $tier);
            } catch (RuntimeException) {
                $this->upsert($userId, ['status' => 'past_due']);
                return;
            }
        }
        $this->upsert($userId, [
            'tier' => $tier, 'status' => 'active', 'billing_interval' => $interval, 'price_cents' => $price,
            'current_period_end' => $this->periodEnd($interval, $nowStr),
            'scheduled_tier' => null, 'scheduled_interval' => null, 'scheduled_at' => null,
        ]);
    }

    private function lapseToBasic(int $userId): void
    {
        $this->upsert($userId, [
            'tier' => 'basic', 'status' => 'active', 'price_cents' => 0,
            'provider' => null, 'provider_sub_id' => null, 'billing_interval' => null,
            'current_period_end' => null, 'scheduled_tier' => null, 'scheduled_interval' => null,
            'scheduled_at' => null,
        ]);
        // Dropped to the free tier — every lapse path funnels through here, so
        // this is the single place to signal that any plan-driven perks should
        // be withdrawn. Decoupled via an event; see EventListeners.
        Events::dispatch('subscription.deactivated', ['user_id' => $userId]);
    }

    /**
     * Apply a verified native-provider webhook event. Correlates the
     * subscription by provider_sub_id. Idempotent (re-delivery is safe).
     */
    public function handleWebhookEvent(WebhookEvent $e): void
    {
        $sub = $this->db->raw('SELECT * FROM user_subscriptions WHERE provider_sub_id = ? LIMIT 1', [$e->providerRef])->fetch();
        if (!$sub) {
            return;
        }
        $userId = (int) $sub['user_id'];
        switch ($e->type) {
            case 'invoice.paid':
                $fields = [
                    'status' => 'active',
                    'current_period_end' => $this->periodEnd((string) ($sub['billing_interval'] ?: 'month')),
                ];
                if ($e->providerSubId !== null && $e->providerSubId !== '' && $e->providerSubId !== (string) $sub['provider_sub_id']) {
                    $fields['provider_sub_id'] = $e->providerSubId;
                }
                $this->upsert($userId, $fields);
                break;
            case 'payment_failed':
                $this->upsert($userId, ['status' => 'past_due']);
                $this->notify($userId, 'subscription_payment_failed', []);
                break;
            case 'subscription.canceled':
                $this->lapseToBasic($userId);
                break;
            case 'subscription.refunded':
                $this->lapseToBasic($userId);
                $this->notify($userId, 'subscription_refunded', ['provider' => (string) ($sub['provider'] ?? '')]);
                break;
        }
    }

    /**
     * Verify and apply a provider webhook. Builds the named provider's
     * gateway, verifies the signature (throws on a forged/unsupported
     * request), then applies the normalized event (idempotent).
     *
     * @param array<string, string> $headers
     */
    public function processWebhook(string $provider, string $rawBody, array $headers): bool
    {
        $gateway = PaymentGatewayFactory::make($provider);
        $event   = $gateway->verifyWebhook($rawBody, $headers);

        // Per-event idempotency (providers re-deliver until 2xx). Claim the
        // event id BEFORE applying; a replay finds it claimed and is skipped.
        $eventId = (string) ($event->raw['id'] ?? '');
        if ($eventId !== '' && !$this->claimWebhookEvent($provider, $eventId)) {
            return true;
        }
        try {
            $this->handleWebhookEvent($event);
        } catch (\Throwable $e) {
            if ($eventId !== '') {
                $this->db->raw('DELETE FROM store_webhook_events WHERE provider = ? AND event_uid = ?', [$provider, $eventId]);
            }
            throw $e;
        }
        return true;
    }

    private function claimWebhookEvent(string $provider, string $eventId): bool
    {
        try {
            $this->db->raw(
                'INSERT INTO store_webhook_events (provider, event_uid, received_at) VALUES (?, ?, ?)',
                [$provider, $eventId, gmdate('Y-m-d H:i:s')]
            );
            return true;
        } catch (\Throwable) {
            return false; // UNIQUE(provider, event_uid) => already processed
        }
    }

    /** The user who owns a given provider subscription id, or null. */
    public function providerSubOwner(string $providerSubId): ?int
    {
        if ($providerSubId === '') {
            return null;
        }
        $row = $this->db->raw('SELECT user_id FROM user_subscriptions WHERE provider_sub_id = ? LIMIT 1', [$providerSubId])->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$charger = null;
        self::$notifier = null;
    }
}
