<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Env — minimal .env loader.
 *
 * Parses KEY=VALUE lines once, caches them, and coerces the common literals
 * (true/false/null/empty and their parenthesised variants). Server
 * environment variables always take precedence over the file so production
 * can inject secrets natively.
 *
 * Default fallback: when a variable resolves to PHP null (unset OR the
 * literal string "null"), the caller-supplied default wins. Without this,
 * `JWT_SECRET=null` in an .env file would silently zero out the secret
 * instead of falling through to the safe default.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];
    private static bool $loaded = false;

    /** Load and parse the .env file at $path (idempotent per request). */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (($lines === false ? [] : $lines) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[strlen($value) - 1] === $value[0]) {
                // Quoted value: strip the matching quotes, keep content verbatim.
                $value = substr($value, 1, -1);
            } elseif (($pos = strpos($value, '#')) !== false && ($pos === 0 || $value[$pos - 1] === ' ')) {
                // Unquoted value: drop a trailing inline comment. Checked against
                // the already-trimmed value, so a blank value followed only by a
                // comment (`KEY=   # note`) has its '#' at position 0 here — the
                // leading whitespace that would otherwise mark the boundary was
                // already removed by trim() above, so position 0 must count too,
                // or a comment-only line reads back as literal comment text.
                $value = rtrim(substr($value, 0, $pos));
            }
            self::$vars[$key] = $value;
        }
    }

    /** Get a variable: server env first, then .env file, then default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = getenv($key);
        if ($raw === false) {
            $raw = $_ENV[$key] ?? self::$vars[$key] ?? null;
        }
        if ($raw === null) {
            return $default;
        }
        if (!is_string($raw)) {
            return $raw;
        }
        $resolved = self::coerce($raw);
        return $resolved === null ? $default : $resolved;
    }

    /**
     * All variables parsed from the .env file (server env not included).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$vars;
    }

    /** Forget the parsed file so the next load() re-reads it (tests, workers). */
    public static function reset(): void
    {
        self::$vars   = [];
        self::$loaded = false;
    }

    /** Coerce common literal strings to real PHP types. */
    private static function coerce(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
