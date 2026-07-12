<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Process-wide single-instance guard backed by flock. acquire() returns false
 * immediately when another process already holds the named lock, so a command
 * can no-op instead of piling up across cron ticks (the classic failure where a
 * slow 5-minute job overlaps itself until the box falls over).
 *
 *     if (!Lock::acquire('scrape:auto')) {
 *         return self::SUCCESS; // already running — this tick is a no-op
 *     }
 *
 * The handle is held for the process lifetime and the OS releases it on exit,
 * so a killed worker never leaves a stale lock behind. If the lock file cannot
 * be opened at all (read-only FS, permissions) we FAIL OPEN and let the work
 * run — a broken lock directory must not silently stop every scheduled job.
 */
final class Lock
{
    /**
     * Open lock file handles, held for the process lifetime.
     *
     * Typed `mixed` rather than `resource`: this namespace also contains a
     * Resource class, and a `resource` phpdoc type here resolves to THAT class
     * instead of the pseudo-type. The is_resource() guards below are what
     * actually narrow it — and they double as protection against a handle that
     * was closed underneath us.
     *
     * @var array<string, mixed>
     */
    private static array $handles = [];

    /** True when the lock is now held by this process. */
    public static function acquire(string $name): bool
    {
        if (self::held($name)) {
            return true; // already ours
        }

        $dir = storage_path('locks');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true; // fail open — can't lock, don't block the work
        }

        $file = $dir . '/' . self::safeName($name) . '.lock';
        $fh = @fopen($file, 'c');
        if ($fh === false) {
            return true; // fail open
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false; // someone else holds it
        }

        self::$handles[$name] = $fh; // hold for the process lifetime
        return true;
    }

    /**
     * Release a lock early (before process exit). Rarely needed — mainly for
     * long-lived workers that hand a lock off, and for tests.
     */
    public static function release(string $name): void
    {
        $fh = self::$handles[$name] ?? null;
        unset(self::$handles[$name]);

        if (!is_resource($fh)) {
            return;
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** True when this process currently holds the named lock. */
    public static function held(string $name): bool
    {
        return is_resource(self::$handles[$name] ?? null);
    }

    private static function safeName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_.-]/i', '_', $name);
    }
}
