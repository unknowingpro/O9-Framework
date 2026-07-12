<?php
declare(strict_types=1);

namespace App\Console;

use DateTimeImmutable;

/**
 * Declarative, in-code task schedule — the single source of truth for
 * recurring background work. `schedule:run` (one crontab line, every
 * minute) runs the tasks due this minute. To add/retime a job, edit
 * define() below; the crontab stays one line, so it can't drift out of
 * sync again.
 *
 * Crontab (install once) — absolute paths, no `cd` (console derives
 * BASE_PATH from its own path):
 *   * * * * * /usr/bin/php /path/to/app/setup/bin/console schedule:run >> /path/to/app/storage/logs/cron.log 2>&1
 *
 * Ships empty — apps fill define() with their own $s->command(...) calls.
 */
final class Schedule
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function command(string $command): ScheduledTask
    {
        $task = new ScheduledTask($command);
        $this->tasks[] = $task;
        return $task;
    }

    /** @return list<ScheduledTask> tasks due at $now (minute granularity). */
    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->tasks, static fn (ScheduledTask $t): bool => $t->isDue($now)));
    }

    /** @return list<ScheduledTask> */
    public function all(): array
    {
        return $this->tasks;
    }

    /** The application's schedule. Example: $s->command('queue:work')->everyMinute(); */
    public static function define(): self
    {
        return new self();
    }
}
