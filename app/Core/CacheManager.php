<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Cache\Cache;
use App\Core\Cache\CacheStore;

/**
 * Cache abstraction — the spec-named entry point over the pluggable stores in
 * Core/Cache/. Identical API to the Cache\Cache facade; projects use either
 * name (`CacheManager::remember(...)` reads better in app code, the Cache\*
 * namespace holds the machinery).
 */
final class CacheManager
{
    public static function store(): CacheStore
    {
        return Cache::store();
    }

    /** Override the store (tests). */
    public static function setStore(?CacheStore $store): void
    {
        Cache::setStore($store);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($key, $default);
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): void
    {
        Cache::set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return Cache::has($key);
    }

    public static function forget(string $key): void
    {
        Cache::forget($key);
    }

    public static function increment(string $key, int $by = 1): int
    {
        return Cache::increment($key, $by);
    }

    public static function flush(): void
    {
        Cache::flush();
    }

    /** Cached value, or compute → store → return. Null $ttl uses the configured default. */
    public static function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return Cache::remember($key, $ttl, $callback);
    }
}
