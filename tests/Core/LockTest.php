<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Lock;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    private string $name;

    protected function setUp(): void
    {
        $this->name = 'test:' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Lock::release($this->name);
        @unlink(storage_path('locks/' . str_replace(':', '_', $this->name) . '.lock'));
    }

    public function testAcquireSucceedsWhenFree(): void
    {
        $this->assertTrue(Lock::acquire($this->name));
        $this->assertTrue(Lock::held($this->name));
    }

    public function testAcquireIsReentrantWithinTheSameProcess(): void
    {
        $this->assertTrue(Lock::acquire($this->name));
        $this->assertTrue(Lock::acquire($this->name), 'the holder re-acquiring its own lock must not deadlock');
    }

    public function testReleaseFreesTheLock(): void
    {
        Lock::acquire($this->name);
        Lock::release($this->name);
        $this->assertFalse(Lock::held($this->name));
        $this->assertTrue(Lock::acquire($this->name));
    }

    public function testReleasingAnUnheldLockIsANoop(): void
    {
        Lock::release('never-acquired');
        $this->assertFalse(Lock::held('never-acquired'));
    }

    public function testTheLockFileIsCreatedWithASanitizedName(): void
    {
        Lock::acquire($this->name);
        $expected = storage_path('locks/' . str_replace(':', '_', $this->name) . '.lock');
        $this->assertFileExists($expected, 'the name must be sanitized into a safe filename');
    }

    public function testNamesCannotTraverseOutOfTheLockDirectory(): void
    {
        $evil = '../../etc/passwd';
        $this->assertTrue(Lock::acquire($evil));
        $this->assertFileExists(storage_path('locks/.._.._etc_passwd.lock'));
        Lock::release($evil);
        @unlink(storage_path('locks/.._.._etc_passwd.lock'));
    }

    /**
     * The contract that matters: a SECOND process must be turned away. flock is
     * per-open-file-handle, so a child process is the only honest way to test it.
     */
    public function testASecondProcessIsTurnedAway(): void
    {
        $this->assertTrue(Lock::acquire($this->name));

        $script = sprintf(
            'define("BASE_PATH", %s); require BASE_PATH."/vendor/autoload.php"; '
            . 'echo App\Core\Lock::acquire(%s) ? "acquired" : "blocked";',
            var_export(BASE_PATH, true),
            var_export($this->name, true)
        );

        $out = shell_exec(escapeshellcmd(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');

        $this->assertSame('blocked', trim((string) $out), 'a concurrent process must not get the lock');
    }
}
