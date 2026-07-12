<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Db;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    protected function setUp(): void
    {
        $db = Database::getInstance();
        $db->pdo()->exec('DROP TABLE IF EXISTS db_facade_t');
        $db->pdo()->exec('CREATE TABLE db_facade_t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, hits INTEGER DEFAULT 0)');
    }

    public function testConnectionReturnsTheSharedPdo(): void
    {
        $this->assertSame(Database::getInstance()->pdo(), Db::connection());
    }

    public function testInsertFirstAllExecute(): void
    {
        $id = Db::insert('INSERT INTO db_facade_t (name) VALUES (?)', ['alpha']);
        $this->assertGreaterThan(0, $id);

        $row = Db::first('SELECT * FROM db_facade_t WHERE id = ?', [$id]);
        $this->assertSame('alpha', $row['name']);
        $this->assertNull(Db::first('SELECT * FROM db_facade_t WHERE id = 99999'));

        Db::insert('INSERT INTO db_facade_t (name) VALUES (?)', ['beta']);
        $all = Db::all('SELECT * FROM db_facade_t ORDER BY id');
        $this->assertCount(2, $all);

        $affected = Db::execute('UPDATE db_facade_t SET hits = hits + 1 WHERE id = ?', [$id]);
        $this->assertSame(1, $affected);
    }

    public function testDriverHelpers(): void
    {
        $this->assertSame('sqlite', Db::driver());
        $this->assertTrue(Db::isSqlite());
        $this->assertFalse(Db::isMysql());
        $this->assertSame("strftime('%s','now')", Db::nowExpr());
    }

    public function testUpsertInsertsThenUpdatesOnConflict(): void
    {
        // SQLite upsert needs a UNIQUE/PK conflict target; exercised via a
        // dedicated table so it doesn't interfere with db_facade_t's tests.
        $db = Database::getInstance();
        $db->pdo()->exec('DROP TABLE IF EXISTS db_upsert_t');
        $db->pdo()->exec('CREATE TABLE db_upsert_t (id INTEGER PRIMARY KEY, name TEXT, hits INTEGER DEFAULT 0)');

        Db::upsert('db_upsert_t', ['id'], ['name' => 'excluded.name', 'hits' => 'hits + 1'], ['id' => 1, 'name' => 'first']);
        $row = Db::first('SELECT * FROM db_upsert_t WHERE id = 1');
        $this->assertSame('first', $row['name']);
        $this->assertSame(0, (int) $row['hits']);

        Db::upsert('db_upsert_t', ['id'], ['name' => 'excluded.name', 'hits' => 'hits + 1'], ['id' => 1, 'name' => 'second']);
        $row = Db::first('SELECT * FROM db_upsert_t WHERE id = 1');
        $this->assertSame('second', $row['name']);
        $this->assertSame(1, (int) $row['hits']);
    }

    public function testUpsertRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(\RuntimeException::class);
        Db::upsert('db_facade_t; DROP TABLE db_facade_t', ['id'], ['name' => "'x'"], ['id' => 1, 'name' => 'x']);
    }

    public function testReconnectDoesNotThrow(): void
    {
        Db::reconnect();
        $this->assertSame('sqlite', Db::driver());
    }
}
