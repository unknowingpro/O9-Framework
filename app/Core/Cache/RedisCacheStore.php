<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * Redis-backed cache. Values are PHP-serialized arrays/scalars (objects are not cached); reads
 * deserialize with allowed_classes=false so a tampered/poisoned value can't trigger object injection.
 * Keys are namespaced with the configured prefix. Counters use Redis INCRBY so
 * `increment()` is atomic across app nodes — the basis for write-behind
 * counters at scale.
 */
final class RedisCacheStore implements CacheStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'o9:',
    ) {}

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->k($key));
        if ($raw === false) {
            return null;
        }
        // allowed_classes=false: cache holds app-written arrays/scalars only, so refuse to
        // instantiate any class on read — a poisoned Redis value can't trigger object injection.
        $val = @unserialize((string) $raw, ['allowed_classes' => false]);
        if ($val === false && $raw !== serialize(false)) {
            // Counters written via increment() use Redis INCRBY, which stores a plain
            // integer string instead of a serialized payload. Surface a canonical
            // integer as an int so increment()/get() round-trips like ArrayCacheStore;
            // anything else that fails to unserialize is treated as a miss.
            return (string) (int) $raw === $raw ? (int) $raw : null;
        }
        return $val;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $raw = serialize($value);
        if ($ttl !== null && $ttl > 0) {
            $this->redis->setex($this->k($key), $ttl, $raw);
        } else {
            $this->redis->set($this->k($key), $raw);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->k($key));
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->k($key));
    }

    public function increment(string $key, int $by = 1): int
    {
        return (int) $this->redis->incrBy($this->k($key), $by);
    }

    public function flush(): void
    {
        // Scoped flush: only keys under our prefix (never FLUSHDB the whole server).
        $it = null;
        $pattern = $this->prefix . '*';
        do {
            $keys = $this->redis->scan($it, $pattern, 500);
            if ($keys !== false && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($it > 0);
    }
}
