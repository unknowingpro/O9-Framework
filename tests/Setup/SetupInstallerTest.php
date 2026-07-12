<?php
declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * setup/setup.php declares plain global functions (same guarded-main
 * pattern as sync-framework.php — see SyncFrameworkTest) so require_once
 * defines them without running the installer. o9_setup_run_migrations() and
 * o9_setup_main() bind to the *current process's* Database singleton (this
 * phpunit run's in-memory sqlite), so exercising the real end-to-end flow
 * belongs in a live subprocess smoke test, not here — these tests cover the
 * pure filesystem/generation logic against a throwaway temp directory.
 */
final class SetupInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/setup/setup.php';
        $this->root = sys_get_temp_dir() . '/o9-setup-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
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

    public function testRequirementsCheckPassesInThisEnvironment(): void
    {
        $checks = \o9_setup_check_requirements();
        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            if (in_array($check['name'], ['ext-redis', 'ext-intl'], true)) {
                continue; // optional
            }
            $this->assertTrue($check['ok'], "{$check['name']}: {$check['detail']}");
        }
    }

    public function testEnsureEnvFileCreatesFromExampleOnce(): void
    {
        file_put_contents($this->root . '/.env.example', "FOO=bar\nJWT_SECRET=\nAPP_KEY=\n");

        $first = \o9_setup_ensure_env_file($this->root);
        $this->assertTrue($first['created']);
        $this->assertFileExists($this->root . '/.env');
        $this->assertFileEquals($this->root . '/.env.example', $this->root . '/.env');

        $second = \o9_setup_ensure_env_file($this->root);
        $this->assertFalse($second['created']);
    }

    public function testEnsureEnvFileThrowsWithoutAnExample(): void
    {
        $this->expectException(\RuntimeException::class);
        \o9_setup_ensure_env_file($this->root);
    }

    public function testGeneratedSecretsMeetEachConsumersFormatRequirement(): void
    {
        $jwt = \o9_setup_random_jwt_secret();
        $this->assertGreaterThanOrEqual(32, strlen($jwt)); // App::assertSecureConfig's production minimum

        $key = \o9_setup_random_app_key();
        $raw = base64_decode($key, true);
        $this->assertNotFalse($raw);
        $this->assertSame(32, strlen($raw)); // Security\Crypto::key() requires exactly 32 raw bytes

        $this->assertNotSame(\o9_setup_random_jwt_secret(), \o9_setup_random_jwt_secret());
        $this->assertNotSame(\o9_setup_random_app_key(), \o9_setup_random_app_key());
    }

    public function testEnsureSecretFillsABlankValueButNeverOverwrites(): void
    {
        $envPath = $this->root . '/.env';
        file_put_contents($envPath, "APP_NAME=Test\nJWT_SECRET=\nAPP_KEY=already-set\n");

        $this->assertTrue(\o9_setup_ensure_secret($envPath, 'JWT_SECRET', 'generated-value'));
        $this->assertFalse(\o9_setup_ensure_secret($envPath, 'APP_KEY', 'would-overwrite'));

        $contents = (string) file_get_contents($envPath);
        $this->assertStringContainsString('JWT_SECRET=generated-value', $contents);
        $this->assertStringContainsString('APP_KEY=already-set', $contents);
        $this->assertStringNotContainsString('would-overwrite', $contents);
    }

    public function testEnsureSecretAppendsAMissingKey(): void
    {
        $envPath = $this->root . '/.env';
        file_put_contents($envPath, "APP_NAME=Test\n");

        $this->assertTrue(\o9_setup_ensure_secret($envPath, 'NEW_SECRET', 'value'));
        $this->assertStringContainsString('NEW_SECRET=value', (string) file_get_contents($envPath));
    }

    public function testScaffoldStorageDirsIsIdempotent(): void
    {
        $created = \o9_setup_scaffold_storage_dirs($this->root);
        $this->assertNotEmpty($created);
        foreach ($created as $dir) {
            $this->assertDirectoryExists($this->root . '/' . $dir);
        }

        $second = \o9_setup_scaffold_storage_dirs($this->root);
        $this->assertSame([], $second);
    }
}
