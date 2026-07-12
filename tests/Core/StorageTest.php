<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-storage-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testWriteJsonCreatesParentDirAndLeavesNoTempFile(): void
    {
        $path = $this->dir . '/state.json';
        $this->assertTrue(Storage::writeJson($path, ['status' => 'ok', 'n' => 3]));
        $this->assertFileExists($path);
        $this->assertCount(1, glob($this->dir . '/*') ?: []); // no .tmp leftover
        $this->assertSame(['status' => 'ok', 'n' => 3], Storage::readJson($path));
    }

    public function testWriteJsonOverwritesAtomically(): void
    {
        $path = $this->dir . '/state.json';
        Storage::writeJson($path, ['v' => 1]);
        Storage::writeJson($path, ['v' => 2]);
        $this->assertSame(['v' => 2], Storage::readJson($path));
        $this->assertCount(1, glob($this->dir . '/*') ?: []);
    }

    public function testReadJsonReturnsNullForMissingOrCorruptFile(): void
    {
        $this->assertNull(Storage::readJson($this->dir . '/ghost.json'));
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/broken.json', '{not json');
        $this->assertNull(Storage::readJson($this->dir . '/broken.json'));
    }

    public function testPutLocalMovesFileAtomicallyAndPreservesContent(): void
    {
        mkdir($this->dir, 0775, true);
        $src = $this->dir . '/src.bin';
        file_put_contents($src, 'payload-bytes');
        $dest = $this->dir . '/nested/dest.bin';

        $this->assertTrue(Storage::putLocal($src, $dest));
        $this->assertFileExists($dest);
        $this->assertSame('payload-bytes', file_get_contents($dest));
        $this->assertFileExists($src); // source survives (copy, not move)

        @unlink($dest);
        @rmdir($this->dir . '/nested');
    }

    public function testEnsureDirIsIdempotent(): void
    {
        Storage::ensureDir($this->dir . '/a/b/c');
        Storage::ensureDir($this->dir . '/a/b/c'); // no error on existing dir
        $this->assertDirectoryExists($this->dir . '/a/b/c');
        @rmdir($this->dir . '/a/b/c');
        @rmdir($this->dir . '/a/b');
        @rmdir($this->dir . '/a');
    }
}
