<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Request;
use App\Support\Delta;
use PHPUnit\Framework\TestCase;

final class DeltaTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS delta_items');
        $this->db->pdo()->exec(
            'CREATE TABLE delta_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT,
                updated_at TEXT, deleted_at TEXT
            )'
        );
    }

    public function testNormalizeHandlesEpochIso8601AndBlank(): void
    {
        $this->assertNull(Delta::normalize(''));
        $this->assertNull(Delta::normalize('   '));
        $this->assertSame(gmdate('Y-m-d H:i:s', 1700000000), Delta::normalize('1700000000'));
        $this->assertSame('2024-01-02 03:04:05', Delta::normalize('2024-01-02T03:04:05.123Z'));
        $this->assertSame('2024-01-02 03:04:05', Delta::normalize('2024-01-02 03:04:05'));
    }

    public function testSinceReadsFromRequestQuery(): void
    {
        $backup = $_GET;
        $_GET = ['updated_since' => '1700000000'];
        try {
            $this->assertSame(gmdate('Y-m-d H:i:s', 1700000000), Delta::since(new Request()));
        } finally {
            $_GET = $backup;
        }
    }

    public function testSinceReturnsNullWhenAbsent(): void
    {
        $backup = $_GET;
        $_GET = [];
        try {
            $this->assertNull(Delta::since(new Request()));
        } finally {
            $_GET = $backup;
        }
    }

    public function testRowsReturnsChangedAndTombstonedRows(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', 1_700_000_000);
        $before = gmdate('Y-m-d H:i:s', 1_699_999_000);
        $after  = gmdate('Y-m-d H:i:s', 1_700_001_000);

        $this->db->raw('INSERT INTO delta_items (user_id, name, updated_at) VALUES (?, ?, ?)', [1, 'old', $before]);
        $this->db->raw('INSERT INTO delta_items (user_id, name, updated_at) VALUES (?, ?, ?)', [1, 'new', $after]);
        $this->db->raw('INSERT INTO delta_items (user_id, name, updated_at, deleted_at) VALUES (?, ?, ?, ?)', [1, 'gone', $after, $after]);
        $this->db->raw('INSERT INTO delta_items (user_id, name, updated_at) VALUES (?, ?, ?)', [2, 'other-user', $after]);

        $result = Delta::rows($this->db, 'delta_items', 'user_id', 1, $cutoff);
        $names = array_column($result['changed'], 'name');
        $this->assertSame(['new'], $names); // 'old' too early, 'gone' excluded (soft-deleted), 'other-user' wrong owner
        $this->assertCount(1, $result['deleted']);
    }

    public function testRowsWithoutSoftDeleteSkipsTombstoneQuery(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS delta_no_soft');
        $this->db->pdo()->exec('CREATE TABLE delta_no_soft (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, updated_at TEXT)');
        $this->db->raw('INSERT INTO delta_no_soft (user_id, updated_at) VALUES (?, ?)', [1, gmdate('Y-m-d H:i:s', 1_700_001_000)]);

        $result = Delta::rows($this->db, 'delta_no_soft', 'user_id', 1, gmdate('Y-m-d H:i:s', 1_700_000_000), 'updated_at', false);
        $this->assertCount(1, $result['changed']);
        $this->assertSame([], $result['deleted']);
    }
}
