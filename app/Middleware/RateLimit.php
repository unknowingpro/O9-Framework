<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Cache\RedisConnection;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

/**
 * IP-based fixed-window rate limiter for sensitive endpoints.
 *
 * Counting runs on Redis (atomic INCR + EXPIRE) when it's reachable, so all
 * app nodes share one bucket and requests from an IP never serialize on
 * disk. When Redis is unavailable it transparently degrades to a
 * per-bucket flock'd JSON file under storage/data/ratelimit/ — same
 * contract, no external dependency.
 *
 * Default: 10 requests per 300s per (ip + path). On exceed -> 429 (JSON) or
 * a flash + redirect back (web). Only counts non-GET requests so opening a
 * page doesn't burn the budget.
 *
 * Subclass and override limit()/windowSeconds() for tighter caps (see
 * ThrottleAuth), or instantiate directly with explicit values:
 *   [new RateLimit(30, 60)]  // 30 writes / 60s per ip+path
 *   [new RateLimit(240, 60, 'api')]  // scoped: one shared bucket per ip, ignoring path
 */
class RateLimit implements Middleware
{
    public function __construct(
        private readonly ?int $max = null,
        private readonly ?int $perSeconds = null,
        private readonly ?string $scope = null,
    ) {
    }

    protected function limit(): int { return $this->max ?? 10; }
    protected function windowSeconds(): int { return $this->perSeconds ?? 300; }

    public function handle(Request $request, ?string $arg = null): void
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $key = $this->bucketKey($request);
        $now = time();
        $win = $this->windowSeconds();

        // Atomic increment of this bucket. Redis (INCR+EXPIRE) when reachable —
        // shared across nodes and lock-free; otherwise an exclusive flock on the
        // bucket's own file so concurrent requests can't lose an increment.
        $bucket = $this->rmwBucket($key, $now, $win);

        if ($bucket['count'] > $this->limit()) {
            $retry = max(1, $bucket['reset'] - $now);
            header('Retry-After: ' . $retry);
            if ($request->wantsJson()) {
                throw HttpException::tooManyRequests('Too many attempts. Try again later.');
            }
            Session::flash("Too many attempts. Try again in {$retry}s.", 'error');
            View::redirect(safe_back('/'));
        }
    }

    private function bucketKey(Request $request): string
    {
        // A SCOPED limiter keys per-IP across all paths (a coarse global cap).
        // The default keys per ip+path — distinct from any scoped limiter
        // sharing the chain, so the two never collide.
        return $this->scope !== null
            ? sha1($request->ip() . '|@' . $this->scope)
            : sha1($request->ip() . '|' . $request->path());
    }

    /** One file PER bucket (key is a sha1 hex) so distinct ip+path pairs never lock each other. */
    private function file(string $key): string
    {
        return base_path('storage/data/ratelimit/' . $key . '.json');
    }

    /**
     * Increment this bucket's counter and return its post-increment
     * {count, reset}. Prefers Redis (atomic, node-shared); falls back to the
     * flock'd file store when Redis is unreachable or errors mid-request, so
     * the limiter never fails open just because the cache tier blipped.
     *
     * @return array{count: int, reset: int}
     */
    private function rmwBucket(string $key, int $now, int $win): array
    {
        $redis = RedisConnection::get();
        if ($redis !== null) {
            try {
                return $this->rmwRedis($redis, $key, $now, $win);
            } catch (\Throwable) {
                // Redis hiccup mid-request -> fall through to the file store.
            }
        }
        return $this->rmwFile($key, $now, $win);
    }

    /**
     * Fixed-window counter in Redis: INCR the bucket, and on the first hit of
     * a window set the TTL to the window length. Done in one Lua eval so the
     * INCR/EXPIRE pair is atomic. Returns {count, reset} where reset = now +
     * remaining TTL (drives Retry-After).
     *
     * @return array{count: int, reset: int}
     */
    private function rmwRedis(\Redis $redis, string $key, int $now, int $win): array
    {
        // Namespaced OUTSIDE the cache prefix so a Cache::flush() can never
        // wipe live throttle state.
        $rkey = rtrim((string) config('cache.prefix', 'o9:'), ':') . '_rl:' . $key;
        $lua = <<<'LUA'
        local c = redis.call('INCR', KEYS[1])
        local t = redis.call('TTL', KEYS[1])
        if c == 1 or t < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
            t = tonumber(ARGV[1])
        end
        return {c, t}
        LUA;
        $res   = $redis->eval($lua, [$rkey, (string) $win], 1);
        $count = is_array($res) ? (int) ($res[0] ?? 1) : 1;
        $ttl   = is_array($res) ? (int) ($res[1] ?? $win) : $win;
        return ['count' => $count, 'reset' => $now + max(1, $ttl)];
    }

    /**
     * File fallback: atomic read-modify-write of one bucket. Holds an
     * exclusive flock on THAT bucket's own file (not a shared store) from
     * read through write, so concurrent requests can't lose an increment and
     * unrelated buckets don't contend on a single global lock.
     *
     * @return array{count: int, reset: int}
     */
    private function rmwFile(string $key, int $now, int $win): array
    {
        $f = $this->file($key);
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($f, 'c+');
        if (!$fh) {
            // If we can't open the storage file, fail-open rather than 500ing every request.
            return ['count' => 0, 'reset' => $now + $win];
        }
        flock($fh, LOCK_EX);
        $bucket = json_decode((string) stream_get_contents($fh), true);
        if (!is_array($bucket) || (int) ($bucket['reset'] ?? 0) < $now) {
            $bucket = ['count' => 0, 'reset' => $now + $win];
        }
        $bucket['count'] = (int) $bucket['count'] + 1;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($bucket, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        /** @var array{count: int, reset: int} $bucket */
        return $bucket;
    }
}
