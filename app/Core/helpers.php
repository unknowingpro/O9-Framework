<?php
declare(strict_types=1);

/*
 * Global procedural helpers — sugar over Core services only, no logic here.
 *
 * Foundation set. Helpers that depend on later-loaded components (view(),
 * component(), redirect(), abort(), __(), csrf_*(), flash*(), setting(),
 * current_user()) are registered alongside those components so this file
 * never references a class that does not exist yet.
 */

use App\Core\Env;

if (!function_exists('env')) {
    /** Read an environment variable (server env > .env file), typed coercion. */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    /** Absolute path inside the project root. */
    function base_path(string $append = ''): string
    {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $append === '' ? $root : $root . '/' . ltrim($append, '/');
    }
}

if (!function_exists('storage_path')) {
    /** Absolute path inside the writable storage directory. */
    function storage_path(string $append = ''): string
    {
        return base_path('storage' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }
}

if (!function_exists('config')) {
    /**
     * Read a config value using dot notation, e.g. config('app.name').
     * Config files live in /config and return arrays; config('app') returns
     * the whole file.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];
        [$file, $path] = array_pad(explode('.', $key, 2), 2, null);
        if (!isset($cache[$file])) {
            $f = base_path("config/{$file}.php");
            $cache[$file] = is_file($f) ? require $f : [];
        }
        $value = $cache[$file];
        if ($path === null) {
            return $value;
        }
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (!function_exists('now')) {
    /** Current unix timestamp (seconds). */
    function now(): int
    {
        return time();
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value for safe output in templates. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('str_random')) {
    /** URL-safe random token of the given byte length (hex-encoded). */
    function str_random(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }
}

if (!function_exists('random_token')) {
    /** A random hex token (link codes, job ids, etc.). */
    function random_token(int $bytes = 20): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }
}

if (!function_exists('uuid')) {
    /** Generate a random RFC 4122 version-4 UUID. */
    function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

if (!function_exists('uuid4')) {
    /** Alias kept for call-site compatibility across the O9 projects. */
    function uuid4(): string
    {
        return uuid();
    }
}

if (!function_exists('url')) {
    /** Absolute URL for an app path, based on config('app.url'). */
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Cache-busting URL for a public asset: '/assets/css/main.css?v=<mtime>'.
     * The version is the file's mtime, so it busts automatically whenever the
     * file changes — never hand-edited.
     */
    function asset(string $path): string
    {
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }
        $file = base_path('public/' . ltrim($path, '/'));
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return $cache[$path] = '/' . ltrim($path, '/') . '?v=' . $v;
    }
}

if (!function_exists('asset_module')) {
    /**
     * URL for an ES-module entry, versioned with the SHARED stamp (written by
     * `console assets:stamp` to public/assets/.assetver) so an entry and its
     * internal imports resolve to the SAME ?v= — else the browser loads two
     * copies and module singletons break. Falls back to mtime pre-stamp.
     */
    function asset_module(string $path): string
    {
        static $ver = null;
        if ($ver === null) {
            $f = base_path('public/assets/.assetver');
            $ver = is_file($f) ? trim((string) file_get_contents($f)) : '';
        }
        if ($ver !== '') {
            return '/' . ltrim($path, '/') . '?v=' . $ver;
        }
        return asset($path);
    }
}

if (!function_exists('app_tmp_dir')) {
    /**
     * Scratch directory for in-flight transfer temp files. Uses
     * config('storage.tmp_dir') when set and writable — point it at a mounted
     * volume to keep multi-GB transfers off the local disk. Falls back to the
     * system temp dir so a bad mount degrades instead of breaking transfers.
     */
    function app_tmp_dir(): string
    {
        $d = (string) config('storage.tmp_dir', '');
        if ($d !== '' && is_dir($d) && is_writable($d)) {
            return rtrim($d, '/');
        }
        return sys_get_temp_dir();
    }
}

if (!function_exists('human_size')) {
    /** Format a byte count as a human-readable size (e.g. 1.5 GB). */
    function human_size(int $bytes, int $decimals = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), $decimals) . ' ' . $units[$i];
    }
}

if (!function_exists('safe_origin_or')) {
    /**
     * Returns $url only if it's same-origin with the current request,
     * otherwise $fallback. Same-origin means the URL is relative (no
     * scheme/host) or its host matches HTTP_HOST (port-agnostic). Use for any
     * user-controlled "where to go next" string — HTTP_REFERER, form
     * `return_to`, query `next` — that ends up fed to redirect().
     */
    function safe_origin_or(string $url, string $fallback): string
    {
        if ($url === '') {
            return $fallback;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }
        if (empty($parts['scheme']) && empty($parts['host'])) {
            return $url;
        }
        $ourHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($ourHost === '' || ($parts['host'] ?? '') === '') {
            return $fallback;
        }
        $candidateHost = strtolower((string) $parts['host']);
        $myHost        = strtolower((string) preg_replace('/:\d+$/', '', $ourHost));
        if ($candidateHost !== $myHost) {
            return $fallback;
        }
        return $url;
    }
}

if (!function_exists('safe_back')) {
    /**
     * Redirect-back target: HTTP_REFERER when same-origin, else $fallback.
     * Without this guard every `redirect(HTTP_REFERER)` is an open redirect.
     */
    function safe_back(string $fallback): string
    {
        return safe_origin_or((string) ($_SERVER['HTTP_REFERER'] ?? ''), $fallback);
    }
}

if (!function_exists('format_number')) {
    /**
     * Number formatting via intl when available (en_US default locale),
     * falling back to a plain cast when the extension is missing.
     */
    function format_number(int|float $value, ?string $locale = null): string
    {
        if (!class_exists('NumberFormatter')) {
            return (string) $value;
        }
        $intl = $locale ?: 'en_US';
        static $cache = [];
        $fmt = $cache[$intl] ??= new NumberFormatter($intl, NumberFormatter::DECIMAL);
        return (string) $fmt->format($value);
    }
}

if (!function_exists('format_currency')) {
    function format_currency(int|float $amount, ?string $currency = null, ?string $locale = null): string
    {
        $currency = $currency ?: 'USD';
        if (!class_exists('NumberFormatter')) {
            return number_format((float) $amount, 2) . ' ' . $currency;
        }
        $intl = $locale ?: 'en_US';
        static $cache = [];
        $fmt = $cache[$intl . '|' . $currency] ??= new NumberFormatter($intl, NumberFormatter::CURRENCY);
        return (string) $fmt->formatCurrency((float) $amount, $currency);
    }
}

if (!function_exists('format_date')) {
    /**
     * Locale + calendar aware date formatting. fa → Jalali, ar → Hijri, the
     * rest → Gregorian, with locale-appropriate digits. $dateLen / $timeLen
     * are IntlDateFormatter constants: FULL=0, LONG=1, MEDIUM=2, SHORT=3,
     * NONE=-1. Default: MEDIUM date + SHORT time; pass $timeLen = -1 for
     * date-only.
     */
    function format_date(int|string $when, ?string $locale = null, int $dateLen = 2, int $timeLen = 3): string
    {
        $ts = is_int($when) ? $when : (strtotime($when) ?: time());
        if (!class_exists('IntlDateFormatter')) {
            return $timeLen === -1 ? date('Y-m-d', $ts) : date('Y-m-d H:i', $ts);
        }
        $intl = $locale ?: 'en_US';
        static $cache = [];
        $cacheKey = $intl . '|' . $dateLen . '|' . $timeLen;
        // TRADITIONAL makes ICU honor the locale's @calendar= keyword
        // (Jalali for fa, Hijri for ar); Gregorian for everything else.
        $fmt = $cache[$cacheKey] ??= new IntlDateFormatter(
            $intl,
            $dateLen,
            $timeLen,
            date_default_timezone_get() ?: 'UTC',
            IntlDateFormatter::TRADITIONAL
        );
        return (string) $fmt->format($ts);
    }
}
