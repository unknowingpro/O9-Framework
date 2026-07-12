<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\Cache\RedisConnection;
use App\Core\HttpException;
use App\Core\HttpResponse;
use App\Core\Request;
use App\Middleware\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = [];
        foreach (glob(base_path('storage/data/ratelimit/*.json')) ?: [] as $f) {
            @unlink($f);
        }
        // RateLimit prefers Redis (see class docblock) when it's reachable —
        // an environment with Redis running must clear its buckets too, or a
        // later test/run reusing the same ip+path inherits a stale count and
        // fails with a false "too many attempts" inside the fixed window.
        $redis = RedisConnection::get();
        if ($redis !== null) {
            $prefix = rtrim((string) config('cache.prefix', 'o9:'), ':') . '_rl:';
            foreach ($redis->keys($prefix . '*') as $rkey) {
                $redis->del($rkey);
            }
        }
    }

    private function apiRequest(string $method, string $path, string $ip): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['REMOTE_ADDR']    = $ip;
        return new Request();
    }

    public function testGetRequestsAreNeverLimited(): void
    {
        $rl = new RateLimit(1, 60);
        $req = $this->apiRequest('GET', '/api/v1/x', '203.0.113.1');
        for ($i = 0; $i < 5; $i++) {
            $rl->handle($req);
        }
        $this->addToAssertionCount(1); // never throws
    }

    public function testAllowsUpToTheLimitThenThrowsForJsonRequests(): void
    {
        $rl = new RateLimit(2, 60);
        $ip = '203.0.113.2';
        $rl->handle($this->apiRequest('POST', '/api/v1/limited-a', $ip));
        $rl->handle($this->apiRequest('POST', '/api/v1/limited-a', $ip));

        $this->expectException(HttpException::class);
        try {
            $rl->handle($this->apiRequest('POST', '/api/v1/limited-a', $ip));
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status);
            throw $e;
        }
    }

    public function testDifferentPathsGetIndependentBuckets(): void
    {
        $rl = new RateLimit(1, 60);
        $ip = '203.0.113.3';
        $rl->handle($this->apiRequest('POST', '/api/v1/a', $ip));
        $rl->handle($this->apiRequest('POST', '/api/v1/b', $ip)); // different bucket — not limited yet
        $this->addToAssertionCount(1);
    }

    public function testDifferentIpsGetIndependentBuckets(): void
    {
        $rl = new RateLimit(1, 60);
        $rl->handle($this->apiRequest('POST', '/api/v1/shared', '203.0.113.4'));
        $rl->handle($this->apiRequest('POST', '/api/v1/shared', '203.0.113.5'));
        $this->addToAssertionCount(1);
    }

    public function testScopedLimiterSharesOneBucketAcrossPaths(): void
    {
        $rl = new RateLimit(1, 60, 'global-scope');
        $ip = '203.0.113.6';
        $rl->handle($this->apiRequest('POST', '/api/v1/one', $ip));
        $this->expectException(HttpException::class);
        $rl->handle($this->apiRequest('POST', '/api/v1/two', $ip)); // same scope bucket -> limited
    }

    public function testNonJsonRequestRedirectsInsteadOfThrowingHttpException(): void
    {
        $rl = new RateLimit(1, 60);
        $ip = '203.0.113.7';
        $rl->handle($this->apiRequest('POST', '/web/form', $ip));
        try {
            $rl->handle($this->apiRequest('POST', '/web/form', $ip));
            $this->fail('expected a redirect response to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(302, $r->status);
        }
    }
}
