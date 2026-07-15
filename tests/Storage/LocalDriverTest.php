<?php
declare(strict_types=1);

namespace Tests\Storage;

use App\Storage\LocalDriver;
use PHPUnit\Framework\TestCase;

final class LocalDriverTest extends TestCase
{
    private string $root;
    private LocalDriver $driver;

    protected function setUp(): void
    {
        $this->root   = sys_get_temp_dir() . '/o9-local-driver-' . bin2hex(random_bytes(4));
        $this->driver = new LocalDriver(['root' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testNameIsLocal(): void
    {
        $this->assertSame('local', $this->driver->name());
    }

    public function testPutGetExistsDeleteRoundTrip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'hello world');

        $this->assertFalse($this->driver->exists('a/b/file.txt'));
        $this->assertTrue($this->driver->put($tmp, 'a/b/file.txt'));
        $this->assertTrue($this->driver->exists('a/b/file.txt'));
        $this->assertSame('hello world', file_get_contents($this->driver->get('a/b/file.txt')));

        $this->assertTrue($this->driver->delete('a/b/file.txt'));
        $this->assertFalse($this->driver->exists('a/b/file.txt'));
        @unlink($tmp);
    }

    public function testGetThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->driver->get('nope.txt');
    }

    public function testListDirectoryReflectsFilesAndDirsSortedDirsFirst(): void
    {
        $this->driver->makeDirectory('sub');
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');
        $this->driver->put($tmp, 'b.txt');
        $this->driver->put($tmp, 'a.txt');
        @unlink($tmp);

        $entries = $this->driver->listDirectory('');
        $names = array_column($entries, 'name');
        $this->assertSame(['sub', 'a.txt', 'b.txt'], $names); // dirs first, then alpha
        $this->assertTrue($entries[0]['is_dir']);
        $this->assertSame('dir', $entries[0]['type']);
        $this->assertSame('file', $entries[1]['type']);
    }

    public function testMoveAndCopy(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'payload');
        $this->driver->put($tmp, 'orig.txt');
        @unlink($tmp);

        $this->assertTrue($this->driver->copy('orig.txt', 'copy.txt'));
        $this->assertTrue($this->driver->exists('orig.txt'));
        $this->assertTrue($this->driver->exists('copy.txt'));

        $this->assertTrue($this->driver->move('copy.txt', 'moved.txt'));
        $this->assertFalse($this->driver->exists('copy.txt'));
        $this->assertTrue($this->driver->exists('moved.txt'));
    }

    public function testDeleteItemHandlesFilesAndDirectoriesRecursively(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');
        $this->driver->put($tmp, 'dir/nested/file.txt');
        @unlink($tmp);

        $this->assertTrue($this->driver->deleteItem('dir'));
        $this->assertFalse($this->driver->exists('dir/nested/file.txt'));
    }

    public function testSafePathRejectsTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->driver->listDirectory('../../etc');
    }

    public function testSafePathRejectsTrailingDotDotSegment(): void
    {
        // 'x/..' resolves to the storage root — deleteItem() on it would wipe
        // every uploaded file. It must be rejected like any other traversal.
        $this->driver->makeDirectory('keepme');
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');
        $this->driver->put($tmp, 'keepme/a.txt');
        @unlink($tmp);

        try {
            $this->driver->deleteItem('keepme/..');
            $this->fail('expected traversal rejection for a trailing .. segment');
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertTrue($this->driver->exists('keepme/a.txt'), 'root contents must survive');
    }

    /** @return list<string> the methods that did NOT reject the traversal path */
    private function methodsAcceptingTraversal(string $tmp): array
    {
        $accepted = [];
        $calls = [
            'exists'       => fn () => $this->driver->exists('../escape.txt'),
            'get'          => fn () => $this->driver->get('../escape.txt'),
            'delete'       => fn () => $this->driver->delete('../escape.txt'),
            'absolutePath' => fn () => $this->driver->absolutePath('../escape.txt'),
            'put'          => fn () => $this->driver->put($tmp, '../escape.txt'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $accepted[] = $name; // returned without rejecting → traversal not guarded
            } catch (\RuntimeException) {
                // rejected as intended
            }
        }
        return $accepted;
    }

    public function testStorageMethodsRejectTraversal(): void
    {
        // The StorageDriverInterface methods (put/get/delete/exists/
        // absolutePath) must guard traversal too — not only the file-manager
        // API. A '../' path must never escape the root.
        $escaped = dirname($this->root) . '/escape.txt';
        @unlink($escaped);
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');

        $accepted = $this->methodsAcceptingTraversal($tmp);

        @unlink($tmp);
        $leaked = is_file($escaped);
        @unlink($escaped);

        $this->assertSame([], $accepted, 'these methods let a ../ path through: ' . implode(', ', $accepted));
        $this->assertFalse($leaked, 'put() wrote outside the storage root');
    }

    public function testQuotaReportsPlausibleShape(): void
    {
        $q = $this->driver->quota();
        $this->assertSame('local', $q['driver']);
        $this->assertArrayHasKey('used', $q);
        $this->assertArrayHasKey('total', $q);
        $this->assertNull($q['error']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
