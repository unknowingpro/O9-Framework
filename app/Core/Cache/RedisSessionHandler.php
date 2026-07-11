<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * Stores PHP sessions in Redis so the app tier can run stateless behind a load
 * balancer. Registered by App::startSession() only when the session driver is
 * redis and a Redis connection is available; otherwise the app keeps using
 * native file sessions.
 *
 * Keys: "<prefix>sess:<id>", TTL = configured session lifetime, refreshed on
 * every write so active sessions don't expire mid-use.
 */
final class RedisSessionHandler implements \SessionHandlerInterface
{
    private string $keyPrefix;

    public function __construct(
        private readonly \Redis $redis,
        private readonly int $ttl = 1209600,        // 14 days
        string $prefix = 'o9:',
    ) {
        $this->keyPrefix = $prefix . 'sess:';
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $data = $this->redis->get($this->keyPrefix . $id);
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->redis->setex($this->keyPrefix . $id, $this->ttl, $data);
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->keyPrefix . $id);
        return true;
    }

    /** Redis TTL handles expiry, so GC is a no-op. */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }
}
