<?php
declare(strict_types=1);

namespace Tests\Models;

use App\Core\Database;
use App\Core\StorageManager;
use App\Models\MediaModel;
use App\Storage\LocalDriver;
use PHPUnit\Framework\TestCase;

final class MediaModelTest extends TestCase
{
    private MediaModel $model;
    private string $root;

    protected function setUp(): void
    {
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS media');
        $pdo->exec(
            'CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, path TEXT,'
            . ' driver TEXT DEFAULT "local", filename TEXT, mime TEXT, size INTEGER,'
            . ' created_at TEXT, updated_at TEXT)'
        );

        $this->root = sys_get_temp_dir() . '/o9-media-model-' . bin2hex(random_bytes(4));
        StorageManager::reset();
        StorageManager::instance()->setDriver('local', new LocalDriver(['root' => $this->root]));

        $this->model = new MediaModel();
    }

    protected function tearDown(): void
    {
        StorageManager::reset();
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testStoreUploadWritesFileAndCatalogRow(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'file bytes');

        $id = $this->model->storeUpload($tmp, 'My Photo.JPG', 7);
        @unlink($tmp);

        $row = $this->model->find($id);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('image/jpeg', $row['mime']);
        $this->assertSame('My Photo.JPG', $row['filename']);
        $this->assertStringStartsWith('7/', $row['path']);
        $this->assertTrue(StorageManager::instance()->exists($row['path']));
    }

    public function testForUserOrdersByIdDescending(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');
        $first = $this->model->storeUpload($tmp, 'a.txt', 3);
        $second = $this->model->storeUpload($tmp, 'b.txt', 3);
        @unlink($tmp);

        $rows = $this->model->forUser(3);
        $this->assertSame([$second, $first], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function testDeleteAndPurgeRemovesFileAndRow(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tmp, 'x');
        $id = $this->model->storeUpload($tmp, 'c.txt', 9);
        @unlink($tmp);
        $path = $this->model->find($id)['path'];

        $this->model->deleteAndPurge($id);

        $this->assertNull($this->model->find($id));
        $this->assertFalse(StorageManager::instance()->exists($path));
    }

    public function testDeleteAndPurgeIsANoOpForAMissingId(): void
    {
        $this->model->deleteAndPurge(999999);
        $this->addToAssertionCount(1); // no exception
    }
}
