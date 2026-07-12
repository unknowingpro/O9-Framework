<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\ScheduleRunCommand;
use PHPUnit\Framework\TestCase;

final class ScheduleRunCommandTest extends TestCase
{
    public function testExitsZeroWithTheDefaultEmptySchedule(): void
    {
        // Schedule::define() ships empty until an app fills it in, so there's
        // never anything due — this exercises the full run() path (lock dir
        // creation, due-task loop with zero iterations) without spawning any
        // subprocess.
        $exit = (new ScheduleRunCommand())->run([]);
        $this->assertSame(0, $exit);
    }

    public function testCreatesTheLockDirectory(): void
    {
        $lockDir = base_path('storage/run/locks');
        new ScheduleRunCommand();
        $this->assertDirectoryExists($lockDir);
    }
}
