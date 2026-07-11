<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * Builds a configured \Redis connection (ext-redis). Shared by the cache store
 * and the session handler. Returns null when ext-redis is missing or the server
 * is unreachable — callers MUST treat null as "no Redis" and fall back, so the
 * app never hard-fails on a Redis outage.
 */
final class RedisConnection
{
    private static ?\Redis $shared = null;
    private static bool $tried = false;

    /** Process-shared connection (one per request). Null when unavailable. */
    public static function get(): ?\Redis
    {
        if (self::$tried) {
            return self::$shared;
        }
        self::$tried = true;
        self::$shared = self::connect((array) config('cache.redis', []));
        return self::$shared;
    }

    /**
     * Open a fresh connection from a config array, or null on any failure.
     *
     * @param array<string, mixed> $conf
     */
    public static function connect(array $conf): ?\Redis
    {
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $redis = new \Redis();
            $ok = $redis->connect(
                (string) ($conf['host'] ?? '127.0.0.1'),
                (int) ($conf['port'] ?? 6379),
                (float) ($conf['timeout'] ?? 1.0),
            );
            if (!$ok) {
                return null;
            }
            if (!empty($conf['password'])) {
                $redis->auth((string) $conf['password']);
            }
            if (!empty($conf['db'])) {
                $redis->select((int) $conf['db']);
            }
            return $redis;
        } catch (\Throwable) {
            // Unreachable / auth failure → behave as "no Redis".
            return null;
        }
    }

    /**
     * Emit a throttled WARNING when a subsystem is configured for Redis but the
     * connection is unavailable, so the silent degradation to array cache / file
     * sessions surfaces in the logs instead of going unnoticed. Throttled to one
     * line per 5 min per subsystem via a marker file, so a sustained outage under
     * load doesn't flood the log (the fallback paths run on every request).
     */
    public static function warnFallback(string $subsystem): void
    {
        $marker = storage_path('logs/.redis-fallback-' . $subsystem . '.warn');
        $last = @filemtime($marker);
        if ($last !== false && (time() - $last) < 300) {
            return;
        }
        @touch($marker);
        if (class_exists(\App\Core\Logger::class)) {
            \App\Core\Logger::warning('Redis configured but unavailable — degraded to fallback', [
                'subsystem' => $subsystem,
                'ext_redis' => extension_loaded('redis'),
                'host'      => (string) config('cache.redis.host', '127.0.0.1'),
                'port'      => (int) config('cache.redis.port', 6379),
            ]);
        }
    }

    /** Test/reset hook. */
    public static function reset(): void
    {
        self::$shared = null;
        self::$tried  = false;
    }
}
