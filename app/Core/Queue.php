<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Queue — DB-backed background-job queue (zero-dependency).
 *
 * push() enqueues a Job class + JSON payload. A worker (`console queue:work`)
 * reserves the oldest available, unreserved row (incrementing attempts up front
 * so a crash mid-handle still counts), runs handle(), deletes on success,
 * releases with linear backoff on failure, and buries after MAX_ATTEMPTS. The
 * claim is atomic (conditional UPDATE inside a transaction) so concurrent
 * workers never double-run a job.
 *
 * Timestamps are unix ints. Buried jobs are left reserved (never re-reserved)
 * for inspection — nothing is deleted destructively here except a job row on
 * SUCCESS, which carries no user data.
 */
final class Queue
{
    public const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = 60;

    /** @param array<string,mixed> $payload @return int job id */
    public static function push(string $jobClass, array $payload = [], int $delaySeconds = 0, string $queue = 'default'): int
    {
        $now = time();
        $db  = Database::getInstance();
        $db->raw(
            'INSERT INTO jobs (queue, job, payload, attempts, available_at, reserved_at, created_at) VALUES (?, ?, ?, 0, ?, NULL, ?)',
            [$queue, $jobClass, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now + max(0, $delaySeconds), $now]
        );
        return (int) $db->pdo()->lastInsertId();
    }

    /**
     * Atomically claim the next runnable job (incrementing attempts).
     *
     * @return array<string,mixed>|null
     */
    public static function reserve(string $queue = 'default'): ?array
    {
        $db  = Database::getInstance();
        $now = time();
        /** @var array<string,mixed>|null */
        return $db->transaction(function () use ($db, $queue, $now): ?array {
            $row = $db->raw(
                'SELECT * FROM jobs WHERE queue = ? AND reserved_at IS NULL AND available_at <= ? ORDER BY id LIMIT 1',
                [$queue, $now]
            )->fetch();
            if (!is_array($row)) {
                return null;
            }
            $db->raw(
                'UPDATE jobs SET reserved_at = ?, attempts = attempts + 1 WHERE id = ? AND reserved_at IS NULL',
                [$now, (int) $row['id']]
            );
            $row['attempts']    = (int) $row['attempts'] + 1;
            $row['reserved_at'] = $now;
            return $row;
        });
    }

    /** Process up to $max runnable jobs. @return int number processed (succeeded or failed). */
    public static function work(int $max = PHP_INT_MAX, string $queue = 'default'): int
    {
        $processed = 0;
        while ($processed < $max) {
            $job = self::reserve($queue);
            if ($job === null) {
                break;
            }
            try {
                $class    = (string) $job['job'];
                $instance = new $class();
                if (!$instance instanceof Job) {
                    throw new \RuntimeException($class . ' is not a Job');
                }
                $payload = json_decode((string) $job['payload'], true);
                $instance->handle(is_array($payload) ? $payload : []);
                self::delete((int) $job['id']);
            } catch (\Throwable $e) {
                self::failed($job, $e);
            }
            $processed++;
        }
        return $processed;
    }

    public static function size(string $queue = 'default'): int
    {
        $row = Database::getInstance()->raw('SELECT COUNT(*) c FROM jobs WHERE queue = ?', [$queue])->fetch();
        return (int) (is_array($row) ? ($row['c'] ?? 0) : 0);
    }

    /** Delete a job row on SUCCESS (no user data — this is a transient work item). */
    private static function delete(int $id): void
    {
        Database::getInstance()->raw('DELETE FROM jobs WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $job */
    private static function failed(array $job, \Throwable $e): void
    {
        $attempts = (int) $job['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            // Buried: leave reserved (won't be re-reserved) for inspection.
            if (class_exists(Logger::class)) {
                Logger::error('queue.job.buried', ['job' => $job['job'], 'id' => $job['id'], 'attempts' => $attempts, 'error' => $e->getMessage()]);
            }
            return;
        }
        $retryAt = time() + $attempts * self::BACKOFF_SECONDS;
        Database::getInstance()->raw('UPDATE jobs SET reserved_at = NULL, available_at = ? WHERE id = ?', [$retryAt, (int) $job['id']]);
    }
}
