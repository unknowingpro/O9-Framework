<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Cache\ArrayCacheStore;
use App\Core\Cache\Cache;
use App\Core\CachedModel;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class CachedModelTest extends TestCase
{
    private CachedWidgetModel $model;

    protected function setUp(): void
    {
        // Use the array store for fast, isolated cache
        Cache::setStore(new ArrayCacheStore());

        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS cached_widgets');
        $pdo->exec(
            'CREATE TABLE cached_widgets ('
            . ' id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' name TEXT NOT NULL,'
            . ' price REAL DEFAULT 0,'
            . ' created_at TEXT,'
            . ' updated_at TEXT'
            . ')'
        );

        $this->model = new CachedWidgetModel();
    }

    protected function tearDown(): void
    {
        Cache::setStore(null);
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS cached_widgets');
    }

    public function testFindIsCachedOnSecondCall(): void
    {
        $id = $this->model->create(['name' => 'Alpha', 'price' => 9.99]);

        // First call — DB query, populates cache
        $row1 = $this->model->find($id);
        $this->assertSame('Alpha', $row1['name']);

        // Update directly in DB to bypass cache
        Database::getInstance()->raw('UPDATE cached_widgets SET name = ? WHERE id = ?', ['Beta', $id]);

        // Second call — should return the cached 'Alpha', not the updated 'Beta'
        $row2 = $this->model->find($id);
        $this->assertSame('Alpha', $row2['name']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->model->find(999));
    }

    public function testUpdateByIdForgetsCache(): void
    {
        $id = $this->model->create(['name' => 'Gamma']);

        // Prime the cache
        $this->model->find($id);

        // Update — should forget the cache
        $this->model->updateById($id, ['name' => 'Delta']);

        // Now find should hit DB and return the fresh value
        $row = $this->model->find($id);
        $this->assertSame('Delta', $row['name']);
    }

    public function testDeleteByIdForgetsCache(): void
    {
        $id = $this->model->create(['name' => 'Epsilon']);

        // Prime cache
        $this->model->find($id);

        // Delete
        $this->model->deleteById($id);

        // Cache should be gone; find returns null
        $this->assertNull($this->model->find($id));
    }

    public function testSoftDeleteForgetsCache(): void
    {
        // Add the deleted_at column that softDeletes needs
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('ALTER TABLE cached_widgets ADD COLUMN deleted_at TEXT');

        $model = new CachedWidgetSoftModel();
        $id    = $model->create(['name' => 'Zeta']);

        // Prime cache
        $model->find($id);

        // Soft delete
        $model->softDeleteById($id);

        // After soft delete the default find() hides it (because
        // BaseModel applies whereNull('deleted_at') when softDeletes=true)
        $this->assertNull($model->find($id));
    }

    public function testCachedAllReturnsAllAndIsCached(): void
    {
        $this->model->create(['name' => 'A']);
        $this->model->create(['name' => 'B']);

        $all = $this->model->cachedAll();
        $this->assertCount(2, $all);

        // Add a row directly in DB
        Database::getInstance()->raw('INSERT INTO cached_widgets (name, price, created_at) VALUES (?, ?, ?)', ['C', 1, '2025-01-01']);

        // cachedAll should still return 2 (cached)
        $all2 = $this->model->cachedAll();
        $this->assertCount(2, $all2);
    }

    public function testCreateForgetsAllCache(): void
    {
        $this->model->create(['name' => 'P1']);
        $this->model->cachedAll(); // primes the all-cache

        // Create another — should forget the all-cache
        $this->model->create(['name' => 'P2']);

        // Next cachedAll should hit DB and see both
        $all = $this->model->cachedAll();
        $this->assertCount(2, $all);
    }

    public function testForgetFindEvictsSingleEntry(): void
    {
        $id = $this->model->create(['name' => 'Memorable']);

        // Prime cache
        $this->model->find($id);

        // Manually evict
        $this->model->forgetFind($id);

        // Update DB behind our back
        Database::getInstance()->raw('UPDATE cached_widgets SET name = ? WHERE id = ?', ['Forgotten', $id]);

        // Should get fresh value
        $row = $this->model->find($id);
        $this->assertSame('Forgotten', $row['name']);
    }

    public function testForgetAllEvictsCachedAll(): void
    {
        $this->model->create(['name' => 'X']);
        $this->model->cachedAll(); // primes

        Database::getInstance()->raw('INSERT INTO cached_widgets (name, price, created_at) VALUES (?, ?, ?)', ['Y', 2, '2025-01-01']);

        $this->model->forgetAll();
        $all = $this->model->cachedAll();
        $this->assertCount(2, $all);
    }
}

// ── Concrete model for testing ──────────────────────────────────────

final class CachedWidgetModel extends CachedModel
{
    protected string $table = 'cached_widgets';
    protected bool $hasUpdatedAt = true;
}

final class CachedWidgetSoftModel extends CachedModel
{
    protected string $table = 'cached_widgets';
    protected bool $softDeletes = true;
    protected bool $hasUpdatedAt = true;
}
