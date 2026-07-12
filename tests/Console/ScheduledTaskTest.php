<?php
declare(strict_types=1);

namespace Tests\Console;

use App\Console\ScheduledTask;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskTest extends TestCase
{
    public function testInertUntilAFrequencyIsSet(): void
    {
        $t = new ScheduledTask('noop');
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-01 00:00:00')));
    }

    public function testEveryMinuteIsAlwaysDue(): void
    {
        $t = (new ScheduledTask('x'))->everyMinute();
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-06-15 13:47:00')));
    }

    public function testEveryMinutesRespectsTheInterval(): void
    {
        $t = (new ScheduledTask('x'))->everyMinutes(15);
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 00:15:00')));
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-01 00:07:00')));
    }

    public function testEveryMinutesClampsToAtLeastOne(): void
    {
        $t = (new ScheduledTask('x'))->everyMinutes(0);
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 00:01:00'))); // n=0 clamped to 1
    }

    public function testHourlyFiresOnlyAtMinuteZero(): void
    {
        $t = (new ScheduledTask('x'))->hourly();
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 05:00:00')));
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-01 05:01:00')));
    }

    public function testHourlyAtFiresOnTheGivenMinuteOfEveryHour(): void
    {
        $t = (new ScheduledTask('x'))->hourlyAt(30);
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 11:30:00')));
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-01 11:31:00')));
    }

    public function testDailyAtFiresOnceADay(): void
    {
        $t = (new ScheduledTask('x'))->dailyAt(2, 30);
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 02:30:00')));
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-02 02:31:00')));
    }

    public function testWeeklyOnFiresOnlyOnTheGivenDayHourMinute(): void
    {
        // 2024-01-01 is a Monday (w=1).
        $t = (new ScheduledTask('x'))->weeklyOn(1, 9, 0);
        $this->assertTrue($t->isDue(new DateTimeImmutable('2024-01-01 09:00:00')));
        $this->assertFalse($t->isDue(new DateTimeImmutable('2024-01-02 09:00:00')));
    }

    public function testCommandReturnsTheOriginalString(): void
    {
        $t = new ScheduledTask('queue:work default 50');
        $this->assertSame('queue:work default 50', $t->command());
    }
}
