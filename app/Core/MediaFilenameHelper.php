<?php
declare(strict_types=1);

namespace App\Core;

/** Safe filename / extension / mime helpers for user-uploaded media. */
final class MediaFilenameHelper
{
    /**
     * Sanitize an untrusted filename for safe storage/display: strips path
     * separators, null bytes, and control characters, collapses whitespace,
     * and caps length. Does NOT change the extension.
     */
    public static function sanitize(string $filename, int $maxLength = 200): string
    {
        // Keep only the basename — reject any path component an attacker embedded.
        $filename = basename(str_replace('\\', '/', $filename));
        // Strip null bytes and control characters.
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        // Collapse runs of whitespace to a single space, trim.
        $filename = trim((string) preg_replace('/\s+/', ' ', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file';
        }
        if (strlen($filename) > $maxLength) {
            $ext  = self::extension($filename);
            $stem = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;
            $keep = $maxLength - ($ext !== '' ? strlen($ext) + 1 : 0);
            $stem = substr($stem, 0, max(1, $keep));
            $filename = $ext !== '' ? "{$stem}.{$ext}" : $stem;
        }
        return $filename;
    }

    /** Lowercased extension, without the dot ('' if none). */
    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /** Filename without its extension. */
    public static function stem(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;
    }

    /** Extension-based MIME type guess for common upload types (no fileinfo dependency). */
    public static function guessMime(string $filename): string
    {
        static $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip',
            'tar' => 'application/x-tar', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv', 'txt' => 'text/plain', 'json' => 'application/json', 'xml' => 'application/xml',
            'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        ];
        return $map[self::extension($filename)] ?? 'application/octet-stream';
    }

    /**
     * Sniff the real content type from the file's bytes (magic numbers) via
     * the bundled fileinfo extension, rather than trusting the client-supplied
     * filename's extension. Falls back to the extension-based guess when
     * fileinfo is unavailable or the sniff is inconclusive, so callers always
     * get a usable value.
     */
    public static function detectMime(string $absPath, string $originalName): string
    {
        if (function_exists('finfo_open') && is_file($absPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $absPath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    return $detected;
                }
            }
        }
        return self::guessMime($originalName);
    }

    /**
     * A safe, collision-resistant name for storing an uploaded file: a random
     * hex stem preserving the original (sanitized) extension. The original
     * name is never trusted as a storage path component.
     */
    public static function safeStoredName(string $originalName, ?string $fallbackExt = null): string
    {
        $ext = self::extension($originalName);
        if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            $ext = $fallbackExt !== null ? strtolower(ltrim($fallbackExt, '.')) : '';
        }
        $stem = bin2hex(random_bytes(16));
        return $ext !== '' ? "{$stem}.{$ext}" : $stem;
    }
}
