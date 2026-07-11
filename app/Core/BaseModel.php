<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Thin parent for models. Provides a builder rooted at $table and a few
 * CRUD shortcuts.
 */
abstract class BaseModel
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    /** Set false for tables without an updated_at column. */
    protected bool $hasUpdatedAt = true;
    /** When true, find() hides soft-deleted rows and softDeleteById() is the delete path. */
    protected bool $softDeletes = false;
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function table(): QueryBuilder
    {
        return $this->db->table($this->table);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $q = $this->table()->where($this->primaryKey, '=', $id);
        if ($this->softDeletes) {
            $q = $q->whereNull('deleted_at');
        }
        return $q->first();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] ??= self::now();
        if ($this->hasUpdatedAt) {
            $data['updated_at'] ??= $data['created_at'];
        }
        return $this->db->insertGetId($this->table, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateById(int $id, array $data): int
    {
        if ($this->hasUpdatedAt) {
            $data['updated_at'] = self::now();
        }
        return $this->table()->where($this->primaryKey, '=', $id)->update($data);
    }

    /** Hard delete — removes the row. Used by purge jobs; bypasses soft-delete. */
    public function deleteById(int $id): int
    {
        return $this->table()->where($this->primaryKey, '=', $id)->delete();
    }

    /** Soft delete — stamps deleted_at so the row is hidden but retained. */
    public function softDeleteById(int $id): int
    {
        return $this->table()->where($this->primaryKey, '=', $id)->update(['deleted_at' => self::now()]);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Atomically add $by to a denormalized counter column on one row. $allowed is
     * a whitelist — the column name is interpolated as an SQL identifier (never a
     * bind param), so callers must constrain it. No-ops on a non-whitelisted name.
     *
     * @param array<int, string> $allowed
     */
    protected function bumpColumn(int $id, string $column, int $by, array $allowed): void
    {
        if (!in_array($column, $allowed, true)) {
            return;
        }
        $this->db->raw(
            "UPDATE {$this->table} SET $column = $column + ? WHERE {$this->primaryKey} = ?",
            [$by, $id]
        );
    }
}
