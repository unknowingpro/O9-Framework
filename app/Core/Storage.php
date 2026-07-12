<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Storage — small filesystem primitives used before/around StorageManager:
 * atomic JSON read/write for local state files (job/progress trackers,
 * heartbeats-adjacent bookkeeping) and an atomic "move a tmp file into
 * place" helper for local uploads.
 *
 * Atomicity comes from rename(), which is atomic on the same filesystem —
 * a crash mid-write leaves no half-written file at the real path. Every
 * write here goes through a per-process tmp file first.
 */
final class Storage
{
    /**
     * Atomically write $data as JSON to $path.
     *
     * @param array<string, mixed> $data
     */
    public static function writeJson(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false; // never write a corrupt/zero-byte file
        }
        self::ensureDir(dirname($path));
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Read and decode a JSON file written by writeJson(). Null if missing/corrupt.
     *
     * @return array<string, mixed>|null
     */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomically move a local tmp file into its final local path (copy + rename,
     * so the source survives if the destination filesystem differs). Creates
     * any missing parent directories.
     */
    public static function putLocal(string $tmpPath, string $destPath): bool
    {
        self::ensureDir(dirname($destPath));
        $staging = $destPath . '.' . getmypid() . '.tmp';
        if (!copy($tmpPath, $staging)) {
            @unlink($staging);
            return false;
        }
        if (!rename($staging, $destPath)) {
            @unlink($staging);
            return false;
        }
        return true;
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
