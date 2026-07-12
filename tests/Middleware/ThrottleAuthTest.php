<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Middleware\ThrottleAuth;
use PHPUnit\Framework\TestCase;

final class ThrottleAuthTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        foreach (glob(base_path('storage/data/ratelimit/*.json')) ?: [] as $f) {
            @unlink($f);
        }
    }

    private function req(string $ip): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/v1/auth/login';
        $_SERVER['REMOTE_ADDR']    = $ip;
        return new Request();
    }

    public function testAllowsFiveThenThrowsOnTheSixth(): void
    {
        $ta = new ThrottleAuth();
        $ip = '203.0.113.20';
        for ($i = 0; $i < 5; $i++) {
            $ta->handle($this->req($ip));
        }
        $this->expectException(HttpException::class);
        try {
            $ta->handle($this->req($ip));
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status);
            throw $e;
        }
    }
}
