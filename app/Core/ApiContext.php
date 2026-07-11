<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-request API context: a correlation id (X-Request-Id, taken from the client or generated),
 * request timing, and lightweight request/error counters. begin() is called before dispatch and
 * finish() from a shutdown handler, so the final status + duration are captured even though
 * Response methods exit(). Logger stamps every line with the id, and Response echoes it back, so a
 * client error can be traced end-to-end across logs.
 */
final class ApiContext
{
    private static ?string $id = null;
    private static float $start = 0.0;
    private static bool $done = false;

    public static function begin(?string $incomingId = null, ?float $startedAt = null): void
    {
        self::$id = ($incomingId !== null && self::valid($incomingId)) ? $incomingId : self::generate();
        self::$start = $startedAt ?? microtime(true);
        self::$done = false;
    }

    public static function id(): string
    {
        return self::$id ?? '';
    }

    public static function active(): bool
    {
        return self::$id !== null;
    }

    public static function elapsedMs(): int
    {
        return self::$start > 0.0 ? (int) round((microtime(true) - self::$start) * 1000) : 0;
    }

    /** Increment the request counters for a finished response (total + 4xx/5xx buckets). */
    public static function record(int $status): void
    {
        if (!class_exists(Cache\Cache::class)) {
            return; // counters need the cache subsystem; nothing to record without it
        }
        Cache\Cache::increment('api.req.total');
        if ($status >= 500) {
            Cache\Cache::increment('api.req.error_5xx');
        } elseif ($status >= 400) {
            Cache\Cache::increment('api.req.error_4xx');
        }
    }

    /** Shutdown handler: count + structured-log the finished request. Runs at most once. */
    public static function finish(): void
    {
        if (self::$done || self::$id === null) {
            return;
        }
        self::$done = true;
        $status = (int) (http_response_code() ?: 200);
        try {
            self::record($status);
            if (class_exists(Logger::class)) {
                Logger::info('api.request', [
                    'rid'    => self::$id,
                    'status' => $status,
                    'ms'     => self::elapsedMs(),
                    'uid'    => class_exists(Auth::class) ? Auth::id() : null,
                ]);
            }
        } catch (\Throwable) {
            // Never let request logging break the response.
        }
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$id = null;
        self::$start = 0.0;
        self::$done = false;
    }

    private static function valid(string $id): bool
    {
        return $id !== '' && strlen($id) <= 200 && preg_match('/^[A-Za-z0-9._-]+$/', $id) === 1;
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
