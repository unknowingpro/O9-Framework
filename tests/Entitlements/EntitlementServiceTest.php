<?php
declare(strict_types=1);

namespace Tests\Entitlements;

use App\Core\Database;
use App\Entitlements\EntitlementDenied;
use App\Entitlements\EntitlementService;
use PHPUnit\Framework\TestCase;

final class EntitlementServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS user_subscriptions');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS entitlement_overrides');
        $this->db->pdo()->exec(
            'CREATE TABLE user_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, tier TEXT, status TEXT,
                source TEXT, current_period_end TEXT, started_at TEXT, updated_at TEXT
            )'
        );
        $this->db->pdo()->exec(
            'CREATE TABLE entitlement_overrides (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ent_key TEXT, value TEXT,
                reason TEXT, expires_at TEXT, created_at TEXT
            )'
        );
        EntitlementService::auditUsing(null);
    }

    protected function tearDown(): void
    {
        EntitlementService::auditUsing(null);
    }

    public function testModeDefaultsToOff(): void
    {
        $this->assertSame('off', (new EntitlementService())->mode());
    }

    public function testOffModeIsFullyPermissive(): void
    {
        $svc = new EntitlementService();
        $this->assertTrue($svc->can(1, 'anything'));
        $this->assertSame(-1, $svc->limit(1, 'anything'));
        $this->assertSame(-1, $svc->remaining(1, 'anything', 999));
        $svc->assertCan(1, 'anything'); // must not throw
        $svc->assertWithin(1, 'anything', 999); // must not throw
    }

    public function testTierOfDefaultsToBasicWithNoSubscription(): void
    {
        $this->assertSame('basic', (new EntitlementService())->tierOf(1));
    }

    public function testTierOfHonoursCurrentPeriodEnd(): void
    {
        $svc = new EntitlementService();
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $past = gmdate('Y-m-d H:i:s', time() - 3600);

        $this->db->raw(
            'INSERT INTO user_subscriptions (user_id, tier, status, current_period_end, started_at, updated_at) VALUES (?,?,?,?,?,?)',
            [1, 'pro', 'active', $future, $now, $now]
        );
        $this->assertSame('pro', $svc->tierOf(1));

        $this->db->raw(
            'INSERT INTO user_subscriptions (user_id, tier, status, current_period_end, started_at, updated_at) VALUES (?,?,?,?,?,?)',
            [2, 'pro', 'active', $past, $now, $now]
        );
        // Lapsed-but-not-yet-cron'd row must NOT keep granting the tier.
        $this->assertSame('basic', $svc->tierOf(2));
    }

    public function testSetTierUpsertsAndAudits(): void
    {
        $seen = null;
        EntitlementService::auditUsing(function (string $action, int $byUserId, string $targetType, int $targetId, array $meta) use (&$seen): void {
            $seen = [$action, $byUserId, $targetType, $targetId, $meta];
        });

        $svc = new EntitlementService();
        $svc->setTier(1, 'pro', 99, 'manual');
        $this->assertSame('pro', $svc->tierOf(1));
        $this->assertSame(['ent.tier_set', 99, 'user', 1, ['tier' => 'pro', 'source' => 'manual']], $seen);

        // Second call upserts (no duplicate row).
        $svc->setTier(1, 'basic', 99);
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM user_subscriptions WHERE user_id = 1')->fetch()['c'];
        $this->assertSame(1, $count);
    }

    public function testSetTierRejectsUnknownTier(): void
    {
        $this->expectException(\RuntimeException::class);
        (new EntitlementService())->setTier(1, 'not-a-real-tier', 99);
    }

    public function testSetOverrideRejectsUnknownEntitlementKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new EntitlementService())->setOverride(1, 'no-such-key', '1', 99);
    }

    public function testSetOverridePersistsAndCanBeCleared(): void
    {
        // can()/limit() short-circuit permissive in the default 'off' mode (tested
        // above), so this verifies the override row itself round-trips correctly.
        $svc = new EntitlementService();
        $svc->setOverride(1, 'export_data', '1', 99, 'promo');
        $row = $this->db->raw('SELECT value, reason FROM entitlement_overrides WHERE user_id = ? AND ent_key = ?', [1, 'export_data'])->fetch();
        $this->assertSame('1', $row['value']);
        $this->assertSame('promo', $row['reason']);

        // Re-saving updates in place rather than inserting a second row.
        $svc->setOverride(1, 'export_data', '0', 99);
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM entitlement_overrides WHERE user_id = 1 AND ent_key = ?', ['export_data'])->fetch()['c'];
        $this->assertSame(1, $count);

        $svc->clearOverride(1, 'export_data', 99);
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM entitlement_overrides WHERE user_id = 1 AND ent_key = ?', ['export_data'])->fetch()['c'];
        $this->assertSame(0, $count);
    }

    public function testClearOverrideDeletesUnconditionallyAndAudits(): void
    {
        $this->db->raw(
            'INSERT INTO entitlement_overrides (user_id, ent_key, value, created_at) VALUES (?, ?, ?, ?)',
            [1, 'some_key', '1', gmdate('Y-m-d H:i:s')]
        );
        $seen = null;
        EntitlementService::auditUsing(function (string $action, int $byUserId, string $targetType, int $targetId, array $meta) use (&$seen): void {
            $seen = $action;
        });
        (new EntitlementService())->clearOverride(1, 'some_key', 99);
        $count = (int) $this->db->raw("SELECT COUNT(*) c FROM entitlement_overrides WHERE user_id = 1 AND ent_key = 'some_key'")->fetch()['c'];
        $this->assertSame(0, $count);
        $this->assertSame('ent.override_clear', $seen);
    }

    public function testAssertCanAndAssertWithinAreNoOpsWhenModeIsOff(): void
    {
        $svc = new EntitlementService();
        $svc->assertCan(1, 'anything');
        $svc->assertWithin(1, 'anything', 1_000_000);
        $this->addToAssertionCount(2);
    }

    public function testAllReturnsEveryConfiguredEntitlementPermissiveInOffMode(): void
    {
        // In the default 'off' mode every gate resolves permissive.
        $this->assertSame(['export_data' => true, 'projects_max' => -1], (new EntitlementService())->all(1));
    }
}
