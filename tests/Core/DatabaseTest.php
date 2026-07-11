<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS db_t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, hits INTEGER DEFAULT 0)');
        $this->db->pdo()->exec('DELETE FROM db_t');
    }

    public function testSingletonAndDriver(): void
    {
        $this->assertSame($this->db, Database::getInstance());
        $this->assertSame('sqlite', $this->db->driver());
    }

    public function testInsertGetIdAndRaw(): void
    {
        $id = $this->db->insertGetId('db_t', ['name' => 'alpha']);
        $this->assertGreaterThan(0, $id);
        $row = $this->db->raw('SELECT * FROM db_t WHERE id = ?', [$id])->fetch();
        $this->assertSame('alpha', $row['name']);
    }

    public function testInsertOrIgnorePortabilityVerbRuns(): void
    {
        $this->db->insertGetId('db_t', ['id' => 77, 'name' => 'first']);
        // On SQLite the verb passes through untouched; on MySQL it would be rewritten.
        $this->db->raw('INSERT OR IGNORE INTO db_t (id, name) VALUES (?, ?)', [77, 'second']);
        $row = $this->db->raw('SELECT name FROM db_t WHERE id = 77')->fetch();
        $this->assertSame('first', $row['name']);
    }

    public function testTableAndColumnExistence(): void
    {
        $this->assertTrue($this->db->tableExists('db_t'));
        $this->assertFalse($this->db->tableExists('no_such_table'));
        $this->assertTrue($this->db->columnExists('db_t', 'name'));
        $this->assertFalse($this->db->columnExists('db_t', 'ghost'));
    }

    public function testAssertSafeIdentifierRejectsInjection(): void
    {
        Database::assertSafeIdentifier('valid_name');
        $this->expectException(\RuntimeException::class);
        Database::assertSafeIdentifier('users; DROP TABLE users');
    }

    public function testTransactionCommitsAndRollsBack(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->insertGetId('db_t', ['name' => 'committed']);
        });
        $this->assertSame(1, $this->db->table('db_t')->count());

        try {
            $this->db->transaction(function (Database $db): void {
                $db->insertGetId('db_t', ['name' => 'doomed']);
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $this->db->table('db_t')->count());
    }

    public function testNestedTransactionRollsBackOnlyInnerWork(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->insertGetId('db_t', ['name' => 'outer']);
            try {
                $db->transaction(function (Database $inner): void {
                    $inner->insertGetId('db_t', ['name' => 'inner']);
                    throw new \RuntimeException('inner boom');
                });
            } catch (\RuntimeException) {
                // inner savepoint rolled back; outer continues
            }
        });
        $names = array_column($this->db->table('db_t')->get(), 'name');
        $this->assertSame(['outer'], $names);
    }
}
