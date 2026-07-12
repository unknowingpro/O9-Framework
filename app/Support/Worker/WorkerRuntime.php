<?php
declare(strict_types=1);

namespace App\Support\Worker;

/**
 * Long-running worker loop: graceful signals, optional single-instance flock,
 * memory/runtime/iteration recycling (clean exit so systemd respawns fresh),
 * and a per-iteration heartbeat. Wraps a worker's existing per-batch body as $tick.
 *
 * Options: singleInstance (bool), sleepSeconds (int), maxIterations (int),
 * maxRuntimeSeconds (int), maxMemoryBytes (int), runDir (string).
 */
final class WorkerRuntime
{
    private bool $stop = false;

    /** @param array<string,mixed> $opts */
    public function __construct(private string $name, private array $opts = [])
    {
    }

    /**
     * Pure: should the daemon exit-to-recycle?
     *
     * @param array<string,mixed> $opts
     */
    public static function shouldRecycle(int $iterations, float $startTs, int $memBytes, array $opts, float $now): bool
    {
        $mi = (int) ($opts['maxIterations'] ?? 0);
        $mr = (int) ($opts['maxRuntimeSeconds'] ?? 0);
        $mm = (int) ($opts['maxMemoryBytes'] ?? 0);
        if ($mi > 0 && $iterations >= $mi) { return true; }
        if ($mr > 0 && ($now - $startTs) >= $mr) { return true; }
        if ($mm > 0 && $memBytes >= $mm) { return true; }
        return false;
    }

    /** @param callable(): mixed $tick returns per-batch stats ['ok' => int, 'fail' => int, 'processed' => int] */
    public function run(callable $tick, bool $daemon): int
    {
        $runDirOpt = isset($this->opts['runDir']) ? (string) $this->opts['runDir'] : null;
        $runDir = Heartbeat::runDir($runDirOpt);
        if (!is_dir($runDir)) { @mkdir($runDir, 0775, true); }

        $lock = null;
        if (!empty($this->opts['singleInstance'])) {
            $fp = fopen($runDir . '/' . $this->name . '.lock', 'c');
            if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
                fwrite(STDOUT, "[{$this->name}] already running; exiting\n");
                if ($fp !== false) { fclose($fp); }
                return 0;
            }
            $lock = $fp; // resource; lock held until run() returns
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->stop = true; });
            pcntl_signal(SIGINT,  function (): void { $this->stop = true; });
        }

        $started = microtime(true);
        $iterations = 0; $totOk = 0; $totFail = 0;

        do {
            $iterations++;
            $stats = ['ok' => 0, 'fail' => 0, 'processed' => 0];
            try {
                $r = $tick();
                if (is_array($r)) { $stats = array_merge($stats, $r); }
            } catch (\Throwable $e) {
                $stats['fail'] = (int) $stats['fail'] + 1;
                fwrite(STDOUT, "[{$this->name}] tick error: " . $e->getMessage() . "\n");
                // A long-running daemon's MySQL connection can be dropped by the
                // server (wait_timeout, restart, killed). PDO then throws
                // "MySQL server has gone away" (2006) / "Lost connection" (2013)
                // on every tick forever — recover by re-opening the connection so
                // the next tick works instead of wedging the daemon.
                $msg = $e->getMessage();
                if (stripos($msg, 'gone away') !== false || stripos($msg, 'Lost connection') !== false
                    || str_contains($msg, '2006') || str_contains($msg, '2013')) {
                    try { \App\Core\Database::getInstance()->reconnect(); fwrite(STDOUT, "[{$this->name}] reconnected to the database\n"); }
                    catch (\Throwable $re) { fwrite(STDOUT, "[{$this->name}] reconnect failed: " . $re->getMessage() . "\n"); }
                }
            }
            $totOk += (int) $stats['ok'];
            $totFail += (int) $stats['fail'];

            Heartbeat::write($this->name, [
                'started_at' => (int) $started,
                'iterations' => $iterations,
                'last_ok'    => $totOk,
                'last_fail'  => $totFail,
                'mem'        => memory_get_usage(true),
            ], $runDirOpt);

            if (!$daemon || $this->stop) { break; }
            if (self::shouldRecycle($iterations, $started, memory_get_usage(true), $this->opts, microtime(true))) {
                fwrite(STDOUT, "[{$this->name}] recycling after {$iterations} iteration(s)\n");
                break;
            }
            $this->interruptibleSleep((int) ($this->opts['sleepSeconds'] ?? 10));
        } while (true); // all exits are via break above (non-daemon, stop signal, or recycle)

        if ($lock !== null) { flock($lock, LOCK_UN); fclose($lock); }
        return 0;
    }

    private function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->stop; $i++) { sleep(1); }
    }
}
