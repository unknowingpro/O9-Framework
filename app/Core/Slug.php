<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Slug/username helpers. Produces lowercase ascii [a-z0-9-] tokens suitable for
 * URLs. Lossy for non-Latin scripts — text that transliterates to nothing (fa,
 * ar, zh...) yields an empty slug, so callers should fall back to shortId()/
 * shortcode() rather than assume make() always returns something.
 */
final class Slug
{
    /**
     * Slugify a string: transliterate to ASCII, lowercase, replace runs of
     * non-alphanumerics with `-`, trim dashes. Returns '' when the input
     * carries no Latin characters.
     */
    public static function make(string $input, int $maxLen = 60): string
    {
        $s = trim($input);
        if ($s === '') {
            return '';
        }

        // Transliteration when iconv is available — degrades cleanly otherwise.
        if (function_exists('iconv')) {
            $maybe = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($maybe !== false) {
                $s = $maybe;
            }
        }

        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        if (strlen($s) > $maxLen) {
            $s = rtrim(substr($s, 0, $maxLen), '-');
        }

        return $s;
    }

    /** Short base36 token used to deduplicate generated slugs. */
    public static function shortId(int $bytes = 4): string
    {
        return strtolower(base_convert(bin2hex(random_bytes(max(1, $bytes))), 16, 36));
    }

    /**
     * Instagram-style random shortcode: mixed-case base62, no separators.
     * 11 chars of base62 ≈ 5.2e19 combinations — collision-safe at scale.
     */
    public static function shortcode(int $len = 11): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Pick a value that isn't taken yet, appending `-2`, `-3`, ... and finally a
     * random suffix. $exists returns true when a candidate is already in use —
     * typically a `SELECT 1 FROM t WHERE slug = ?` closure.
     *
     * @param callable(string): bool $exists
     */
    public static function unique(string $base, callable $exists, int $maxAttempts = 8): string
    {
        if ($base === '') {
            return self::shortId(4);
        }
        if (!$exists($base)) {
            return $base;
        }
        for ($i = 2; $i <= $maxAttempts; $i++) {
            $candidate = $base . '-' . $i;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        return $base . '-' . self::shortId(3);
    }
}
