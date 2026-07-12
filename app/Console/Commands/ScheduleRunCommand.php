<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Schedule;
use App\Core\Logger;
use App\Support\PhpCli;
use DateTimeImmutable;

/**
 * Runs the tasks due this minute (Schedule::define()). Invoke every minute
 * from cron — the ONLY crontab line the app needs. Each due task runs as an
 * isolated subprocess so a failing job can't abort the rest of the tick;
 * results are logged (schedule.ran / schedule.failed) when Logger is
 * available.
 *
 * Concurrency guard: if a task's lockfile shows it's still running from the
 * previous tick, that task is skipped — preventing pile-ups when a task
 * takes longer than its interval.
 */
final class ScheduleRunCommand implements Command
{
    private string $lockDir;

    public function __construct()
    {
        $this->lockDir = base_path('storage/run/locks');
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0775, true);
        }
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Run scheduled tasks due this minute (invoke every minute from cron).';
    }

    /**
     * Attempt to acquire a lock for $taskName. Returns true if the lock was
     * acquired (caller should run the task), false if another instance is alive.
     */
    private function acquireLock(string $taskName): bool
    {
        $pidFile = $this->lockDir . '/' . strtr($taskName, ':', '_') . '.pid';
        if (is_file($pidFile)) {
            $raw = @file_get_contents($pidFile);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['pid'])) {
                    $pid = (int) $data['pid'];
                    if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
                        return false; // still alive -> skip
                    }
                }
            }
            @unlink($pidFile);
        }
        file_put_contents($pidFile, (string) json_encode(['pid' => getmypid(), 'started_at' => time()], JSON_UNESCAPED_SLASHES));
        return true;
    }

    private function releaseLock(string $taskName): void
    {
        $pidFile = $this->lockDir . '/' . strtr($taskName, ':', '_') . '.pid';
        @unlink($pidFile);
    }

    public function run(array $args): int
    {
        $now     = new DateTimeImmutable('now');
        $console = base_path('setup/bin/console');
        $due     = Schedule::define()->due($now);

        foreach ($due as $task) {
            $taskName = $task->command();

            if (!$this->acquireLock($taskName)) {
                if (class_exists(Logger::class)) {
                    Logger::info('schedule.skipped', ['task' => $taskName, 'reason' => 'already_running']);
                }
                fwrite(STDOUT, "schedule:run - {$taskName} skipped (already running)\n");
                continue;
            }

            // Task commands are developer-defined in Schedule::define() (static code)
            // and may legitimately contain shell syntax (pipes, redirects). They are
            // NOT derived from user input, so command() is intentionally passed
            // unescaped to preserve that syntax.
            $cmd   = escapeshellarg(PhpCli::path()) . ' ' . escapeshellarg($console) . ' ' . $task->command() . ' 2>&1';
            $out   = [];
            $code  = 0;
            $start = microtime(true);
            exec($cmd, $out, $code);
            $ms = (int) round((microtime(true) - $start) * 1000);

            $this->releaseLock($taskName);

            if (class_exists(Logger::class)) {
                if ($code === 0) {
                    Logger::info('schedule.ran', ['task' => $taskName, 'ms' => $ms]);
                } else {
                    Logger::error('schedule.failed', [
                        'task' => $taskName,
                        'exit' => $code,
                        'ms'   => $ms,
                        'out'  => implode("\n", array_slice($out, -5)),
                    ]);
                }
            }
        }

        fwrite(STDOUT, 'schedule:run - ' . count($due) . ' due task(s) at ' . $now->format('Y-m-d H:i') . "\n");
        return 0;
    }
}
