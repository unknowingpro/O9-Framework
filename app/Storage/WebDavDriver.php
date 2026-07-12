<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * WebDavDriver — stores files on a WebDAV server (Nextcloud, ownCloud, Hetzner
 * Storage Box, or any RFC-4918-compliant server).
 *
 * Directory listing via PROPFIND, move/rename via MOVE, server-side copy via
 * COPY, directory creation via MKCOL, quota via PROPFIND.
 *
 * Config keys:
 *   url       Base WebDAV URL (e.g. https://u541173.your-storagebox.de/)
 *   user      username
 *   password  password / app-password
 *   prefix    optional sub-folder inside the WebDAV root
 *   label     human-readable name shown in the storage API (optional)
 */
class WebDavDriver implements StorageDriverInterface, FileManagerInterface
{
    private string $base;         // full base URL including prefix, trailing slash
    private string $root;         // server root URL (no prefix) for quota PROPFIND
    private string $user;
    private string $pass;
    private string $label;
    private string $instanceName; // e.g. "webdav", "webdav1", "webdav2"

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg, string $instanceName = 'webdav')
    {
        $rawUrl = rtrim((string) ($cfg['url'] ?? ''), '/') . '/';
        // Accept both 'prefix' and legacy 'root' as the sub-folder key.
        $prefix = trim((string) ($cfg['prefix'] ?? $cfg['root'] ?? ''), '/');
        $this->root = $rawUrl;
        $this->base = $prefix !== '' ? $rawUrl . $prefix . '/' : $rawUrl;
        // Accept both 'user' and legacy 'username'.
        $this->user = (string) ($cfg['user'] ?? $cfg['username'] ?? '');
        $this->pass = (string) ($cfg['password'] ?? '');
        $this->label = (string) ($cfg['label'] ?? $instanceName);
        $this->instanceName = $instanceName;

        if ($rawUrl === '/' || $this->user === '') {
            throw new \RuntimeException("WebDavDriver[$instanceName]: url and user are required");
        }
    }

    public function name(): string  { return $this->instanceName; }
    public function label(): string { return $this->label; }

    // ── StorageDriverInterface ────────────────────────────────────────────

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        $this->mkcolPath(dirname($remotePath));
        $url       = $this->url($remotePath);
        $localSize = (int) filesize($tmpPath);

        // HEAD check: if the file already exists with the same size, skip upload.
        // If size differs, the file was truncated/corrupted — overwrite it.
        $hch = curl_init($url);
        curl_setopt_array($hch, [
            CURLOPT_CUSTOMREQUEST  => 'HEAD',
            CURLOPT_NOBODY         => true,
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
        ]);
        curl_exec($hch);
        $hcode      = (int) curl_getinfo($hch, CURLINFO_HTTP_CODE);
        $remoteSize = (int) curl_getinfo($hch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($hch);

        // File exists and size matches — already uploaded correctly, skip.
        if ($hcode === 200 && $remoteSize === $localSize) return true;

        // File exists but size differs — force overwrite with If-Match: *
        $extraHeaders = $hcode === 200 ? ['If-Match: *'] : [];

        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) throw new \RuntimeException("WebDAV PUT: cannot open local file: $tmpPath");
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $localSize,
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $extraHeaders,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (is_resource($fh)) fclose($fh);

        if ($err) throw new \RuntimeException("WebDAV PUT cURL error: $err");
        if ($code >= 300) throw new \RuntimeException("WebDAV PUT failed HTTP $code => $url");
        return true;
    }

    public function get(string $remotePath): string
    {
        $url = $this->url($remotePath);

        // Prefer app storage/tmp (usually more space than /tmp) for large files.
        $tmpDir = is_writable(base_path('storage/tmp'))
            ? base_path('storage/tmp')
            : sys_get_temp_dir();

        $tmp = tempnam($tmpDir, 'o9_dav_');
        if ($tmp === false) {
            throw new \RuntimeException('WebDAV GET: could not create temp file in ' . $tmpDir);
        }

        $fh = fopen($tmp, 'wb');
        if ($fh === false) { @unlink($tmp); throw new \RuntimeException('WebDAV GET: could not open temp file'); }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD         => "{$this->user}:{$this->pass}",
            CURLOPT_FILE            => $fh,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_TIMEOUT         => 0,   // no upper timeout — large files
            CURLOPT_LOW_SPEED_LIMIT => 512, // abort if stalled < 512 B/s
            CURLOPT_LOW_SPEED_TIME  => 60,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($err)         { @unlink($tmp); throw new \RuntimeException("WebDAV GET cURL error: $err"); }
        if ($code >= 300) { @unlink($tmp); throw new \RuntimeException("WebDAV GET failed HTTP $code for $url"); }
        return $tmp;
    }

    /**
     * Stream a remote file directly to PHP output — zero temp-file buffering.
     * Handles HTTP Range requests. The caller must send all headers
     * (Content-Type, Content-Disposition, etc.) BEFORE calling this method.
     */
    public function stream(string $remotePath, int $fileSize = 0): void
    {
        $url = $this->url($remotePath);

        $r = \App\Core\RangeRequest::parse((string) ($_SERVER['HTTP_RANGE'] ?? ''), $fileSize);
        $r->applyHeaders($fileSize);
        if ($r->unsatisfiable) { return; } // 416 already sent — no body

        $ch   = curl_init($url);
        $opts = [
            CURLOPT_USERPWD         => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => 512,
            CURLOPT_LOW_SPEED_TIME  => 60,
            CURLOPT_WRITEFUNCTION   => static function ($ch, string $chunk): int {
                if (connection_aborted()) return -1;
                echo $chunk;
                if (ob_get_level()) ob_flush();
                flush();
                return strlen($chunk);
            },
        ];
        if ($cr = $r->curlRange()) {
            $opts[CURLOPT_RANGE] = $cr;
        }

        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("WebDAV stream cURL error: $err");
        if ($code >= 300) { // 206 Partial Content is < 300 and always allowed
            throw new \RuntimeException("WebDAV stream failed HTTP $code");
        }
    }

    public function delete(string $remotePath): bool
    {
        $code = $this->httpDelete($this->url($remotePath));
        // Apache/mod_dav (Hetzner) 301-redirects a DELETE on a collection requested
        // without a trailing slash — retry with one so directory deletion works.
        if ($code === 301 || $code === 308) {
            $code = $this->httpDelete(rtrim($this->url($remotePath), '/') . '/');
        }
        return $code < 300 || $code === 404;
    }

    private function httpDelete(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    public function exists(string $remotePath): bool
    {
        $url = $this->url($remotePath);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 || $code === 207;
    }

    // ── FileManagerInterface ──────────────────────────────────────────────

    public function listDirectory(string $path = ''): array
    {
        $url = rtrim($this->url($path), '/') . '/';
        if ($path === '') $url = $this->base;

        $body = '<?xml version="1.0"?>'
              . '<d:propfind xmlns:d="DAV:">'
              . '<d:prop>'
              . '<d:resourcetype/>'
              . '<d:getcontentlength/>'
              . '<d:getcontenttype/>'
              . '<d:getlastmodified/>'
              . '</d:prop>'
              . '</d:propfind>';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Depth: 1',
                'Content-Type: application/xml; charset=utf-8',
            ],
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("WebDAV PROPFIND cURL error: $err");

        // 207 Multi-Status = success (standard WebDAV PROPFIND response).
        if ($code === 207) {
            return $this->parsePropfind((string) $xml, $path);
        }

        // 404/405 = directory does not exist yet.
        // Hetzner Storage Box returns 405 (not 404) for PROPFIND on a
        // non-existent path. Auto-create with MKCOL and return empty list.
        if ($code === 404 || $code === 405) {
            $createPath = $path === '' ? ltrim(str_replace($this->root, '', $this->base), '/') : $path;
            $this->mkcolPath($createPath);
            return [];
        }

        throw new \RuntimeException("WebDAV PROPFIND failed HTTP $code at $url");
    }

    /** @return list<array{name: string, path: string, type: string, size: int|null, modified: int|null, mime: string|null, is_dir: bool}> */
    private function parsePropfind(string $xml, string $basePath): array
    {
        // DOMXPath with the DAV: namespace registered — servers mix <D:…>,
        // <lp1:…>, <g0:…> prefixes, all bound to DAV:, so context-relative
        // queries need namespace-aware resolution rather than SimpleXML's
        // (unreliable here) child-node access.
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // XXE hardening: LIBXML_NONET blocks network access during parsing and we
        // never pass LIBXML_NOENT, so external/parameter entities are not
        // substituted — a malicious PROPFIND body can't read local files.
        if (!$dom->loadXML($xml, LIBXML_NONET)) return [];
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('d', 'DAV:');

        $entries = [];
        $first   = true;

        foreach ($xp->query('//d:response') ?: [] as $response) {
            if ($first) { $first = false; continue; }
            if (!$response instanceof \DOMElement) { continue; } // //d:response always yields elements

            $href       = $xp->evaluate('string(d:href)', $response);
            $collection = $xp->query('d:propstat/d:prop/d:resourcetype/d:collection', $response);
            $isDir      = $collection !== false && $collection->length > 0;
            $lenStr   = $xp->evaluate('string(d:propstat/d:prop/d:getcontentlength)', $response);
            $size     = $lenStr !== '' ? (int) $lenStr : null;
            $ct       = $xp->evaluate('string(d:propstat/d:prop/d:getcontenttype)', $response);
            $mime     = $ct !== '' ? (string) $ct : null;
            $lmStr    = $xp->evaluate('string(d:propstat/d:prop/d:getlastmodified)', $response);
            $modified = $lmStr !== '' ? (strtotime((string) $lmStr) ?: null) : null;

            $relPath = $this->hrefToPath((string) $href);
            $name    = basename(rtrim($relPath, '/'));
            if ($name === '') continue;

            $entries[] = [
                'name'     => $name,
                'path'     => trim(($basePath !== '' ? rtrim($basePath, '/') . '/' : '') . $name, '/'),
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? null : $size,
                'modified' => $modified ?: null,
                'mime'     => $isDir ? null : ($mime ?: $this->guessMime($name)),
                'is_dir'   => $isDir,
            ];
        }

        usort($entries, fn (array $a, array $b): int => ($b['is_dir'] <=> $a['is_dir']) ?: strcasecmp((string) $a['name'], (string) $b['name']));

        return $entries;
    }

    private function hrefToPath(string $href): string
    {
        $basePath = (string) (parse_url($this->base, PHP_URL_PATH) ?: '/');
        $hrefPath = (string) (parse_url($href, PHP_URL_PATH) ?: $href);
        if (str_starts_with($hrefPath, $basePath)) {
            return urldecode(substr($hrefPath, strlen($basePath)));
        }
        return urldecode(ltrim($hrefPath, '/'));
    }

    public function makeDirectory(string $path): bool
    {
        $this->mkcolPath($path);
        return true;
    }

    public function move(string $from, string $to): bool
    {
        $srcUrl  = $this->url($from);
        $destUrl = $this->url($to);
        $this->mkcolPath(dirname($to));

        $ch = curl_init($srcUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'MOVE',
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Destination: ' . $destUrl, 'Overwrite: T'],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("WebDAV MOVE cURL error: $err");
        return in_array($code, [201, 204], true);
    }

    public function copy(string $from, string $to): bool
    {
        $srcUrl  = $this->url($from);
        $destUrl = $this->url($to);
        $this->mkcolPath(dirname($to));

        $ch = curl_init($srcUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'COPY',
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Destination: ' . $destUrl, 'Overwrite: T', 'Depth: infinity'],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("WebDAV COPY cURL error: $err");
        return in_array($code, [201, 204], true);
    }

    public function deleteItem(string $path): bool { return $this->delete($path); }
    public function download(string $path): string { return $this->get($path); }
    public function upload(string $localPath, string $remotePath): bool { return $this->put($localPath, $remotePath); }

    // ── Quota ───────────────────────────────────────────────────────────────

    public function quota(): array
    {
        $base = [
            'driver' => $this->instanceName, 'label' => $this->label, 'url' => $this->root,
            'used' => 0, 'free' => 0, 'total' => 0, 'used_human' => '—', 'free_human' => '—',
            'total_human' => '—', 'percent_used' => 0.0, 'error' => null,
        ];

        $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop>'
              . '<d:quota-available-bytes/><d:quota-used-bytes/>'
              . '</d:prop></d:propfind>';

        $ch = curl_init($this->root);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Depth: 0', 'Content-Type: application/xml; charset=utf-8'],
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code >= 300) { $base['error'] = $err ?: "PROPFIND HTTP $code"; return $base; }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadXML((string) $xml, LIBXML_NONET)) { $base['error'] = 'XML parse error'; return $base; }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('d', 'DAV:');

        $freeStr = $xp->evaluate('string(//d:quota-available-bytes)');
        $usedStr = $xp->evaluate('string(//d:quota-used-bytes)');
        $free = $freeStr !== '' ? (int) $freeStr : -1;
        $used = $usedStr !== '' ? (int) $usedStr : 0;

        if ($free < 0) {
            $base['free'] = $base['total'] = 0;
            $base['free_human'] = $base['total_human'] = 'Unlimited';
            $base['used'] = $used;
            $base['used_human'] = $this->humanSize($used);
        } else {
            $total = $used + $free;
            $base = array_merge($base, [
                'used' => $used, 'free' => $free, 'total' => $total,
                'used_human' => $this->humanSize($used), 'free_human' => $this->humanSize($free),
                'total_human' => $this->humanSize($total),
                'percent_used' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
            ]);
        }
        return $base;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function url(string $remotePath): string
    {
        return $this->base . ltrim($remotePath, '/');
    }

    private function mkcolPath(string $dir): void
    {
        if ($dir === '.' || $dir === '') return;
        $parts   = explode('/', trim($dir, '/'));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            $current .= ($current !== '' ? '/' : '') . $part;
            $ch = curl_init($this->base . $current);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'MKCOL',
                CURLOPT_USERPWD        => "{$this->user}:{$this->pass}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . ($units[$i] ?? 'B');
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
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
