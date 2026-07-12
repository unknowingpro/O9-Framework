<?php
declare(strict_types=1);

namespace App\Entitlements;

use App\Core\Database;

/**
 * Tier-based entitlements: can(user, feature) is the paywall/feature gate.
 * Resolves a user's entitlement value as:
 *   per-user override (unexpired) > tier value (config) > safe default.
 *
 * A global enforcement mode (config('entitlements.mode'), 'off'|'enforce',
 * default 'off') makes every gate fully permissive when off, so the engine
 * can ship dark and be switched on deliberately. This is the only place
 * entitlement resolution lives.
 *
 * Entitlement definitions come from config('entitlements.entitlements'):
 *   'key' => ['bool', [tier0 => bool, tier1 => bool, ...]]   // indexed by tier position
 *   'key' => ['int',  [tier0 => int,  tier1 => int,  ...]]
 * and config('entitlements.tiers') lists the tier names in ascending order,
 * e.g. ['basic', 'pro'].
 *
 * Writes go through a `user_subscriptions` table (tier/status/current_period_end
 * — the same table Subscriptions/SubscriptionService manages) and an
 * `entitlement_overrides` table for per-user exceptions. Auditing each write
 * is opt-in via auditUsing(), the framework's injectable-hook pattern —
 * without a registered hook, writes still happen, just unaudited.
 */
final class EntitlementService
{
    private Database $db;

    /** @var (callable(string, int, string, int, array<string, mixed>): void)|null */
    private static $auditor = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @param (callable(string, int, string, int, array<string, mixed>): void)|null $fn */
    public static function auditUsing(?callable $fn): void
    {
        self::$auditor = $fn;
    }

    /** off => permissive (no gating); enforce => real resolution. */
    public function mode(): string
    {
        return (string) config('entitlements.mode', 'off') === 'enforce' ? 'enforce' : 'off';
    }

    public function tierOf(int $userId): string
    {
        // Honour current_period_end: a lapsed-but-not-yet-cron'd 'active' row must
        // NOT keep granting the tier (otherwise a delayed renew cron = free access).
        $row = $this->db->raw(
            "SELECT tier FROM user_subscriptions
              WHERE user_id = ? AND status = 'active'
                AND (current_period_end IS NULL OR current_period_end > ?) LIMIT 1",
            [$userId, gmdate('Y-m-d H:i:s')]
        )->fetch();
        $tier = is_array($row) ? (string) ($row['tier'] ?? 'basic') : 'basic';
        return in_array($tier, (array) config('entitlements.tiers', ['basic']), true) ? $tier : 'basic';
    }

    public function can(int $userId, string $key): bool
    {
        if ($this->mode() === 'off') {
            return true;
        }
        return (bool) $this->resolve($userId, $key, 'bool');
    }

    public function limit(int $userId, string $key): int
    {
        if ($this->mode() === 'off') {
            return -1;
        }
        return (int) $this->resolve($userId, $key, 'int');
    }

    /** -1 when unlimited; else max(0, limit - used). */
    public function remaining(int $userId, string $key, int $used): int
    {
        $limit = $this->limit($userId, $key);
        return $limit < 0 ? -1 : max(0, $limit - $used);
    }

    /** Throws EntitlementDenied if the boolean entitlement is off (no-op when mode=off). */
    public function assertCan(int $userId, string $key): void
    {
        if (!$this->can($userId, $key)) {
            throw new EntitlementDenied($key);
        }
    }

    /**
     * Block-new / grandfather-existing: $current is the user's CURRENT count of
     * the limited thing. Throws if adding one more would exceed the limit
     * ($current >= limit), unless unlimited (-1). Never inspects existing items;
     * no-op when mode=off (limit() returns -1).
     */
    public function assertWithin(int $userId, string $key, int $current): void
    {
        $limit = $this->limit($userId, $key);
        if ($limit >= 0 && $current >= $limit) {
            throw new EntitlementDenied($key);
        }
    }

    /** @return array<string, bool|int> fully-resolved entitlement map. */
    public function all(int $userId): array
    {
        $out = [];
        foreach ((array) config('entitlements.entitlements', []) as $key => $defn) {
            $type = (string) ($defn[0] ?? 'bool');
            $out[$key] = $type === 'bool' ? $this->can($userId, $key) : $this->limit($userId, $key);
        }
        return $out;
    }

    /* ── admin writes (engine owns its table writes) ─────────────────────── */

    /** Assign a user's tier (upsert one active row). */
    public function setTier(int $userId, string $tier, int $byUserId, string $source = 'manual'): void
    {
        if (!in_array($tier, (array) config('entitlements.tiers', []), true)) {
            throw new \RuntimeException('unknown tier: ' . $tier);
        }
        $now = gmdate('Y-m-d H:i:s');
        $exists = $this->db->raw('SELECT id FROM user_subscriptions WHERE user_id = ? LIMIT 1', [$userId])->fetch();
        if ($exists) {
            $this->db->raw(
                "UPDATE user_subscriptions SET tier = ?, status = 'active', source = ?, updated_at = ? WHERE user_id = ?",
                [$tier, $source, $now, $userId]
            );
        } else {
            $this->db->raw(
                "INSERT INTO user_subscriptions (user_id, tier, status, source, started_at, updated_at)
                 VALUES (?, ?, 'active', ?, ?, ?)",
                [$userId, $tier, $source, $now, $now]
            );
        }
        $this->audit('ent.tier_set', $byUserId, 'user', $userId, ['tier' => $tier, 'source' => $source]);
    }

    /** Add/replace a per-user override. $expiresAt is 'Y-m-d H:i:s' UTC or null. */
    public function setOverride(int $userId, string $key, string $value, int $byUserId, ?string $reason = null, ?string $expiresAt = null): void
    {
        if (!isset(((array) config('entitlements.entitlements', []))[$key])) {
            throw new \RuntimeException('unknown entitlement key: ' . $key);
        }
        $now = gmdate('Y-m-d H:i:s');
        $exists = $this->db->raw('SELECT id FROM entitlement_overrides WHERE user_id = ? AND ent_key = ? LIMIT 1', [$userId, $key])->fetch();
        if ($exists) {
            $this->db->raw(
                'UPDATE entitlement_overrides SET value = ?, reason = ?, expires_at = ? WHERE user_id = ? AND ent_key = ?',
                [$value, $reason, $expiresAt, $userId, $key]
            );
        } else {
            $this->db->raw(
                'INSERT INTO entitlement_overrides (user_id, ent_key, value, reason, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $key, $value, $reason, $expiresAt, $now]
            );
        }
        $this->audit('ent.override_set', $byUserId, 'user', $userId, ['key' => $key, 'value' => $value]);
    }

    /** Remove a per-user override. */
    public function clearOverride(int $userId, string $key, int $byUserId): void
    {
        $this->db->raw('DELETE FROM entitlement_overrides WHERE user_id = ? AND ent_key = ?', [$userId, $key]);
        $this->audit('ent.override_clear', $byUserId, 'user', $userId, ['key' => $key]);
    }

    /* ── resolution ───────────────────────────────────────────────────────── */

    /** override (unexpired) > tier value > safe default (false / 0). */
    private function resolve(int $userId, string $key, string $expectType): bool|int
    {
        $defn = (array) config('entitlements.entitlements', []);
        if (!isset($defn[$key]) || $defn[$key][0] !== $expectType) {
            return $expectType === 'bool' ? false : 0; // unknown/typed-wrong => safe default
        }
        $ov = $this->override($userId, $key);
        if ($ov !== null) {
            return $expectType === 'bool' ? ($ov === '1' || $ov === 'true') : (int) $ov;
        }
        $tiers = (array) config('entitlements.tiers', ['basic']);
        $tierIdx = array_search($this->tierOf($userId), $tiers, true);
        $val = $defn[$key][1][$tierIdx] ?? ($expectType === 'bool' ? false : 0);
        return $expectType === 'bool' ? (bool) $val : (int) $val;
    }

    /** Active (unexpired) override value for (user,key), or null. */
    private function override(int $userId, string $key): ?string
    {
        $row = $this->db->raw(
            'SELECT value, expires_at FROM entitlement_overrides WHERE user_id = ? AND ent_key = ? LIMIT 1',
            [$userId, $key]
        )->fetch();
        if (!is_array($row)) {
            return null;
        }
        $exp = (string) ($row['expires_at'] ?? '');
        if ($exp !== '' && $exp < gmdate('Y-m-d H:i:s')) {
            return null; // expired
        }
        return (string) $row['value'];
    }

    /** @param array<string, mixed> $meta */
    private function audit(string $action, int $byUserId, string $targetType, int $targetId, array $meta): void
    {
        if (self::$auditor !== null) {
            (self::$auditor)($action, $byUserId, $targetType, $targetId, $meta);
        }
    }
}
