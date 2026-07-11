<?php
declare(strict_types=1);

namespace App\Core\Cache;

/** Pluggable cache backend. Implemented by Array/File/Redis stores. */
interface CacheStore
{
    /** Stored value, or null when missing/expired. */
    public function get(string $key): mixed;

    /** Store $value with optional TTL in seconds (null = no expiry). */
    public function set(string $key, mixed $value, ?int $ttl = null): void;

    public function delete(string $key): void;

    public function has(string $key): bool;

    /** Atomic increment; creates the key at 0 first. Returns the new value. */
    public function increment(string $key, int $by = 1): int;

    /** Drop everything in this store/namespace. */
    public function flush(): void;
}
