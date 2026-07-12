<?php
declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * setup/framework-manifest.php is the sync contract read by
 * setup/scripts/sync-framework.php — every path it lists must exist in this
 * repo, and it must not accidentally claim an app-owned directory.
 */
final class FrameworkManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEveryDirectoryEntryExists(): void
    {
        $manifest = require $this->root . '/setup/framework-manifest.php';
        foreach ($manifest['dirs'] as $dir) {
            $this->assertDirectoryExists($this->root . '/' . $dir, "Manifest dir missing: {$dir}");
        }
    }

    public function testEveryFileEntryExists(): void
    {
        $manifest = require $this->root . '/setup/framework-manifest.php';
        foreach ($manifest['files'] as $file) {
            $this->assertFileExists($this->root . '/' . $file, "Manifest file missing: {$file}");
        }
    }

    public function testDoesNotClaimAppOwnedDirectories(): void
    {
        $manifest = require $this->root . '/setup/framework-manifest.php';
        $appOwned = ['app/Controllers', 'app/Models', 'app/Resources', 'app/Views', 'app/Lang', 'app/Services'];

        foreach ($manifest['dirs'] as $dir) {
            foreach ($appOwned as $owned) {
                if ($dir === $owned) {
                    $this->fail("Manifest wrongly claims app-owned directory: {$dir}");
                }
            }
        }
        // The one Services subtree that IS framework-owned is explicitly scoped.
        $this->assertContains('app/Services/I18n', $manifest['dirs']);
    }

    public function testPathsAreUniqueAndUseForwardSlashes(): void
    {
        $manifest = require $this->root . '/setup/framework-manifest.php';
        $all = [...$manifest['dirs'], ...$manifest['files']];
        $this->assertSame(array_unique($all), array_values($all));
        foreach ($all as $path) {
            $this->assertStringNotContainsString('\\', $path);
            $this->assertStringStartsNotWith('/', $path);
        }
    }
}
