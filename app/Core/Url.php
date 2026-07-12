<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation for user-supplied URLs, and safe resolution of a public media URL
 * back to its on-disk path.
 *
 * safe() blocks javascript:/data:/file: schemes and malformed input — only
 * http(s) with a host survives — so a hostile string is never rendered into an
 * <img>/<video> src or stored as if it were a URL.
 *
 * mediaDiskPath() is the counterpart for the write side: it refuses to hand back
 * any path that escapes the upload root, so a DB column can never steer an
 * unlink()/rename() at an arbitrary file.
 */
final class Url
{
    /** The validated URL, or null when it is unsafe/unparseable. */
    public static function safe(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Reject control chars / whitespace smuggling.
        if (preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            return null;
        }

        $parts = parse_url($raw);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if (!filter_var($raw, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $raw;
    }

    /**
     * Resolve a media URL (absolute or path-only, e.g. "/media/a/b.jpg") to an
     * on-disk path under the upload root — but ONLY if it stays inside that
     * root. Returns null for a foreign URL, a traversal attempt, or anything
     * that resolves outside the root.
     *
     * The returned path may not exist yet (realpath() fails on missing files);
     * callers should still is_file() before unlinking. Use this before ANY
     * unlink()/rename() of a path derived from a DB column — even a
     * server-generated one, as defense in depth.
     *
     * @param string|null $root defaults to config('storage.upload_dir')
     */
    public static function mediaDiskPath(?string $url, ?string $root = null): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // Check the RAW input for NUL first: parse_url() silently rewrites a NUL
        // byte to '_', so a check after it would never fire.
        if (str_contains($url, "\0")) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // Decode once so percent-encoded traversal (%2e%2e) and an encoded NUL
        // (%00) are both caught below.
        $path = rawurldecode($path);
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        $rootPath = $root ?? (string) config('storage.upload_dir', base_path('storage/uploads'));
        $realRoot = realpath($rootPath);
        if ($realRoot === false) {
            return null;
        }

        // Strip the public prefix ("/media/foo.jpg" → "foo.jpg") so the rest is
        // resolved relative to the upload root.
        $prefix = trim((string) config('storage.media_url_prefix', '/media'), '/');
        $rel = ltrim($path, '/');
        if ($prefix !== '' && str_starts_with($rel, $prefix . '/')) {
            $rel = substr($rel, strlen($prefix) + 1);
        }
        if ($rel === '') {
            return null;
        }

        $candidate = $realRoot . '/' . $rel;
        $real = realpath($candidate);

        // If it resolved, it must still be inside the root. If it didn't (file
        // not created yet), the traversal checks above already cleared it.
        if ($real !== false && !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real !== false ? $real : $candidate;
    }
}
