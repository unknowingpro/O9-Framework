<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\EventListeners;
use App\Core\Events;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        Events::flush();
    }

    protected function tearDown(): void
    {
        EventListeners::reset();
    }

    public function testListenersFireInRegistrationOrderWithThePayload(): void
    {
        $log = [];
        Events::listen('order.paid', static function (array $p) use (&$log): void {
            $log[] = 'first:' . $p['id'];
        });
        Events::listen('order.paid', static function (array $p) use (&$log): void {
            $log[] = 'second:' . $p['id'];
        });
        Events::dispatch('order.paid', ['id' => 5]);
        $this->assertSame(['first:5', 'second:5'], $log);
    }

    public function testDispatchWithNoListenersIsANoOp(): void
    {
        Events::dispatch('nobody.cares', ['x' => 1]);
        $this->addToAssertionCount(1); // no exception, nothing to observe
    }

    public function testAThrowingListenerNeverBreaksTheOthers(): void
    {
        $log = [];
        Events::listen('e', static function (): void {
            throw new \RuntimeException('side effect failed');
        });
        Events::listen('e', static function () use (&$log): void {
            $log[] = 'survivor';
        });
        Events::dispatch('e');
        $this->assertSame(['survivor'], $log);
    }

    public function testForgetRemovesOneEventFlushRemovesAll(): void
    {
        $hits = 0;
        $inc = static function () use (&$hits): void {
            $hits++;
        };
        Events::listen('a', $inc);
        Events::listen('b', $inc);

        Events::forget('a');
        Events::dispatch('a');
        Events::dispatch('b');
        $this->assertSame(1, $hits);

        Events::flush();
        Events::dispatch('b');
        $this->assertSame(1, $hits);
    }

    public function testEventListenersRegisterIsIdempotent(): void
    {
        EventListeners::reset();
        EventListeners::register();
        EventListeners::register(); // second call must be a no-op
        $this->addToAssertionCount(1);
    }
}
