<?php
declare(strict_types=1);

namespace Tests\Support\Worker;

use App\Support\Worker\Heartbeat;
use App\Support\Worker\WorkerRuntime;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-worker-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testShouldRecycleHonoursEveryLimit(): void
    {
        $now = 1_000.0;
        // No limits configured → never recycle.
        $this->assertFalse(WorkerRuntime::shouldRecycle(999, 0.0, PHP_INT_MAX, [], $now));
        // Iteration ceiling.
        $this->assertTrue(WorkerRuntime::shouldRecycle(10, $now, 0, ['maxIterations' => 10], $now));
        $this->assertFalse(WorkerRuntime::shouldRecycle(9, $now, 0, ['maxIterations' => 10], $now));
        // Runtime ceiling.
        $this->assertTrue(WorkerRuntime::shouldRecycle(1, $now - 61, 0, ['maxRuntimeSeconds' => 60], $now));
        $this->assertFalse(WorkerRuntime::shouldRecycle(1, $now - 59, 0, ['maxRuntimeSeconds' => 60], $now));
        // Memory ceiling.
        $this->assertTrue(WorkerRuntime::shouldRecycle(1, $now, 512, ['maxMemoryBytes' => 512], $now));
        $this->assertFalse(WorkerRuntime::shouldRecycle(1, $now, 511, ['maxMemoryBytes' => 512], $now));
    }

    public function testNonDaemonRunsExactlyOneTickAndWritesAHeartbeat(): void
    {
        $ticks = 0;
        $rt = new WorkerRuntime('unit', ['runDir' => $this->dir]);
        $exit = $rt->run(function () use (&$ticks): array {
            $ticks++;
            return ['ok' => 2, 'fail' => 1, 'processed' => 3];
        }, false);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $ticks);
        $hb = Heartbeat::read('unit', $this->dir);
        $this->assertNotNull($hb);
        $this->assertSame(1, $hb['iterations']);
        $this->assertSame(2, $hb['last_ok']);
        $this->assertSame(1, $hb['last_fail']);
    }

    public function testDaemonRecyclesAtMaxIterationsWithoutSleepingForever(): void
    {
        $ticks = 0;
        $rt = new WorkerRuntime('recycler', [
            'runDir'        => $this->dir,
            'maxIterations' => 3,
            'sleepSeconds'  => 0,
        ]);
        $rt->run(function () use (&$ticks): array {
            $ticks++;
            return ['ok' => 1];
        }, true);

        $this->assertSame(3, $ticks);
        $hb = Heartbeat::read('recycler', $this->dir);
        $this->assertNotNull($hb);
        $this->assertSame(3, $hb['iterations']);
        $this->assertSame(3, $hb['last_ok']);
    }

    public function testATickExceptionCountsAsFailureAndTheLoopSurvives(): void
    {
        $ticks = 0;
        $rt = new WorkerRuntime('flaky', [
            'runDir'        => $this->dir,
            'maxIterations' => 2,
            'sleepSeconds'  => 0,
        ]);
        $rt->run(function () use (&$ticks): array {
            $ticks++;
            if ($ticks === 1) {
                throw new \RuntimeException('transient failure');
            }
            return ['ok' => 1];
        }, true);

        $this->assertSame(2, $ticks); // survived the first-tick explosion
        $hb = Heartbeat::read('flaky', $this->dir);
        $this->assertNotNull($hb);
        $this->assertSame(1, $hb['last_fail']);
        $this->assertSame(1, $hb['last_ok']);
    }

    public function testSingleInstanceLockPreventsASecondRunner(): void
    {
        // Hold the lock the way a live daemon would.
        $lock = fopen($this->dir . '/solo.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $ticks = 0;
        $rt = new WorkerRuntime('solo', ['runDir' => $this->dir, 'singleInstance' => true]);
        $exit = $rt->run(function () use (&$ticks): array {
            $ticks++;
            return [];
        }, false);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $ticks); // never ticked — another instance owns the lock

        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
