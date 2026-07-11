<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * Cache facade with pluggable backends.
 *
 *   Cache::remember('feed:home:42', 60, fn() => $svc->buildFeed(42));
 *   Cache::increment('post:99:likes');
 *
 * Resolves the store from config('cache.driver'): 'redis' | 'file' | 'array'.
 * `redis` is used only when ext-redis is present AND the server connects;
 * otherwise it transparently degrades to the persistent file store, so
 * callers never need to guard.
 */
final class Cache
{
    private static ?CacheStore $store = null;

    public static function store(): CacheStore
    {
        if (self::$store !== null) {
            return self::$store;
        }
        $driver = (string) config('cache.driver', 'array');
        if ($driver === 'redis') {
            $redis = RedisConnection::get();
            if ($redis !== null) {
                return self::$store = new RedisCacheStore(
                    $redis,
                    (string) config('cache.prefix', 'o9:'),
                );
            }
            // configured for redis but unreachable → degrade, don't fail
            RedisConnection::warnFallback('cache');
            return self::$store = new FileCacheStore();
        }
        return self::$store = $driver === 'file' ? new FileCacheStore() : new ArrayCacheStore();
    }

    /** Override the store (tests). */
    public static function setStore(?CacheStore $store): void
    {
        self::$store = $store;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::store()->has($key)) {
            return $default;
        }
        return self::store()->get($key);
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): void
    {
        self::store()->set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }

    public static function forget(string $key): void
    {
        self::store()->delete($key);
    }

    public static function increment(string $key, int $by = 1): int
    {
        return self::store()->increment($key, $by);
    }

    public static function flush(): void
    {
        self::store()->flush();
    }

    /**
     * Return the cached value or compute → store → return it. $ttl null uses the
     * configured default TTL. The cache-aside pattern for feed/profile/count reads.
     */
    public static function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        if (self::store()->has($key)) {
            return self::store()->get($key);
        }
        $value = $callback();
        if ($value !== null) {
            self::store()->set($key, $value, $ttl ?? (int) config('cache.default_ttl', 3600));
        }
        return $value;
    }
}
