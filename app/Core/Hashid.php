<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Reversible obfuscation of integer ids → short opaque tokens, so URLs and UI
 * never expose raw sequential ids (e.g. withdrawal #3). NOT a security boundary
 * (access is still authorization-checked) — it just avoids leaking raw ids and
 * gives a stable "internal hash" per record.
 *
 * Bijective base-N encoding of (id + OFFSET) over a salt-shuffled alphabet:
 * deterministic, collision-free, and decode() is the exact inverse. The OFFSET
 * keeps small ids from producing 1-char tokens. The shuffle mixes in APP_KEY
 * via config('app.key'), so tokens are app-specific — do not treat them as
 * portable between deployments with different keys.
 */
final class Hashid
{
    // Unambiguous alphabet (no 0/O/1/I/l). 30 chars.
    private const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
    private const OFFSET    = 1_000_000;          // small ids → multi-char tokens
    private const SALT      = 'o9.hashid.v1';

    /** Encode a positive id into a short token. */
    public static function encode(int $id): string
    {
        if ($id < 0) {
            return '';
        }
        $alpha = self::shuffled();
        $base  = strlen($alpha);
        $n     = $id + self::OFFSET;
        $out   = '';
        do {
            $out = $alpha[$n % $base] . $out;
            $n = intdiv($n, $base);
        } while ($n > 0);
        return $out;
    }

    /** Decode a token back to its id, or null if malformed. */
    public static function decode(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $alpha = self::shuffled();
        $base  = strlen($alpha);
        $n     = 0;
        $len   = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alpha, $token[$i]);
            if ($pos === false) {
                return null;
            }
            $n = $n * $base + $pos;
        }
        $id = $n - self::OFFSET;
        return $id >= 0 ? $id : null;
    }

    /** Deterministic salt-shuffled alphabet (Fisher–Yates seeded by the salt). */
    private static function shuffled(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $a    = str_split(self::ALPHABET);
        $key  = function_exists('config') ? (string) (config('app.key') ?? '') : '';
        $salt = self::SALT . $key;
        $n    = count($a);
        $seed = 0;
        for ($i = $n - 1; $i > 0; $i--) {
            $seed = ($seed + ord($salt[$i % strlen($salt)])) % 0x7fffffff;
            $j = $seed % ($i + 1);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $cache = implode('', $a);
    }
}
