<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Cache\ArrayCacheStore;
use App\Core\Cache\Cache;
use App\Core\Cache\CacheStore;
use App\Core\Cache\FileCacheStore;
use App\Core\CacheManager;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setStore(null);
    }

    /** @return list<array{0: CacheStore}> */
    public static function stores(): array
    {
        return [
            'array' => [new ArrayCacheStore()],
            'file'  => [new FileCacheStore(sys_get_temp_dir() . '/o9-cache-test-' . getmypid())],
        ];
    }

    /** @dataProvider stores */
    public function testSetGetDeleteRoundTrip(CacheStore $store): void
    {
        $store->flush();
        $this->assertNull($store->get('missing'));
        $this->assertFalse($store->has('missing'));

        $store->set('k', ['nested' => ['a' => 1], 'flag' => true]);
        $this->assertTrue($store->has('k'));
        $this->assertSame(['nested' => ['a' => 1], 'flag' => true], $store->get('k'));

        $store->delete('k');
        $this->assertFalse($store->has('k'));
    }

    /** @dataProvider stores */
    public function testTtlExpiry(CacheStore $store): void
    {
        $store->flush();
        $store->set('short', 'value', -1); // already expired
        $this->assertNull($store->get('short'));
        $this->assertFalse($store->has('short'));

        $store->set('long', 'value', 3600);
        $this->assertSame('value', $store->get('long'));
    }

    /** @dataProvider stores */
    public function testIncrementCreatesAndAccumulates(CacheStore $store): void
    {
        $store->flush();
        $this->assertSame(1, $store->increment('counter'));
        $this->assertSame(4, $store->increment('counter', 3));
        $this->assertSame(4, (int) $store->get('counter'));
    }

    /** @dataProvider stores */
    public function testFlushDropsEverything(CacheStore $store): void
    {
        $store->set('a', 1);
        $store->set('b', 2);
        $store->flush();
        $this->assertFalse($store->has('a'));
        $this->assertFalse($store->has('b'));
    }

    public function testFileStoreKeysAreCollisionSafeAndPersistent(): void
    {
        $dir = sys_get_temp_dir() . '/o9-cache-test-' . getmypid();
        $a = new FileCacheStore($dir);
        // Keys that sanitise to the same name must not collide (hash prefix).
        $a->set('user/1', 'one');
        $a->set('user 1', 'two');
        $this->assertSame('one', $a->get('user/1'));
        $this->assertSame('two', $a->get('user 1'));

        // A separate instance over the same dir sees the data (persistence).
        $b = new FileCacheStore($dir);
        $this->assertSame('one', $b->get('user/1'));
        $b->flush();
    }

    public function testFacadeRememberComputesOnceAndCaches(): void
    {
        Cache::setStore(new ArrayCacheStore());
        $calls = 0;
        $fn = function () use (&$calls): string {
            $calls++;
            return 'computed';
        };
        $this->assertSame('computed', Cache::remember('memo', 60, $fn));
        $this->assertSame('computed', Cache::remember('memo', 60, $fn));
        $this->assertSame(1, $calls);
    }

    public function testFacadeGetDefaultAndForget(): void
    {
        Cache::setStore(new ArrayCacheStore());
        $this->assertSame('dflt', Cache::get('nope', 'dflt'));
        Cache::set('x', 9);
        $this->assertSame(9, Cache::get('x'));
        Cache::forget('x');
        $this->assertSame('dflt', Cache::get('x', 'dflt'));
    }

    public function testCacheManagerDelegatesToSameStore(): void
    {
        $store = new ArrayCacheStore();
        CacheManager::setStore($store);
        CacheManager::set('shared', 'value');
        $this->assertSame('value', Cache::get('shared'));
        $this->assertSame(2, Cache::increment('n', 2));
        $this->assertSame(2, CacheManager::get('n'));
    }
}
