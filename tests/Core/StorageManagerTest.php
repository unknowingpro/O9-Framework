<?php
declare(strict_types=1);

namespace Tests\Core\Fixtures {
    use App\Storage\StorageDriverInterface;

    /** In-memory fake driver — records calls, lets tests force failures. */
    final class FakeStorageDriver implements StorageDriverInterface
    {
        /** @var array<string,string> */
        public array $files = [];
        /** @var list<string> */
        public array $putCalls = [];
        public bool $failGet = false;
        public bool $failExists = false;
        public bool $failDelete = false;

        public function __construct(private string $driverName)
        {
        }

        public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
        {
            $this->putCalls[] = $remotePath;
            $this->files[$remotePath] = (string) file_get_contents($tmpPath);
            return true;
        }

        public function get(string $remotePath): string
        {
            if ($this->failGet || !isset($this->files[$remotePath])) {
                throw new \RuntimeException("{$this->driverName}: not found: $remotePath");
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fake_');
            file_put_contents($tmp, $this->files[$remotePath]);
            return $tmp;
        }

        public function delete(string $remotePath): bool
        {
            if ($this->failDelete) return false;
            unset($this->files[$remotePath]);
            return true;
        }

        public function exists(string $remotePath): bool
        {
            if ($this->failExists) return false;
            return isset($this->files[$remotePath]);
        }

        public function name(): string { return $this->driverName; }
    }
}

namespace Tests\Core {

use App\Core\StorageManager;
use PHPUnit\Framework\TestCase;
use Tests\Core\Fixtures\FakeStorageDriver;

final class StorageManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        StorageManager::reset();
    }

    private function srcFile(string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sm_src_');
        file_put_contents($tmp, $contents);
        return $tmp;
    }

    public function testPrimaryModeWritesOnlyToPrimary(): void
    {
        $mgr = StorageManager::instance();
        $primary = new FakeStorageDriver('primary');
        $other   = new FakeStorageDriver('other');
        $mgr->setDriver('local', $primary); // 'local' is the default primary name
        $mgr->setDriver('other', $other);

        $this->assertTrue($mgr->put($this->srcFile('data'), 'a.txt'));
        $this->assertSame(['a.txt'], $primary->putCalls);
        $this->assertSame([], $other->putCalls);
    }

    public function testGetFallsBackToTheConfiguredChainWhenPrimaryFails(): void
    {
        $mgr = StorageManager::instance();
        $primary  = new FakeStorageDriver('local');
        $fallback = new FakeStorageDriver('fallback');
        $mgr->setDriver('local', $primary);
        $mgr->setDriver('fallback', $fallback);
        $mgr->setFallbackChain(['fallback']);
        $fallback->files['report.pdf'] = 'from-fallback';

        // Primary has nothing for this path — get() must fail over.
        $tmpPath = $mgr->get('report.pdf');
        $this->assertSame('from-fallback', file_get_contents($tmpPath));
    }

    public function testGetThrowsWhenNoDriverInTheChainHasTheFile(): void
    {
        $mgr = StorageManager::instance();
        $mgr->setDriver('local', new FakeStorageDriver('local'));

        $this->expectException(\RuntimeException::class);
        $mgr->get('missing.txt');
    }

    public function testExistsChecksTheFallbackChain(): void
    {
        $mgr = StorageManager::instance();
        $primary  = new FakeStorageDriver('local');
        $fallback = new FakeStorageDriver('fallback');
        $mgr->setDriver('local', $primary);
        $mgr->setDriver('fallback', $fallback);
        $mgr->setFallbackChain(['fallback']);
        $fallback->files['x.txt'] = 'y';

        $this->assertFalse($primary->exists('x.txt'));
        $this->assertTrue($mgr->exists('x.txt'));
    }

    public function testDeleteRemovesFromEveryDriverAndReportsPartialFailure(): void
    {
        $mgr = StorageManager::instance();
        $a = new FakeStorageDriver('local');
        $b = new FakeStorageDriver('b');
        $mgr->setDriver('local', $a);
        $mgr->setDriver('b', $b);
        $a->files['f.txt'] = '1';
        $b->files['f.txt'] = '1';
        $b->failDelete = true;

        $this->assertFalse($mgr->delete('f.txt')); // b failed, so overall false
        $this->assertArrayNotHasKey('f.txt', $a->files);
    }

    public function testAllAndPrimaryNameAndDriverAccessors(): void
    {
        $mgr = StorageManager::instance();
        $mgr->setDriver('local', new FakeStorageDriver('local'));
        $mgr->setDriver('extra', new FakeStorageDriver('extra'));

        $this->assertSame('local', $mgr->primaryName());
        $this->assertSame($mgr->primary(), $mgr->driver('local'));
        $this->assertCount(2, $mgr->all());
    }

    public function testDriverThrowsForUnknownName(): void
    {
        $mgr = StorageManager::instance();
        $this->expectException(\RuntimeException::class);
        $mgr->driver('does-not-exist');
    }

    public function testInstanceIsASingletonUntilReset(): void
    {
        $a = StorageManager::instance();
        $b = StorageManager::instance();
        $this->assertSame($a, $b);
        StorageManager::reset();
        $this->assertNotSame($a, StorageManager::instance());
    }
}

}
