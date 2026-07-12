<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Cache\Cache;
use App\Core\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
    }

    public function testStartsClosedAndAllowsCalls(): void
    {
        $cb = new CircuitBreaker('svc-a');
        $this->assertTrue($cb->allowed());
    }

    public function testTripsOpenAfterThresholdFailures(): void
    {
        $cb = new CircuitBreaker('svc-b', threshold: 3, cooldown: 30);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertTrue($cb->allowed());
        $cb->recordFailure(); // 3rd failure trips it
        $this->assertFalse($cb->allowed());
    }

    public function testRecordSuccessResetsFailureCount(): void
    {
        $cb = new CircuitBreaker('svc-c', threshold: 2);
        $cb->recordFailure();
        $cb->recordSuccess();
        $cb->recordFailure();
        $this->assertTrue($cb->allowed()); // only 1 consecutive failure since the reset
    }

    public function testCallReturnsResultAndResetsOnSuccess(): void
    {
        $cb = new CircuitBreaker('svc-d');
        $result = $cb->call(fn () => 'ok');
        $this->assertSame('ok', $result);
        $this->assertTrue($cb->allowed());
    }

    public function testCallRecordsFailureAndRethrowsWithoutFallback(): void
    {
        $cb = new CircuitBreaker('svc-e', threshold: 1);
        try {
            $cb->call(function (): void {
                throw new \RuntimeException('dependency down');
            });
            $this->fail('expected exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('dependency down', $e->getMessage());
        }
        $this->assertFalse($cb->allowed()); // threshold=1, tripped immediately
    }

    public function testCallUsesFallbackWhenOpen(): void
    {
        $cb = new CircuitBreaker('svc-f', threshold: 1);
        $cb->recordFailure(); // trip it
        $result = $cb->call(
            fn () => $this->fail('the wrapped call must not run while open'),
            fn () => 'fallback-value'
        );
        $this->assertSame('fallback-value', $result);
    }

    public function testCallThrowsWhenOpenAndNoFallbackGiven(): void
    {
        $cb = new CircuitBreaker('svc-g', threshold: 1);
        $cb->recordFailure();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Circuit 'svc-g' is open");
        $cb->call(fn () => 'never runs');
    }

    public function testCallUsesFallbackOnFailureToo(): void
    {
        $cb = new CircuitBreaker('svc-h', threshold: 5);
        $result = $cb->call(
            function (): void { throw new \RuntimeException('fail'); },
            fn () => 'fallback'
        );
        $this->assertSame('fallback', $result);
    }

    public function testIndependentServicesHaveIndependentState(): void
    {
        $cbA = new CircuitBreaker('svc-i', threshold: 1);
        $cbB = new CircuitBreaker('svc-j', threshold: 1);
        $cbA->recordFailure();
        $this->assertFalse($cbA->allowed());
        $this->assertTrue($cbB->allowed());
    }
}
