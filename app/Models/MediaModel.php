<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\BaseModel;
use App\Core\MediaFilenameHelper;
use App\Core\StorageManager;

/**
 * Sample model: a `media` record pairing a StorageManager-relative path
 * with basic metadata (see setup/database/migrations/008_media.sql). The
 * file bytes live wherever StorageManager put them; this table is just the
 * catalog entry Admin\MediaController and the sync sample read from.
 */
final class MediaModel extends BaseModel
{
    protected string $table = 'media';

    /** Store an already-uploaded local tmp file via StorageManager and record it. */
    public function storeUpload(string $tmpPath, string $originalName, ?int $userId = null): int
    {
        $storedName = MediaFilenameHelper::safeStoredName($originalName);
        $path = ($userId !== null ? $userId . '/' : '') . $storedName;

        $storage = StorageManager::instance();
        $storage->put($tmpPath, $path);

        return $this->create([
            'user_id'  => $userId,
            'path'     => $path,
            'driver'   => $storage->primaryName(),
            'filename' => MediaFilenameHelper::sanitize($originalName),
            'mime'     => MediaFilenameHelper::guessMime($originalName),
            'size'     => @filesize($tmpPath) ?: null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->table()->where('user_id', '=', $userId)->orderBy('id', 'DESC')->get();
    }

    public function deleteAndPurge(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        StorageManager::instance()->delete((string) $row['path']);
        $this->deleteById($id);
    }
}
