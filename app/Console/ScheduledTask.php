<?php
declare(strict_types=1);

namespace App\Console;

use DateTimeImmutable;

/**
 * One scheduled console task + its frequency. Frequencies are PURE functions
 * of the wall clock at minute granularity, so a once-a-minute cron
 * (`schedule:run`) fires each task exactly when due — no last-run state
 * needed. The command string is a console invocation, e.g. "queue:work
 * default 200" or "cache:clear".
 */
final class ScheduledTask
{
    /** @var callable(DateTimeImmutable): bool */
    private $due;

    public function __construct(private readonly string $command)
    {
        $this->due = static fn (): bool => false; // inert until a frequency is set
    }

    public function command(): string
    {
        return $this->command;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return (bool) ($this->due)($now);
    }

    public function everyMinute(): self
    {
        $this->due = static fn (): bool => true;
        return $this;
    }

    public function everyMinutes(int $n): self
    {
        $n = max(1, $n);
        $this->due = static fn (DateTimeImmutable $t): bool => ((int) $t->format('i')) % $n === 0;
        return $this;
    }

    public function hourly(): self
    {
        $this->due = static fn (DateTimeImmutable $t): bool => (int) $t->format('i') === 0;
        return $this;
    }

    public function hourlyAt(int $minute): self
    {
        $this->due = static fn (DateTimeImmutable $t): bool => (int) $t->format('i') === $minute;
        return $this;
    }

    public function dailyAt(int $hour, int $minute = 0): self
    {
        $this->due = static fn (DateTimeImmutable $t): bool => (int) $t->format('G') === $hour && (int) $t->format('i') === $minute;
        return $this;
    }

    public function weeklyOn(int $dayOfWeek, int $hour, int $minute = 0): self
    {
        $this->due = static fn (DateTimeImmutable $t): bool =>
            (int) $t->format('w') === $dayOfWeek && (int) $t->format('G') === $hour && (int) $t->format('i') === $minute;
        return $this;
    }
}
