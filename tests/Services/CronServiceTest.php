<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Core\Database;
use App\Services\CronService;
use PHPUnit\Framework\TestCase;

final class CronServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS jwt_revocations');
        $pdo->exec('DROP TABLE IF EXISTS entitlement_overrides');
        $pdo->exec('CREATE TABLE jwt_revocations (jti TEXT PRIMARY KEY, user_id INTEGER, revoked_at TEXT, exp TEXT)');
        $pdo->exec(
            'CREATE TABLE entitlement_overrides (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ent_key TEXT, value TEXT,
                reason TEXT, expires_at TEXT, created_at TEXT
            )'
        );
    }

    public function testPrunesOnlyExpiredRowsFromBothTables(): void
    {
        $db = Database::getInstance();
        $db->raw('INSERT INTO jwt_revocations (jti, user_id, revoked_at, exp) VALUES (?, ?, ?, ?)', ['j1', 1, '2020-01-01 00:00:00', '2020-01-02 00:00:00']);
        $db->raw('INSERT INTO jwt_revocations (jti, user_id, revoked_at, exp) VALUES (?, ?, ?, ?)', ['j2', 1, '2099-01-01 00:00:00', '2099-01-02 00:00:00']);
        $db->raw('INSERT INTO entitlement_overrides (user_id, ent_key, value, expires_at, created_at) VALUES (?, ?, ?, ?, ?)', [1, 'k', 'v', '2020-01-01 00:00:00', '2020-01-01 00:00:00']);
        $db->raw('INSERT INTO entitlement_overrides (user_id, ent_key, value, expires_at, created_at) VALUES (?, ?, ?, ?, ?)', [1, 'k2', 'v', null, '2020-01-01 00:00:00']);

        $pruned = CronService::runMaintenance();

        $this->assertSame(1, $pruned['jwt_revocations']);
        $this->assertSame(1, $pruned['entitlement_overrides']);
        $this->assertSame('j2', $db->table('jwt_revocations')->first()['jti']);
        $this->assertNull($db->table('entitlement_overrides')->where('ent_key', '=', 'k')->first());
        $this->assertNotNull($db->table('entitlement_overrides')->where('ent_key', '=', 'k2')->first());
    }

    public function testSkipsTablesThatDoNotExist(): void
    {
        Database::getInstance()->pdo()->exec('DROP TABLE jwt_revocations');
        Database::getInstance()->pdo()->exec('DROP TABLE entitlement_overrides');

        $this->assertSame([], CronService::runMaintenance());
    }
}
