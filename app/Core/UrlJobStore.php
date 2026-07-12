<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Background URL-upload job state store: one small JSON file per job id
 * under storage/jobs/url/, so a client can poll progress ({status, pct,
 * downloaded, total}) for a long-running "fetch this URL and store it"
 * operation without holding the request open. Self-cleaning: create()
 * sweeps files older than TTL on every call.
 */
final class UrlJobStore
{
    private const TTL = 3600;

    private static function dir(): string
    {
        $d = (string) config('urljobs.dir', base_path('storage/jobs/url'));
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        return rtrim($d, '/') . '/';
    }

    private static function path(string $jobId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', $jobId);
        if ($safe === null || $safe === '') {
            throw new \InvalidArgumentException('Invalid job ID');
        }
        return self::dir() . $safe . '.json';
    }

    public static function create(string $jobId): void
    {
        self::write($jobId, ['status' => 'pending', 'downloaded' => 0, 'total' => 0, 'pct' => 0, 'created_at' => time()]);
        self::cleanup();
    }

    /** @param array<string, mixed> $data */
    public static function write(string $jobId, array $data): void
    {
        $data['ts'] = time();
        file_put_contents(self::path($jobId), (string) json_encode($data), LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    public static function read(string $jobId): ?array
    {
        $path = self::path($jobId);
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $fileData */
    public static function finish(string $jobId, array $fileData): void
    {
        self::write($jobId, ['status' => 'done', 'pct' => 100, 'file' => $fileData]);
    }

    public static function fail(string $jobId, string $error): void
    {
        self::write($jobId, ['status' => 'error', 'error' => $error]);
    }

    private static function cleanup(): void
    {
        // glob() and filemtime() are not atomic — a parallel worker may delete a
        // file between the directory scan and the stat call. Guard with
        // file_exists() and suppress the residual race with @filemtime() so no
        // PHP warning is emitted when two workers clean up at the same time.
        foreach (glob(self::dir() . '*.json') ?: [] as $f) {
            if (!file_exists($f)) {
                continue;
            }
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < time() - self::TTL) {
                @unlink($f);
            }
        }
    }
}
