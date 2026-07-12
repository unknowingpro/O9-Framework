<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\CacheClearCommand;
use App\Core\CacheManager;
use PHPUnit\Framework\TestCase;

final class CacheClearCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        CacheManager::flush();
    }

    public function testFlushesTheCacheAndExitsZero(): void
    {
        CacheManager::set('some-key', 'some-value');
        $this->assertSame('some-value', CacheManager::get('some-key'));

        $exit = (new CacheClearCommand())->run([]);
        $this->assertSame(0, $exit);
        $this->assertNull(CacheManager::get('some-key'));
    }
}
