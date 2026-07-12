<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\MigrateCommand;
use App\Core\Database;
use App\Services\MigrationsService;
use PHPUnit\Framework\TestCase;

/**
 * MigrateCommand writes via fwrite(STDOUT/STDERR, ...) directly, bypassing
 * PHP's output-buffering functions (ob_start doesn't intercept fwrite on the
 * STDOUT/STDERR stream constants) — so these tests assert on exit codes and
 * real database/filesystem side effects rather than captured console text.
 */
final class MigrateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-migrate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Database::getInstance()->pdo()->exec('DROP TABLE IF EXISTS migrations');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
        Database::getInstance()->pdo()->exec('DROP TABLE IF EXISTS migrations');
    }

    public function testStatusFlagDoesNotApplyPendingMigrations(): void
    {
        file_put_contents($this->dir . '/001_x.sql', "CREATE TABLE IF NOT EXISTS mig_x (id INTEGER PRIMARY KEY);\n");
        $svc = new MigrationsService($this->dir);
        $exit = (new MigrateCommand($svc))->run(['--status']);
        $this->assertSame(0, $exit);
        $this->assertSame(['001_x.sql'], $svc->pending()); // still pending — nothing applied
    }

    public function testRunAppliesPendingMigrationsAndIsIdempotent(): void
    {
        file_put_contents($this->dir . '/001_y.sql', "CREATE TABLE IF NOT EXISTS mig_y (id INTEGER PRIMARY KEY);\n");
        $svc = new MigrationsService($this->dir);
        $cmd = new MigrateCommand($svc);

        $exit = $cmd->run([]);
        $this->assertSame(0, $exit);
        $this->assertSame([], $svc->pending());
        $this->assertTrue(Database::getInstance()->tableExists('mig_y'));

        // Second run: nothing pending, still exits 0.
        $this->assertSame(0, $cmd->run([]));
    }

    public function testNothingToApplyWhenDirIsEmpty(): void
    {
        $exit = (new MigrateCommand(new MigrationsService($this->dir)))->run([]);
        $this->assertSame(0, $exit);
    }

    public function testFailedMigrationExitsOneWithoutMarkingItApplied(): void
    {
        file_put_contents($this->dir . '/001_bad.sql', "SELECT * FROM no_such_table_at_all;\n");
        $svc = new MigrationsService($this->dir);
        $exit = (new MigrateCommand($svc))->run([]);
        $this->assertSame(1, $exit);
        $this->assertSame(['001_bad.sql'], $svc->pending()); // not recorded as applied
    }

    public function testDefaultConstructorUsesTheRealMigrationsService(): void
    {
        // Smoke test the no-arg path (the one setup/bin/console actually uses)
        // against the framework's real, committed migrations — must not throw.
        $exit = (new MigrateCommand())->run(['--status']);
        $this->assertSame(0, $exit);
    }
}
