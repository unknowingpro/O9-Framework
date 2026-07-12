<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\CacheManager;

/**
 * Cache-aware model that wraps BaseModel's read operations with
 * CacheManager::remember() and auto-forgets on writes.
 *
 * The default TTL is config('cache.default_ttl', 3600); override it in a
 * subclass by setting $cacheTtl.
 *
 * Usage:
 *   class ProductModel extends CachedModel {
 *       protected string $table = 'products';
 *   }
 *
 *   $model = new ProductModel();
 *   $product = $model->find(1);          // cached on first call
 *   $product = $model->find(1);          // returns from cache
 *   $model->updateById(1, ['price' => 9.99]);  // cache forgotten
 *
 * @see \App\Core\BaseModel
 * @see \App\Core\CacheManager
 */
abstract class CachedModel extends BaseModel
{
    /** Override to set a per-model TTL (seconds). Null = use config default. */
    protected ?int $cacheTtl = null;

    /**
     * Cache prefix used for the "all records" cache key.
     * Override when two model tables share a prefix.
     */
    protected string $allCacheKey = '';

    /**
     * Find a record by primary key, caching the result.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $ttl = $this->cacheTtl ?? (int) config('cache.default_ttl', 3600);
        $key = $this->findCacheKey($id);

        /** @var array<string, mixed>|null $cached */
        $cached = CacheManager::remember($key, $ttl, function () use ($id): ?array {
            return parent::find($id);
        });

        return $cached;
    }

    /**
     * Return all rows (cached). Supports the same arguments as QueryBuilder::get().
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function cachedAll(array $columns = ['*']): array
    {
        $ttl = $this->cacheTtl ?? (int) config('cache.default_ttl', 3600);
        $key = $this->allCacheKey ?: $this->table . ':all';

        /** @var list<array<string, mixed>> $cached */
        $cached = CacheManager::remember($key, $ttl, function () use ($columns): array {
            return $this->table()->get($columns);
        });

        return $cached;
    }

    /**
     * Insert a row and forget the 'all' cache.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $id = parent::create($data);
        $this->forgetAll();
        return $id;
    }

    /**
     * Update a row and drop its cached entry.
     *
     * @param array<string, mixed> $data
     */
    public function updateById(int $id, array $data): int
    {
        $affected = parent::updateById($id, $data);
        $this->forgetFind($id);
        return $affected;
    }

    /**
     * Hard delete and drop cached entry.
     */
    public function deleteById(int $id): int
    {
        $affected = parent::deleteById($id);
        $this->forgetFind($id);
        $this->forgetAll();
        return $affected;
    }

    /**
     * Soft delete and drop cached entry.
     */
    public function softDeleteById(int $id): int
    {
        $affected = parent::softDeleteById($id);
        $this->forgetFind($id);
        $this->forgetAll();
        return $affected;
    }

    /**
     * Evict a single record's cache entry.
     */
    public function forgetFind(int $id): void
    {
        CacheManager::forget($this->findCacheKey($id));
    }

    /**
     * Evict the cached "all" entry.
     */
    public function forgetAll(): void
    {
        $key = $this->allCacheKey ?: $this->table . ':all';
        CacheManager::forget($key);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function findCacheKey(int $id): string
    {
        return $this->table . ':' . $this->primaryKey . ':' . $id;
    }
}
