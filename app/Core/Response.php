<?php
declare(strict_types=1);

namespace App\Core;

/**
 * JSON / HTML response helper. Enforces the standard `{ok, data, error, meta}`
 * envelope on every JSON reply — never `echo json_encode()` directly.
 */
final class Response
{
    /**
     * @param array<string, mixed> $payload
     *
     * Builds the response as an {@see HttpResponse} and throws it — App::run()
     * (and the global exception handler, as a fallback) catch it and emit it.
     * This is the same short-circuit mechanism View::redirect()/Router 404s
     * already use, extended to success responses so a controller action can be
     * unit-tested by catching HttpResponse instead of needing a process exit.
     */
    public static function json(array $payload, int $status = 200): never
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            error_log('Response::json encoding failed: ' . json_last_error_msg() . ' — payload keys: ' . implode(',', array_keys($payload)));
            $fallback = '{"ok":false,"data":null,"error":{"code":"encoding_error","message":"response encoding failed"}}';
            throw new HttpResponse(500, $fallback, self::jsonHeaders(strlen($fallback)));
        }
        throw new HttpResponse($status, $encoded, self::jsonHeaders(strlen($encoded)));
    }

    /** @return array<string, string> */
    private static function jsonHeaders(int $contentLength): array
    {
        $headers = [
            'Content-Type'   => 'application/json; charset=utf-8',
            'X-API-Version'  => '1',
            'Content-Length' => (string) $contentLength,
        ];
        if (ApiContext::active()) {
            $headers['X-Request-Id'] = ApiContext::id();
        }
        return $headers;
    }

    /** @param array<string, mixed> $meta */
    public static function ok(mixed $data = null, array $meta = []): never
    {
        $body = ['ok' => true, 'data' => $data, 'error' => null];
        if (!empty($meta)) {
            $body['meta'] = $meta;
        }
        self::json($body, 200);
    }

    public static function created(mixed $data = null): never
    {
        self::json(['ok' => true, 'data' => $data, 'error' => null], 201);
    }

    /**
     * A cacheable GET response: emits an `ETag` for the body and returns `304 Not Modified` (no
     * body) when the client's `If-None-Match` already has it. Use only for GETs whose body is a
     * pure function of the request (no per-call timestamps). Marked `private` cache so shared
     * caches don't serve one user's data to another.
     *
     * @param array<string, mixed> $meta
     */
    public static function okCached(mixed $data = null, array $meta = []): never
    {
        $body = ['ok' => true, 'data' => $data, 'error' => null];
        if (!empty($meta)) {
            $body['meta'] = $meta;
        }
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            error_log('Response::okCached encoding failed: ' . json_last_error_msg());
            $fallback = '{"ok":false,"data":null,"error":{"code":"encoding_error","message":"response encoding failed"}}';
            throw new HttpResponse(500, $fallback, self::jsonHeaders(strlen($fallback)));
        }
        $json = $encoded;
        $etag = self::etagFor($json);
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, must-revalidate'];
        if (ApiContext::active()) {
            $headers['X-Request-Id'] = ApiContext::id();
        }
        if (self::etagSatisfies((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
            throw new HttpResponse(304, '', $headers);
        }
        $headers['Content-Type']  = 'application/json; charset=utf-8';
        $headers['X-API-Version'] = '1';
        throw new HttpResponse(200, $json, $headers);
    }

    /** Strong ETag for a serialized body. */
    public static function etagFor(string $serialized): string
    {
        return '"' . sha1($serialized) . '"';
    }

    /** Does an `If-None-Match` header (comma-list, or `*`) satisfy this ETag? */
    public static function etagSatisfies(string $ifNoneMatch, string $etag): bool
    {
        $inm = trim($ifNoneMatch);
        if ($inm === '') {
            return false;
        }
        if ($inm === '*') {
            return true;
        }
        foreach (explode(',', $inm) as $candidate) {
            $c       = trim($candidate);
            $stripped = str_starts_with($c, 'W/') ? substr($c, 2) : $c;
            if ($stripped === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * A list response with a standard `meta.pagination` block. Pass $total for page-based endpoints
     * (adds total + pages); omit it for offset/cursor endpoints (has_more is inferred from a full
     * page).
     * @param list<mixed> $items
     * @param array<string,mixed> $meta extra top-level meta merged alongside `pagination`.
     */
    public static function paginated(array $items, int $page, int $perPage, ?int $total = null, array $meta = []): never
    {
        self::ok($items, ['pagination' => Paginator::envelope(count($items), $page, $perPage, $total)] + $meta);
    }

    /** @param array<string, mixed>|null $details */
    public static function error(string $code, string $message, int $status = 400, ?array $details = null): never
    {
        $err = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $err['details'] = $details;
        }
        // Server faults (5xx) emitted here never reach set_exception_handler, so
        // without this they'd leave NO trace in the system log. Capture them with a
        // reference the client can quote — the same contract a thrown HttpException
        // gets. 4xx (auth/validation/rate-limit) are expected client errors and stay
        // out of the DB log to avoid noise. (Maintenance 503 / geo-block bypass this.)
        if ($status >= 500) {
            $ref = strtoupper(bin2hex(random_bytes(4)));
            $err['ref'] = $ref;
            if (class_exists(Logger::class)) {
                Logger::error($message !== '' ? $message : ('response.' . $code), ['ref' => $ref, 'code' => $code, 'status' => $status], 'error');
            }
        }
        self::json(['ok' => false, 'data' => null, 'error' => $err], $status);
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::error('not_found', $message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error('unauthorized', $message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error('forbidden', $message, 403);
    }

    public static function html(string $html, int $status = 200): never
    {
        throw new HttpResponse($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Stream a generated file as a download. `$filename` is sanitised to a
     * safe basename so a caller-supplied name can't inject header bytes.
     */
    public static function download(string $body, string $filename, string $contentType = 'application/octet-stream'): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'download';
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    /**
     * Stream a download whose body is produced incrementally — the $writer is
     * handed a php://output stream and emits chunks (e.g. Csv::stream), so a
     * large export is sent as it's generated instead of being buffered whole in
     * memory. Output buffering is torn down and X-Accel-Buffering disabled so
     * nginx forwards bytes immediately; no Content-Length (the size is unknown
     * up front — the response is chunked).
     */
    public static function streamDownload(string $filename, string $contentType, callable $writer): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'download';
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            $writer($out);
            fclose($out);
        }
        exit;
    }

    /**
     * Serve a locally-stored file by its stored relative name. Uses Nginx
     * X-Accel-Redirect (zero PHP I/O, native Range) when the file is on local
     * disk under config('storage.upload_dir'); otherwise streams it from disk.
     */
    public static function file(string $storedName, string $name, string $mime, int $size, int $speedLimit = 0, string $disposition = 'attachment'): never
    {
        $root     = rtrim((string) config('storage.upload_dir', BASE_PATH . '/storage/uploads'), '/\\') . '/';
        $realPath = $root . $storedName;
        if (is_file($realPath)) {
            $internalUri = rtrim((string) config('storage.nginx_accel_prefix', '/protected-files/'), '/') . '/' . ltrim($storedName, '/');
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', '\\'], ['\\"', '\\\\'], $name) . '"');
            header('Content-Length: ' . $size);
            header('Accept-Ranges: bytes');
            header('X-Accel-Redirect: ' . $internalUri);
            if ($speedLimit > 0) {
                header('X-Accel-Limit-Rate: ' . $speedLimit);
            }
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            exit;
        }
        self::fileFromPath($realPath, $name, $mime, $size, false, $speedLimit, $disposition);
    }

    /**
     * Stream a file from an absolute path (chunked, optional speed limit, optional
     * delete-after for tmp files). Used by drivers that resolve to a local tmp copy.
     */
    public static function fileFromPath(string $absPath, string $name, string $mime, int $size, bool $deleteTmp = false, int $speedLimit = 0, string $disposition = 'attachment'): never
    {
        if (!is_file($absPath)) {
            self::error('file_not_found', 'File not found on storage', 404);
        }

        // Open the file handle BEFORE committing headers so a failure here can
        // still return a proper 500 rather than a truncated 200 with no body.
        $fh = fopen($absPath, 'rb');
        if ($fh === false) {
            // Do NOT delete $absPath on open failure — it may still be valid.
            self::error('file_open_failed', 'Could not open file for streaming', 500);
        }

        // Honour an HTTP Range request (resume / seek) — drivers without native ranged
        // streaming reach a local tmp file here, so this gives FTP/SMB (and throttled
        // downloads) byte-range support too.
        $range = RangeRequest::parse($_SERVER['HTTP_RANGE'] ?? null, $size);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-transform');
        header('X-Content-Type-Options: nosniff');
        $range->applyHeaders($size);   // 200/206 + Content-Length/Content-Range
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        $chunk       = (int) config('storage.chunk_size', 2 * 1024 * 1024);
        $windowStart = microtime(true);
        $windowSent  = 0;
        $remaining   = $range->length;
        if ($range->start > 0) {
            fseek($fh, $range->start);
        }
        while ($remaining > 0 && !feof($fh)) {
            $buf = fread($fh, max(1, (int) min($chunk, $remaining)));
            if ($buf === false) { break; }
            echo $buf;
            flush();
            $n           = strlen($buf);
            $remaining  -= $n;
            $windowSent += $n;
            if ($speedLimit > 0 && $windowSent >= $speedLimit) {
                $elapsed = microtime(true) - $windowStart;
                if ($elapsed < 1.0) {
                    usleep((int) ((1.0 - $elapsed) * 1_000_000));
                }
                $windowSent  = 0;
                $windowStart = microtime(true);
            }
        }
        fclose($fh);
        if ($deleteTmp) {
            @unlink($absPath);
        }
        exit;
    }
}
