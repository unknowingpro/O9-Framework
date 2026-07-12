<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * FtpDriver — stores files on a plain FTP or FTPS server via PHP's ftp_* functions.
 *
 * Config keys:
 *   host      FTP hostname
 *   port      (default 21)
 *   user      username
 *   password  password
 *   root      remote base directory (must exist)
 *   ssl       true to use FTPS (FTP over TLS), default false
 *   passive   true to use passive mode (recommended), default true
 */
class FtpDriver implements StorageDriverInterface, FileManagerInterface
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $root;
    private bool   $ssl;
    private bool   $passive;

    private mixed $conn = false;

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->host    = (string) ($cfg['host'] ?? '');
        $this->port    = (int) ($cfg['port'] ?? 21);
        $this->user    = (string) ($cfg['user'] ?? $cfg['username'] ?? '');
        $this->pass    = (string) ($cfg['password'] ?? '');
        $this->root    = rtrim((string) ($cfg['root'] ?? '/'), '/') . '/';
        $this->ssl     = filter_var($cfg['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->passive = filter_var($cfg['passive'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($this->host === '' || $this->user === '') {
            throw new \RuntimeException('FtpDriver: host and user are required');
        }
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('FtpDriver: PHP FTP extension is not enabled');
        }
    }

    public function name(): string { return 'ftp'; }

    // ── Public interface ──────────────────────────────────────────────────

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        $conn = $this->conn();
        $full = $this->root . $remotePath;
        $this->mkdirp($conn, dirname($full));
        // Suppress the PHP warning so it never leaks into HTTP output (a warning
        // printed before headers are sent causes "headers already sent" errors).
        error_clear_last();
        $ok = @ftp_put($conn, $full, $tmpPath, FTP_BINARY);
        if (!$ok) {
            $err    = error_get_last();
            $detail = $err !== null ? preg_replace('/^ftp_put\(\):\s*/i', '', $err['message']) : 'unknown error';
            throw new \RuntimeException("FTP PUT failed for: $remotePath ($detail)");
        }
        return true;
    }

    public function get(string $remotePath): string
    {
        $conn = $this->conn();
        $tmp  = tempnam(sys_get_temp_dir(), 'o9_ftp_');
        if ($tmp === false) throw new \RuntimeException('FTP GET: could not create temp file');
        $ok = ftp_get($conn, $tmp, $this->root . $remotePath, FTP_BINARY);
        if (!$ok) { @unlink($tmp); throw new \RuntimeException("FTP GET failed for: $remotePath"); }
        return $tmp;
    }

    public function delete(string $remotePath): bool
    {
        $conn = $this->conn();
        return (bool) @ftp_delete($conn, $this->root . $remotePath);
    }

    public function exists(string $remotePath): bool
    {
        $conn = $this->conn();
        return ftp_size($conn, $this->root . $remotePath) >= 0;
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function conn(): mixed
    {
        if ($this->conn) return $this->conn;

        $conn = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, 30)
            : @ftp_connect($this->host, $this->port, 30);

        if (!$conn) throw new \RuntimeException("FTP: cannot connect to {$this->host}:{$this->port}");
        if (!@ftp_login($conn, $this->user, $this->pass)) {
            throw new \RuntimeException("FTP: login failed for {$this->user}@{$this->host}");
        }
        ftp_pasv($conn, $this->passive);

        $this->conn = $conn;
        return $conn;
    }

    private function mkdirp(mixed $conn, string $dir): void
    {
        $parts   = explode('/', ltrim($dir, '/'));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            $current .= '/' . $part;
            // Attempt to create; suppress the error if it already exists.
            @ftp_mkdir($conn, $current);
        }
    }

    // ── FileManagerInterface ──────────────────────────────────────────────

    public function listDirectory(string $path = ''): array
    {
        $conn    = $this->conn();
        $remote  = rtrim($this->root . $path, '/');
        $rawList = ftp_rawlist($conn, $remote);
        // ftp_rawlist returns false on connection/permission errors — surface it
        // rather than showing an empty directory (indistinguishable from a real one).
        if ($rawList === false) {
            throw new \RuntimeException("FTP: cannot list $remote (check connection and permissions)");
        }

        $entries = [];
        foreach ($rawList as $line) {
            $name = null; $isDir = false; $size = null;
            // Unix-style: "drwxr-xr-x  2 user group  4096 Jan  1 00:00 name"
            if (preg_match('/^(\S+)\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(.+)$/', $line, $m)) {
                $name = trim($m[3]); $isDir = $m[1][0] === 'd'; $size = $isDir ? null : (int) $m[2];
            // DOS/IIS-style: "01-01-25  12:00AM  <DIR>  name"  or  "... 1234 name"
            } elseif (preg_match('/^\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}(?:[AP]M)?\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
                $name = trim($m[2]); $isDir = strtoupper($m[1]) === '<DIR>'; $size = $isDir ? null : (int) $m[1];
            } else {
                continue;
            }
            if ($name === '' || $name === '.' || $name === '..') continue;
            $rel = ($path !== '' ? rtrim($path, '/') . '/' : '') . $name;
            $entries[] = [
                'name'     => $name,
                'path'     => $rel,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $size,
                'modified' => null,
                'mime'     => $isDir ? null : $this->guessMime($name),
                'is_dir'   => $isDir,
            ];
        }
        return $entries;
    }

    /** Extension-based MIME guess (ftp_rawlist gives only names, not readable paths). */
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

    public function makeDirectory(string $path): bool
    {
        $conn = $this->conn();
        return @ftp_mkdir($conn, $this->root . $path) !== false;
    }

    public function move(string $from, string $to): bool
    {
        $conn = $this->conn();
        $this->mkdirp($conn, dirname($this->root . $to)); // ensure destination dir exists
        return ftp_rename($conn, $this->root . $from, $this->root . $to);
    }

    public function copy(string $from, string $to): bool
    {
        $tmp = $this->get($from);
        try { return $this->put($tmp, $to); } finally { @unlink($tmp); }
    }

    public function deleteItem(string $path): bool
    {
        $conn   = $this->conn();
        $remote = rtrim($this->root . $path, '/');
        if (@ftp_delete($conn, $remote)) return true; // it was a file
        return $this->rmdirRecursive($conn, $remote);  // directory (possibly non-empty)
    }

    /** Recursively delete a remote directory (ftp_rmdir fails on non-empty dirs). */
    private function rmdirRecursive(mixed $conn, string $dir): bool
    {
        $children = @ftp_nlist($conn, $dir);
        if (is_array($children)) {
            foreach ($children as $child) {
                $base = basename($child);
                if ($base === '' || $base === '.' || $base === '..') continue;
                $full = str_contains($child, '/') ? $child : $dir . '/' . $base;
                if (!@ftp_delete($conn, $full)) {
                    $this->rmdirRecursive($conn, $full);
                }
            }
        }
        return @ftp_rmdir($conn, $dir);
    }

    public function download(string $path): string { return $this->get($path); }

    public function upload(string $localPath, string $remotePath): bool { return $this->put($localPath, $remotePath); }

    public function quota(): array
    {
        return ['driver' => $this->name(), 'label' => 'FTP', 'error' => 'Quota not supported for FTP'];
    }

    public function __destruct()
    {
        if ($this->conn) @ftp_close($this->conn);
    }
}
