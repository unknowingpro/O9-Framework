<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * SftpDriver — SFTP backend via libssh2 (ssh2_* PHP extension).
 *
 * Config keys:
 *   host          SFTP hostname
 *   port          (default 22)
 *   user          username
 *   password      password (use either password or privkey_path)
 *   privkey_path  path to private key file
 *   privkey_pass  passphrase for encrypted private key (optional)
 *   root          remote base directory (must exist and be writable)
 */
class SftpDriver implements StorageDriverInterface, FileManagerInterface
{
    private string $host;
    private int    $port;
    private string $user;
    private string $password;
    private string $privkeyPath;
    private string $privkeyPass;
    private string $root;

    private mixed $sftp = null;

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->host        = (string) ($cfg['host'] ?? '');
        $this->port        = (int) ($cfg['port'] ?? 22);
        $this->user        = (string) ($cfg['user'] ?? $cfg['username'] ?? '');
        $this->password    = (string) ($cfg['password'] ?? '');
        $this->privkeyPath = (string) ($cfg['privkey_path'] ?? '');
        $this->privkeyPass = (string) ($cfg['privkey_pass'] ?? '');
        $this->root        = rtrim((string) ($cfg['root'] ?? '/upload'), '/') . '/';

        if ($this->host === '' || $this->user === '') {
            throw new \RuntimeException('SftpDriver: host and user are required');
        }
    }

    public function name(): string { return 'sftp'; }

    // ── StorageDriverInterface ────────────────────────────────────────────

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        // Stream-copy in chunks — file_get_contents() would load the whole
        // file into PHP memory and silently truncate anything past memory_limit.
        $sftp = $this->sftp();
        $dest = 'ssh2.sftp://' . (int) $sftp . $this->root . $remotePath;
        $this->mkdirp(dirname($this->root . $remotePath));

        $in = @fopen($tmpPath, 'rb');
        if (!$in) throw new \RuntimeException("SFTP PUT: cannot open local file: $tmpPath");

        $out = @fopen($dest, 'wb');
        if (!$out) { fclose($in); throw new \RuntimeException("SFTP PUT: cannot open remote path: $remotePath"); }

        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $bytes !== false;
    }

    public function get(string $remotePath): string
    {
        $sftp = $this->sftp();
        $src  = 'ssh2.sftp://' . (int) $sftp . $this->root . $remotePath;

        $tmp = tempnam(sys_get_temp_dir(), 'o9_sftp_');
        if ($tmp === false) throw new \RuntimeException('SFTP GET: could not create temp file');

        $in = @fopen($src, 'rb');
        if (!$in) { @unlink($tmp); throw new \RuntimeException("SFTP GET failed: $remotePath"); }

        $out = fopen($tmp, 'wb');
        if (!$out) { fclose($in); @unlink($tmp); throw new \RuntimeException('SFTP GET: could not open temp file for writing'); }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false) {
            @unlink($tmp);
            throw new \RuntimeException("SFTP GET: stream_copy_to_stream failed (truncated transfer): $remotePath");
        }

        return $tmp;
    }

    /** Stream a remote file to PHP output with HTTP Range support via fseek (no temp file). */
    public function stream(string $remotePath, int $fileSize = 0): void
    {
        $sftp = $this->sftp();
        $src  = 'ssh2.sftp://' . (int) $sftp . $this->root . $remotePath;

        $r = \App\Core\RangeRequest::parse((string) ($_SERVER['HTTP_RANGE'] ?? ''), $fileSize);
        $r->applyHeaders($fileSize);
        if ($r->unsatisfiable) { return; } // 416 already sent — no body

        $in = @fopen($src, 'rb');
        if (!$in) throw new \RuntimeException("SFTP stream: cannot open $remotePath");

        try {
            if ($r->start > 0) {
                if (fseek($in, $r->start) !== 0) {
                    // Some ssh2 wrappers can't seek — fall back to read-and-discard to the offset.
                    $skip = $r->start;
                    while ($skip > 0 && !feof($in)) {
                        $n = strlen((string) fread($in, (int) min($skip, 1 << 20)));
                        if ($n === 0) break;
                        $skip -= $n;
                    }
                }
            }
            $chunk     = max(1, (int) config('storage.chunk_size', 2 * 1024 * 1024));
            $remaining = $fileSize > 0 ? $r->length : PHP_INT_MAX;
            while ($remaining > 0 && !feof($in)) {
                if (connection_aborted()) break;
                $buf = (string) fread($in, max(1, (int) min($chunk, $remaining)));
                if ($buf === '') break;
                echo $buf;
                if (ob_get_level()) ob_flush();
                flush();
                $remaining -= strlen($buf);
            }
        } finally {
            fclose($in);
        }
    }

    public function delete(string $remotePath): bool
    {
        $sftp = $this->sftp();
        return (bool) @ssh2_sftp_unlink($sftp, $this->root . $remotePath);
    }

    public function exists(string $remotePath): bool
    {
        $sftp = $this->sftp();
        return file_exists('ssh2.sftp://' . (int) $sftp . $this->root . $remotePath);
    }

    // ── FileManagerInterface ──────────────────────────────────────────────

    public function listDirectory(string $path = ''): array
    {
        $sftp    = $this->sftp();
        $fullDir = rtrim($this->root . ltrim($path, '/'), '/');
        $handle  = @opendir('ssh2.sftp://' . (int) $sftp . $fullDir);

        if (!$handle) {
            throw new \RuntimeException("SFTP: cannot open directory: $fullDir");
        }

        $entries = [];
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') continue;

            $fullPath = $fullDir . '/' . $entry;
            $stat     = @ssh2_sftp_stat($sftp, $fullPath);

            // When stat fails (permission-denied entry / dangling symlink) leave size unknown
            // rather than reporting a misleading 0 bytes.
            $statOk = is_array($stat);
            $isDir  = $statOk && isset($stat['mode']) && ((int) $stat['mode'] & 0o170000) === 0o040000;
            $size   = $statOk ? ($stat['size'] ?? null) : null;
            $mtime  = $statOk ? ($stat['mtime'] ?? null) : null;

            $relPath = trim(($path !== '' ? rtrim($path, '/') . '/' : '') . $entry, '/');

            $entries[] = [
                'name'     => $entry,
                'path'     => $relPath,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => ($isDir || $size === null) ? null : (int) $size,
                'modified' => $mtime !== null ? (int) $mtime : null,
                'mime'     => $isDir ? null : $this->guessMime($entry),
                'is_dir'   => $isDir,
            ];
        }
        closedir($handle);

        usort($entries, fn (array $a, array $b): int => ($b['is_dir'] <=> $a['is_dir']) ?: strcasecmp((string) $a['name'], (string) $b['name']));

        return $entries;
    }

    public function makeDirectory(string $path): bool
    {
        $this->mkdirp($this->root . ltrim($path, '/'));
        return true;
    }

    public function move(string $from, string $to): bool
    {
        $sftp   = $this->sftp();
        $srcAbs = $this->root . ltrim($from, '/');
        $dstAbs = $this->root . ltrim($to, '/');
        $this->mkdirp(dirname($dstAbs));
        return (bool) @ssh2_sftp_rename($sftp, $srcAbs, $dstAbs);
    }

    /** SFTP has no server-side copy — proxy download+upload through a temp file. */
    public function copy(string $from, string $to): bool
    {
        $tmp = $this->get($from);
        try { return $this->put($tmp, $to); } finally { @unlink($tmp); }
    }

    public function deleteItem(string $path): bool
    {
        $sftp    = $this->sftp();
        $absPath = $this->root . ltrim($path, '/');
        $stat    = @ssh2_sftp_stat($sftp, $absPath);
        $isDir   = is_array($stat) && isset($stat['mode']) && ((int) $stat['mode'] & 0o170000) === 0o040000;

        if ($isDir) {
            $this->rmdirSftp($absPath);
            return true;
        }
        return (bool) @ssh2_sftp_unlink($sftp, $absPath);
    }

    public function download(string $path): string { return $this->get($path); }

    public function upload(string $localPath, string $remotePath): bool { return $this->put($localPath, $remotePath); }

    public function quota(): array
    {
        return [
            'driver'       => 'sftp',
            'label'        => 'SFTP (' . $this->host . ')',
            'url'          => $this->host . ':' . $this->port,
            'used'         => null,
            'free'         => null,
            'total'        => null,
            'used_human'   => '—',
            'free_human'   => '—',
            'total_human'  => '—',
            'percent_used' => null,
            'error'        => 'Quota not supported for SFTP',
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function sftp(): mixed
    {
        if ($this->sftp) return $this->sftp;

        if (!function_exists('ssh2_connect')) {
            throw new \RuntimeException('SftpDriver requires the PHP ssh2 extension (pecl install ssh2)');
        }

        // ssh2_connect() has no timeout argument and blocks for the full OS TCP
        // timeout (~2 min) on a dead host. Bound it via default_socket_timeout.
        $prevTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '15');
        try {
            $conn = @ssh2_connect($this->host, $this->port);
        } finally {
            ini_set('default_socket_timeout', (string) $prevTimeout);
        }
        if (!$conn) throw new \RuntimeException("SFTP: cannot connect to {$this->host}:{$this->port}");

        if ($this->privkeyPath !== '') {
            $ok = @ssh2_auth_pubkey_file($conn, $this->user, $this->privkeyPath . '.pub',
                $this->privkeyPath, $this->privkeyPass);
        } else {
            $ok = @ssh2_auth_password($conn, $this->user, $this->password);
        }
        if (!$ok) throw new \RuntimeException("SFTP: authentication failed for {$this->user}@{$this->host}");

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) throw new \RuntimeException('SFTP: could not init SFTP subsystem');

        $this->sftp = $sftp;
        return $sftp;
    }

    /** Create directory and all parents, similar to mkdir -p. */
    private function mkdirp(string $path): void
    {
        $sftp  = $this->sftp();
        $parts = explode('/', ltrim($path, '/'));
        $cur   = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            $cur .= '/' . $part;
            if (!file_exists('ssh2.sftp://' . (int) $sftp . $cur)) {
                @ssh2_sftp_mkdir($sftp, $cur, 0755, false);
            }
        }
    }

    /** Recursively delete a directory and all contents. */
    private function rmdirSftp(string $absPath): void
    {
        $sftp   = $this->sftp();
        $handle = @opendir('ssh2.sftp://' . (int) $sftp . $absPath);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $child = $absPath . '/' . $entry;
                $stat  = @ssh2_sftp_stat($sftp, $child);
                $isDir = is_array($stat) && isset($stat['mode']) && ((int) $stat['mode'] & 0o170000) === 0o040000;
                $isDir ? $this->rmdirSftp($child) : @ssh2_sftp_unlink($sftp, $child);
            }
            closedir($handle);
        }
        @ssh2_sftp_rmdir($sftp, $absPath);
    }

    private function guessMime(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'txt' => 'text/plain',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
