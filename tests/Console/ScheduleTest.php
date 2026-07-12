<?php
declare(strict_types=1);

namespace Tests\Console;

use App\Console\Schedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testDefineShipsEmpty(): void
    {
        $this->assertSame([], Schedule::define()->all());
    }

    public function testCommandRegistersATaskAndReturnsIt(): void
    {
        $s = new Schedule();
        $task = $s->command('queue:work');
        $this->assertSame('queue:work', $task->command());
        $this->assertCount(1, $s->all());
    }

    public function testDueFiltersByFrequency(): void
    {
        $s = new Schedule();
        $s->command('every-min')->everyMinute();
        $s->command('daily-2am')->dailyAt(2, 0);

        $at2am = new DateTimeImmutable('2024-01-01 02:00:00');
        $due = $s->due($at2am);
        $names = array_map(fn ($t) => $t->command(), $due);
        $this->assertSame(['every-min', 'daily-2am'], $names);

        $at3am = new DateTimeImmutable('2024-01-01 03:00:00');
        $due = $s->due($at3am);
        $this->assertSame(['every-min'], array_map(fn ($t) => $t->command(), $due));
    }
}
