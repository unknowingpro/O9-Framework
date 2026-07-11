<?php
declare(strict_types=1);

namespace App\Core\Cache;

/**
 * File-backed cache store — persistent across requests but slower than Redis.
 *
 * Used as a transparent fallback when Redis is configured but unreachable.
 * Each cache key is a single JSON file under storage/cache/. TTLs are honoured
 * by checking a wall-clock expiry stored in the file alongside the value.
 *
 * Atomic writes via write-to-temp + rename so concurrent workers don't observe
 * partial writes. Increment is implemented with flock() for atomic
 * read-modify-write.
 */
final class FileCacheStore implements CacheStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('cache/file-cache');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        $entry = json_decode($data, true);
        if (!is_array($entry) || !array_key_exists('v', $entry)) {
            return null;
        }
        // TTL expiry check
        if (isset($entry['e']) && is_int($entry['e']) && $entry['e'] < time()) {
            @unlink($path);
            return null;
        }
        return $entry['v'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $entry = [
            'v' => $value,
            'e' => $ttl !== null ? time() + $ttl : null,
        ];
        // Atomic write: temp file then rename
        $path  = $this->path($key);
        $tmp   = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok    = file_put_contents($tmp, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($ok !== false) {
            rename($tmp, $path);
        }
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Atomic increment via flock(). Creates the key at 0 if it doesn't exist.
     */
    public function increment(string $key, int $by = 1): int
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $path = $this->path($key);

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return $by; // last-ditch: return what we tried to add
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return $by;
        }

        // Read current value (JSON)
        $data    = @stream_get_contents($fh);
        $entry   = is_string($data) && $data !== '' ? json_decode($data, true) : null;
        $cur     = is_array($entry) ? (int) ($entry['v'] ?? 0) : 0;

        // Check TTL expiry
        if (is_array($entry) && isset($entry['e']) && is_int($entry['e']) && $entry['e'] < time()) {
            $cur = 0;
        }

        $new = $cur + $by;
        $expiry = is_array($entry) && isset($entry['e']) ? $entry['e'] : null;

        // Rewind and write
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode(['v' => $new, 'e' => $expiry], JSON_UNESCAPED_SLASHES));
        fflush($fh);

        flock($fh, LOCK_UN);
        fclose($fh);

        return $new;
    }

    public function flush(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $dh = opendir($this->dir);
        if ($dh === false) {
            return;
        }
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->dir . '/' . $name;
            if (is_file($path) && str_ends_with($name, '.cache')) {
                @unlink($path);
            }
        }
        closedir($dh);
    }

    /** Derive the filesystem path for a cache key: namespaced, hashed for safety. */
    private function path(string $key): string
    {
        // Sanitize: keep ASCII alphanumeric + common separators; hash the rest.
        $safe = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $key);
        // Avoid collisions when sanitisation collapses distinct keys.
        $hash = substr(md5($key), 0, 8);
        return $this->dir . '/' . $hash . '_' . $safe . '.cache';
    }
}
