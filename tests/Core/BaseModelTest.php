<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\BaseModel;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class ModelFixture extends BaseModel
{
    protected string $table = 'bm_t';
    protected bool $softDeletes = true;

    public function bump(int $id, string $column, int $by): void
    {
        $this->bumpColumn($id, $column, $by, ['hits']);
    }
}

final class BaseModelTest extends TestCase
{
    private ModelFixture $model;

    protected function setUp(): void
    {
        Database::getInstance()->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS bm_t (id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' name TEXT, hits INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Database::getInstance()->pdo()->exec('DELETE FROM bm_t');
        $this->model = new ModelFixture();
    }

    public function testCreateStampsTimestampsAndFinds(): void
    {
        $id = $this->model->create(['name' => 'thing']);
        $row = $this->model->find($id);
        $this->assertSame('thing', $row['name']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertSame($row['created_at'], $row['updated_at']);
    }

    public function testUpdateByIdBumpsUpdatedAt(): void
    {
        $id = $this->model->create(['name' => 'x', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        $this->model->updateById($id, ['name' => 'y']);
        $row = $this->model->find($id);
        $this->assertSame('y', $row['name']);
        $this->assertNotSame('2020-01-01 00:00:00', $row['updated_at']);
    }

    public function testSoftDeleteHidesFromFindButKeepsRow(): void
    {
        $id = $this->model->create(['name' => 'temp']);
        $this->model->softDeleteById($id);
        $this->assertNull($this->model->find($id));
        $raw = Database::getInstance()->table('bm_t')->where('id', '=', $id)->first();
        $this->assertNotNull($raw);
        $this->assertNotNull($raw['deleted_at']);

        $this->model->deleteById($id);
        $this->assertNull(Database::getInstance()->table('bm_t')->where('id', '=', $id)->first());
    }

    public function testBumpColumnHonoursWhitelist(): void
    {
        $id = $this->model->create(['name' => 'counter']);
        $this->model->bump($id, 'hits', 3);
        $this->model->bump($id, 'hits', 2);
        $this->model->bump($id, 'name', 1); // not whitelisted → no-op
        $row = $this->model->find($id);
        $this->assertSame(5, (int) $row['hits']);
        $this->assertSame('counter', $row['name']);
    }
}
