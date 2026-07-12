<?php
declare(strict_types=1);

namespace Tests\Controllers\Admin;

use App\Controllers\Admin\MediaController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\StorageManager;
use App\Storage\LocalDriver;
use PHPUnit\Framework\TestCase;

final class MediaControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/o9-media-controller-' . bin2hex(random_bytes(4));
        StorageManager::reset();
        StorageManager::instance()->setDriver('local', new LocalDriver(['root' => $this->root]));
    }

    protected function tearDown(): void
    {
        StorageManager::reset();
        foreach (glob($this->root . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
    }

    public function testThrowsNotFoundForAnEmptyPath(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new MediaController())->show(new Request(), ['path' => '']);
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
            throw $e;
        }
    }

    public function testThrowsNotFoundForTraversalAttempts(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new MediaController())->show(new Request(), ['path' => '../etc/passwd']);
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
            throw $e;
        }
    }

    public function testThrowsNotFoundWhenTheFileDoesNotExistInStorage(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new MediaController())->show(new Request(), ['path' => 'no-such-file.txt']);
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
            throw $e;
        }
    }
}
