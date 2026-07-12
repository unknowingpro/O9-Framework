<?php
declare(strict_types=1);

namespace App\Core;

/** RFC 4122 UUID v4 generator (zero-dependency). */
final class Uuid
{
    public static function v4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // version 4
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** True if $value is a well-formed UUID (any version). */
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
