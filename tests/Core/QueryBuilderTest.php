<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS qb_t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER, grp TEXT, deleted_at TEXT)');
        $this->db->pdo()->exec('DELETE FROM qb_t');
        $this->db->table('qb_t')->insert([
            ['name' => 'a', 'score' => 10, 'grp' => 'x', 'deleted_at' => null],
            ['name' => 'b', 'score' => 20, 'grp' => 'x', 'deleted_at' => null],
            ['name' => 'c', 'score' => 30, 'grp' => 'y', 'deleted_at' => '2026-01-01'],
        ]);
    }

    public function testClonePerCallNeverMutatesParent(): void
    {
        $base = $this->db->table('qb_t');
        $filtered = $base->where('grp', '=', 'x');
        $this->assertSame(3, $base->count(), 'parent builder must stay unfiltered');
        $this->assertSame(2, $filtered->count());
    }

    public function testWhereChainsAndOrder(): void
    {
        $rows = $this->db->table('qb_t')
            ->where('score', '>=', 20)
            ->orderBy('score', 'DESC')
            ->get();
        $this->assertSame(['c', 'b'], array_column($rows, 'name'));
    }

    public function testOrWhereAndWhereIn(): void
    {
        $rows = $this->db->table('qb_t')->where('name', '=', 'a')->orWhere('name', '=', 'c')->get();
        $this->assertCount(2, $rows);

        $this->assertSame(2, $this->db->table('qb_t')->whereIn('name', ['a', 'b'])->count());
        $this->assertSame(0, $this->db->table('qb_t')->whereIn('name', [])->count());
    }

    public function testNullFilters(): void
    {
        $this->assertSame(2, $this->db->table('qb_t')->whereNull('deleted_at')->count());
        $this->assertSame(1, $this->db->table('qb_t')->whereNotNull('deleted_at')->count());
    }

    public function testSelectLimitOffsetFirst(): void
    {
        $row = $this->db->table('qb_t')->select('name')->orderBy('name')->offset(1)->limit(1)->get();
        $this->assertSame([['name' => 'b']], $row);
        $first = $this->db->table('qb_t')->orderBy('score', 'DESC')->first();
        $this->assertSame('c', $first['name']);
        $this->assertNull($this->db->table('qb_t')->where('name', '=', 'zzz')->first());
    }

    public function testGroupByHavingAndSelectRaw(): void
    {
        $rows = $this->db->table('qb_t')
            ->selectRaw('grp', 'SUM(score) AS total')
            ->groupBy('grp')
            ->havingRaw('SUM(score) >= 30')
            ->orderBy('grp')
            ->get();
        $this->assertSame([['grp' => 'x', 'total' => 30], ['grp' => 'y', 'total' => 30]], $rows);
    }

    public function testUpdateAndDeleteScopedByWhere(): void
    {
        $n = $this->db->table('qb_t')->where('grp', '=', 'x')->update(['score' => 99]);
        $this->assertSame(2, $n);
        $this->assertSame(2, $this->db->table('qb_t')->where('score', '=', 99)->count());

        $this->assertSame(1, $this->db->table('qb_t')->where('name', '=', 'c')->delete());
        $this->assertSame(2, $this->db->table('qb_t')->count());
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS qb_u (code TEXT PRIMARY KEY, val INTEGER)');
        $this->db->pdo()->exec('DELETE FROM qb_u');
        $this->db->table('qb_u')->upsert([['code' => 'k', 'val' => 1]], ['code'], ['val']);
        $this->db->table('qb_u')->upsert([['code' => 'k', 'val' => 5]], ['code'], ['val']);
        $rows = $this->db->table('qb_u')->get();
        $this->assertSame([['code' => 'k', 'val' => 5]], $rows);
    }

    public function testJoinValidatesIdentifiers(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS qb_j (id INTEGER PRIMARY KEY, qb_t_id INTEGER, tag TEXT)');
        $this->db->pdo()->exec('DELETE FROM qb_j');
        $aId = (int) $this->db->raw("SELECT id FROM qb_t WHERE name = 'a'")->fetch()['id'];
        $this->db->table('qb_j')->insert(['qb_t_id' => $aId, 'tag' => 'tagged']);

        $rows = $this->db->table('qb_t')
            ->select('qb_t.name', 'qb_j.tag')
            ->join('qb_j', 'qb_j.qb_t_id', '=', 'qb_t.id')
            ->get();
        $this->assertSame([['name' => 'a', 'tag' => 'tagged']], $rows);

        $this->expectException(\RuntimeException::class);
        $this->db->table('qb_t')->join('qb_j; DROP TABLE qb_t', 'a.b', '=', 'c.d');
    }

    public function testCountIncludesJoins(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS qb_j (id INTEGER PRIMARY KEY, qb_t_id INTEGER, tag TEXT)');
        $this->db->pdo()->exec('DELETE FROM qb_j');
        $aId = (int) $this->db->raw("SELECT id FROM qb_t WHERE name = 'a'")->fetch()['id'];
        $bId = (int) $this->db->raw("SELECT id FROM qb_t WHERE name = 'b'")->fetch()['id'];
        $this->db->table('qb_j')->insert([
            ['qb_t_id' => $aId, 'tag' => 'keep'],
            ['qb_t_id' => $bId, 'tag' => 'drop'],
        ]);

        // A count over a joined query filtered on the JOINED table's column
        // must build the same FROM/JOIN clause as get() — not just FROM qb_t.
        $n = $this->db->table('qb_t')
            ->join('qb_j', 'qb_j.qb_t_id', '=', 'qb_t.id')
            ->where('qb_j.tag', '=', 'keep')
            ->count();
        $this->assertSame(1, $n);
    }

    public function testCountWithGroupByCountsGroups(): void
    {
        // 'x' has two rows and 'y' one — a grouped count() must return the
        // number of GROUPS (2), not the first group's row count.
        $n = $this->db->table('qb_t')->groupBy('grp')->count();
        $this->assertSame(2, $n);
    }

    public function testCountWithGroupByAndHavingCountsMatchingGroups(): void
    {
        // Only group 'x' reaches SUM(score) >= 30... both do (x=30, y=30);
        // raise the bar so only one group qualifies.
        $n = $this->db->table('qb_t')->groupBy('grp')->havingRaw('SUM(score) > 30')->count();
        $this->assertSame(0, $n);
        $n = $this->db->table('qb_t')->groupBy('grp')->havingRaw('SUM(score) >= 30')->count();
        $this->assertSame(2, $n);
    }

    public function testUnsafeColumnAndOperatorRejected(): void
    {
        try {
            $this->db->table('qb_t')->where('name; --', '=', 'x');
            $this->fail('expected unsafe identifier rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unsafe SQL identifier', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->db->table('qb_t')->where('name', 'UNION SELECT', 'x');
    }
}
