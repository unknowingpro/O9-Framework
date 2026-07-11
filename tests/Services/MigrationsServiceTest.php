<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Core\Database;
use App\Services\MigrationsService;
use PHPUnit\Framework\TestCase;

final class MigrationsServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-migrations-' . getmypid();
        @mkdir($this->dir, 0777, true);
        foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
            unlink($f);
        }
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS migrations');
        $pdo->exec('DROP TABLE IF EXISTS mig_demo');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAppliesPendingInOrderAndTracksThem(): void
    {
        file_put_contents($this->dir . '/001_create.sql',
            "-- create the demo table\nCREATE TABLE mig_demo (id INTEGER PRIMARY KEY, v TEXT); -- inline note\n");
        file_put_contents($this->dir . '/002_seed.sql',
            "INSERT INTO mig_demo (v) VALUES ('one');\nINSERT INTO mig_demo (v) VALUES ('two');");

        $svc = new MigrationsService($this->dir);
        $this->assertSame(['001_create.sql', '002_seed.sql'], $svc->pending());

        $applied = $svc->applyAll();
        $this->assertSame(['001_create.sql', '002_seed.sql'], $applied);
        $this->assertSame([], $svc->pending());
        $this->assertSame(2, Database::getInstance()->table('mig_demo')->count());

        // Re-running applies nothing.
        $this->assertSame([], $svc->applyAll());
    }

    public function testFailedMigrationRollsBackAndIsNotRecorded(): void
    {
        file_put_contents($this->dir . '/001_bad.sql',
            "CREATE TABLE mig_demo (id INTEGER PRIMARY KEY);\nTHIS IS NOT SQL;");

        $svc = new MigrationsService($this->dir);
        try {
            $svc->applyAll();
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Migration '001_bad.sql' failed", $e->getMessage());
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        // Rolled back: table gone, migration not recorded, still pending.
        $this->assertFalse(Database::getInstance()->tableExists('mig_demo'));
        $this->assertSame(['001_bad.sql'], $svc->pending());
    }
}
