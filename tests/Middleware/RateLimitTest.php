<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\Cache\RedisConnection;
use App\Core\HttpException;
use App\Core\HttpResponse;
use App\Core\Logger;
use App\Core\Request;
use App\Middleware\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        Logger::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Logger::reset();
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

    public function testLogsASecurityEventWhenTheLimitIsExceeded(): void
    {
        $rl = new RateLimit(1, 60);
        $ip = '203.0.113.20';
        $rl->handle($this->apiRequest('POST', '/api/v1/logged', $ip));

        $seen = null;
        Logger::persistUsing(function (string $channel, array $entry) use (&$seen): void {
            $seen = [$channel, $entry];
        });

        try {
            $rl->handle($this->apiRequest('POST', '/api/v1/logged', $ip));
        } catch (HttpException) {
            // expected — asserting on the log side effect, not the exception here
        }

        $this->assertNotNull($seen);
        [$channel, $entry] = $seen;
        $this->assertSame('security', $channel);
        $this->assertSame('auth.rate_limited', $entry['msg']);
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

    public function testGcFilesRemovesOnlyStaleBuckets(): void
    {
        $dir = base_path('storage/data/ratelimit');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fresh = $dir . '/fresh.json';
        $stale = $dir . '/stale.json';
        file_put_contents($fresh, '{}');
        file_put_contents($stale, '{}');
        // Age the stale bucket well past the cutoff.
        touch($stale, time() - 7200);

        $removed = RateLimit::gcFiles(3600);

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertFileExists($fresh, 'a recently-touched bucket must survive');
        $this->assertFileDoesNotExist($stale, 'a bucket older than the cutoff must be removed');
    }
}
