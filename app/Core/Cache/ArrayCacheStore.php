<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * In-process cache (per request / CLI run). The safe default + the fallback when
 * Redis is configured but unreachable. TTLs are honoured within the process.
 */
final class ArrayCacheStore implements CacheStore
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        $it = $this->items[$key] ?? null;
        if ($it === null) {
            return null;
        }
        if ($it['expires'] !== null && $it['expires'] < time()) {
            unset($this->items[$key]);
            return null;
        }
        return $it['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->items[$key] = [
            'value'   => $value,
            'expires' => $ttl !== null ? time() + $ttl : null,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function has(string $key): bool
    {
        $it = $this->items[$key] ?? null;
        if ($it === null) { return false; }
        if ($it['expires'] !== null && $it['expires'] < time()) {
            unset($this->items[$key]);
            return false;
        }
        return true;
    }

    public function increment(string $key, int $by = 1): int
    {
        $cur = (int) ($this->get($key) ?? 0);
        $new = $cur + $by;
        // preserve existing expiry if any
        $exp = $this->items[$key]['expires'] ?? null;
        $this->items[$key] = ['value' => $new, 'expires' => $exp];
        return $new;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
