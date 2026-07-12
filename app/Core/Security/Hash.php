<?php
declare(strict_types=1);

namespace App\Core\Security;

/**
 * Password hashing helpers. Bcrypt via password_hash, constant-time verify.
 */
final class Hash
{
    public static function make(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function check(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}
