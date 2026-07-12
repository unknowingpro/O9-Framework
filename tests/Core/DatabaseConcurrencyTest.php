<?php
declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Real OS-level concurrency, not phpunit-in-one-process — spawns actual
 * `php` subprocesses that all reserve jobs from the SAME on-disk sqlite
 * file at once. This is what caught a real bug: PDO's beginTransaction()
 * issues a plain (deferred) BEGIN, which doesn't request SQLite's write
 * lock until the first write statement runs. When several connections all
 * defer-begin and then all try to write within the same instant, the
 * losers got an immediate "database is locked" PDOException — and this
 * was NOT fixed by adding PRAGMA busy_timeout alone; busy_timeout doesn't
 * reliably cover that specific deferred-upgrade race. Only switching the
 * outermost transaction to BEGIN IMMEDIATE (see Database::beginWrite())
 * made every worker wait for the lock instead of crashing.
 *
 * A single-process phpunit test cannot reproduce this: PHP has no real
 * threads, so two "concurrent" calls in one process are never actually
 * simultaneous at the OS/SQLite level.
 */
final class DatabaseConcurrencyTest extends TestCase
{
    private string $dbPath;
    private string $workerScript;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/o9-db-concurrency-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->workerScript = sys_get_temp_dir() . '/o9-db-concurrency-worker-' . bin2hex(random_bytes(4)) . '.php';

        $basePath = dirname(__DIR__, 2);
        file_put_contents($this->workerScript, <<<PHP
            <?php
            define('BASE_PATH', '{$basePath}');
            require BASE_PATH . '/vendor/autoload.php';
            putenv('DB_DRIVER=sqlite');
            putenv('DB_DATABASE={$this->dbPath}');
            require BASE_PATH . '/config/env.php';

            \$claimed = [];
            while (true) {
                \$job = App\\Core\\Queue::reserve();
                if (\$job === null) {
                    break;
                }
                usleep(random_int(1000, 4000)); // force real interleaving between workers
                \$claimed[] = (int) \$job['id'];
            }
            fwrite(STDOUT, json_encode(\$claimed));
            PHP);

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec((string) file_get_contents($basePath . '/setup/database/migrations/010_jobs.sqlite.sql'));
    }

    protected function tearDown(): void
    {
        @unlink($this->workerScript);
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            @unlink($f);
        }
    }

    public function testConcurrentWorkersNeverDoubleClaimOrCrashOnARealSharedSqliteFile(): void
    {
        $jobCount = 150;
        $workerCount = 6;

        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->exec('PRAGMA busy_timeout = 5000;');
        $stmt = $seed->prepare('INSERT INTO jobs (queue, job, payload, attempts, available_at, reserved_at, created_at) VALUES (?, ?, ?, 0, ?, NULL, ?)');
        $now = time();
        for ($i = 0; $i < $jobCount; $i++) {
            $stmt->execute(['default', 'App\\Jobs\\DispatchEventJob', '{}', $now, $now]);
        }

        $processes = [];
        $pipes = [];
        for ($w = 0; $w < $workerCount; $w++) {
            $proc = proc_open(
                ['php', $this->workerScript],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipe
            );
            $this->assertNotFalse($proc);
            $processes[] = $proc;
            $pipes[] = $pipe;
        }

        $allClaimed = [];
        $stderrOutput = '';
        foreach ($processes as $i => $proc) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderrOutput .= stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($proc);

            $ids = json_decode((string) $stdout, true);
            $this->assertIsArray($ids, "worker {$i} produced non-JSON output: {$stdout}");
            $allClaimed = array_merge($allClaimed, $ids);
        }

        $this->assertSame('', trim($stderrOutput), 'no worker should crash with an uncaught exception');
        $this->assertCount($jobCount, $allClaimed, 'every seeded job must be claimed exactly once — none lost');
        $this->assertCount($jobCount, array_unique($allClaimed), 'no job id may be claimed by more than one worker');
    }
}
