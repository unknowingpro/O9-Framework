<?php
declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * setup/scripts/sync-framework.php declares plain global functions (it's a
 * standalone CLI script, not an App\ class) guarded by a
 * realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__ check, so require_once
 * here defines the functions without invoking the CLI entrypoint. It writes
 * via fwrite(STDOUT/STDERR, ...) directly (see MigrateCommandTest), so these
 * tests assert on return values and real filesystem side effects.
 */
final class SyncFrameworkTest extends TestCase
{
    private string $root;
    private string $target;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/setup/scripts/sync-framework.php';
        $this->root = dirname(__DIR__, 2);
        $this->target = sys_get_temp_dir() . '/o9-sync-target-' . bin2hex(random_bytes(4));
        mkdir($this->target, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->target);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testExpandListsOnlyRealFilesAndIsStable(): void
    {
        $first = \o9_sync_expand($this->root);
        $second = \o9_sync_expand($this->root);
        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
        $this->assertContains('setup/scripts/sync-framework.php', $first);
        $this->assertContains('public/index.php', $first);
        $this->assertContains('app/Core/App.php', $first);
        foreach ($first as $relative) {
            $this->assertFileExists($this->root . '/' . $relative);
        }
    }

    public function testPlanMarksEverythingAsCreateForAnEmptyTarget(): void
    {
        $plan = \o9_sync_plan($this->root, $this->target);
        $this->assertNotEmpty($plan);
        foreach ($plan as $entry) {
            $this->assertSame('create', $entry['status']);
        }
    }

    public function testApplyCopiesFilesAndSecondPlanIsAllUnchanged(): void
    {
        $plan = \o9_sync_plan($this->root, $this->target);
        $counts = \o9_sync_apply($plan, $this->root, $this->target);

        $this->assertSame(count($plan), $counts['created']);
        $this->assertSame(0, $counts['updated']);
        $this->assertFileExists($this->target . '/app/Core/App.php');
        $this->assertFileEquals($this->root . '/app/Core/App.php', $this->target . '/app/Core/App.php');

        $secondPlan = \o9_sync_plan($this->root, $this->target);
        foreach ($secondPlan as $entry) {
            $this->assertSame('unchanged', $entry['status']);
        }
    }

    public function testDriftedFileIsMarkedUpdateAndApplyRestoresIt(): void
    {
        \o9_sync_apply(\o9_sync_plan($this->root, $this->target), $this->root, $this->target);
        file_put_contents($this->target . '/app/Core/App.php', "<?php\n// drifted\n");

        $plan = \o9_sync_plan($this->root, $this->target);
        $entry = current(array_filter($plan, static fn (array $e): bool => $e['path'] === 'app/Core/App.php'));
        $this->assertNotFalse($entry);
        $this->assertSame('update', $entry['status']);

        $counts = \o9_sync_apply($plan, $this->root, $this->target);
        $this->assertSame(1, $counts['updated']);
        $this->assertFileEquals($this->root . '/app/Core/App.php', $this->target . '/app/Core/App.php');
    }

    public function testMainReturnsOneWithoutATargetArgument(): void
    {
        $this->assertSame(1, \o9_sync_main(['sync-framework.php']));
    }

    public function testMainReturnsOneWhenTargetDoesNotExist(): void
    {
        $this->assertSame(1, \o9_sync_main(['sync-framework.php', $this->target . '/no-such-dir']));
    }

    public function testMainRefusesToSyncPhpframeOntoItself(): void
    {
        $this->assertSame(1, \o9_sync_main(['sync-framework.php', $this->root]));
    }

    public function testMainDryRunDoesNotWriteAnyFiles(): void
    {
        $exit = \o9_sync_main(['sync-framework.php', $this->target, '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->target . '/app/Core/App.php');
    }

    public function testMainAppliesTheSyncByDefault(): void
    {
        $exit = \o9_sync_main(['sync-framework.php', $this->target]);
        $this->assertSame(0, $exit);
        $this->assertFileEquals($this->root . '/app/Core/App.php', $this->target . '/app/Core/App.php');
    }
}
