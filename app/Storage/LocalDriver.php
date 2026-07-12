<?php
declare(strict_types=1);

namespace App\Storage;

/** LocalDriver — stores files on the local filesystem. */
class LocalDriver implements StorageDriverInterface, FileManagerInterface
{
    private string $root;

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->root = rtrim((string) ($cfg['root'] ?? config('storage.upload_dir', base_path('storage/uploads'))), '/\\') . '/';
        if (!is_dir($this->root)) mkdir($this->root, 0755, true);
    }

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        $abs = $this->root . $remotePath;
        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // Atomic write: copy to a per-pid tmp file first, then rename(). rename()
        // is atomic on the same filesystem — a crash mid-copy leaves no partial
        // file at the real path.
        $staging = $abs . '.tmp.' . getmypid();
        if (!copy($tmpPath, $staging)) {
            @unlink($staging);
            return false;
        }
        if (!rename($staging, $abs)) {
            @unlink($staging);
            return false;
        }
        return true;
    }

    public function get(string $remotePath): string
    {
        $abs = $this->root . $remotePath;
        if (!file_exists($abs)) throw new \RuntimeException("Local file not found: $abs");
        return $abs; // no copy needed — return the abs path directly
    }

    public function delete(string $remotePath): bool
    {
        $abs = $this->root . $remotePath;
        if (!file_exists($abs)) return true;
        $ok  = @unlink($abs);
        $dir = dirname($abs);
        if (is_dir($dir) && count(array_diff(scandir($dir) ?: [], ['.', '..'])) === 0) @rmdir($dir);
        return $ok;
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->root . $remotePath);
    }

    public function absolutePath(string $remotePath): string
    {
        return $this->root . $remotePath;
    }

    public function root(): string { return $this->root; }

    public function name(): string { return 'local'; }

    // ── FileManagerInterface (browsable local filesystem) ───────────────────

    /** Resolve a browse-relative path under root, rejecting traversal. */
    private function safePath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel !== '' && (str_contains($rel, '../') || str_starts_with($rel, '..') || str_contains($rel, "\0"))) {
            throw new \RuntimeException('Invalid path');
        }
        return rtrim($this->root, '/') . ($rel !== '' ? '/' . $rel : '');
    }

    public function listDirectory(string $path = ''): array
    {
        $dir = $this->safePath($path);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Local: not a directory: ' . ($path === '' ? '/' : $path));
        }
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $abs   = $dir . '/' . $name;
            $isDir = is_dir($abs);
            $rel   = ($path !== '' ? rtrim($path, '/') . '/' : '') . $name;
            $entries[] = [
                'name'     => $name,
                'path'     => $rel,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? null : (int) (@filesize($abs) ?: 0),
                'modified' => (@filemtime($abs) ?: null),
                'mime'     => $isDir ? null : $this->guessMime($name),
                'is_dir'   => $isDir,
            ];
        }
        usort($entries, fn (array $a, array $b): int => ($b['is_dir'] <=> $a['is_dir']) ?: strcasecmp((string) $a['name'], (string) $b['name']));
        return $entries;
    }

    public function makeDirectory(string $path): bool
    {
        $abs = $this->safePath($path);
        return is_dir($abs) || @mkdir($abs, 0755, true);
    }

    public function move(string $from, string $to): bool
    {
        $src = $this->safePath($from);
        $dst = $this->safePath($to);
        if (!file_exists($src)) { return false; }
        $dir = dirname($dst);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return @rename($src, $dst);
    }

    public function copy(string $from, string $to): bool
    {
        $src = $this->safePath($from);
        $dst = $this->safePath($to);
        if (!is_file($src)) { return false; }
        $dir = dirname($dst);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return @copy($src, $dst);
    }

    public function deleteItem(string $path): bool
    {
        $abs = $this->safePath($path);
        if (is_dir($abs)) { return $this->rrmdir($abs); }
        return !file_exists($abs) || @unlink($abs);
    }

    private function rrmdir(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        return @rmdir($dir);
    }

    public function download(string $path): string
    {
        $abs = $this->safePath($path);
        if (!is_file($abs)) { throw new \RuntimeException("Local file not found: $path"); }
        return $abs;
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $abs = $this->safePath($remotePath);
        $dir = dirname($abs);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return @copy($localPath, $abs);
    }

    public function quota(): array
    {
        $free  = @disk_free_space($this->root);
        $total = @disk_total_space($this->root);
        $free  = $free === false ? 0 : (int) $free;
        $total = $total === false ? 0 : (int) $total;
        $used  = max(0, $total - $free);
        return [
            'driver' => 'local', 'label' => 'Local',
            'used' => $used, 'free' => $free, 'total' => $total,
            'used_human' => human_size($used), 'free_human' => human_size($free), 'total_human' => human_size($total),
            'percent_used' => $total > 0 ? round($used / $total * 100, 1) : 0.0, 'error' => null,
        ];
    }

    private function guessMime(string $filename): string
    {
        static $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'wav' => 'audio/wav',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
            'txt' => 'text/plain', 'json' => 'application/json', 'csv' => 'text/csv', 'html' => 'text/html',
        ];
        return $map[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
