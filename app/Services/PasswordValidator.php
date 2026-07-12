<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Server-side password strength validation for registration and password
 * changes — the only enforcement point that matters, since a client-side
 * check is trivially bypassed by calling the write path directly.
 */
final class PasswordValidator
{
    /**
     * Minimum password length required.
     */
    public const MIN_LENGTH = 12;

    /**
     * A short, common-password blocklist — not exhaustive, just the
     * obvious footguns. In production, back this with a real breach list.
     *
     * @var list<string>
     */
    private static array $commonPasswords = [
        'password', '123456', '12345678', 'qwerty', '123456789',
        '12345', '1234', '111111', '1234567', 'dragon',
        '123123', 'baseball', 'abc123', 'football', 'monkey',
        'letmein', 'shadow', 'master', '666666', 'qwertyuiop',
        '123321', 'mustang', '1234567890', 'michael', '654321',
        'superman', '1qaz2wsx', '7777777', '121212', '000000',
        'qazwsx', '123qwe', 'killer', 'trustno1', 'jordan',
        'jennifer', 'zxcvbnm', 'asdfgh', 'hunter', 'buster',
        'soccer', 'harley', 'batman', 'andrew', 'tigger',
        'sunshine', 'iloveyou', '2000', 'charlie', 'robert',
        'thomas', 'hockey', 'ranger', 'daniel', 'starwars',
        'klaster', '112233', 'george', 'computer', 'michelle',
        'jessica', 'pepper', '1111', 'zxcvbn', '555555',
        '11111111', '131313', 'freedom', '777777', 'pass',
        'maggie', '159753', 'aaaaaa', 'ginger', 'princess',
        'joshua', 'cheese', 'amanda', 'summer', 'love',
        'ashley', 'nicole', 'chelsea', 'biteme', 'matthew',
        'access', 'yankees', '987654321', 'david', 'ocelot',
        'austin', 'taylor', 'mattress', 'jenifer', 'elijah',
        'camaro', 'vienna', 'theboss', 'harry', 'andrea',
        'secret', 'ready', 'mercedes', 'jordan69', 'orange',
        'invader', 'quality', 'armstrong', 'merlin', 'diamond'
    ];

    /**
     * Validates a password for strength and returns an error message if invalid.
     *
     * @param string $password The password to validate
     * @return string|null Error message if password is invalid, null if valid
     */
    public static function validate(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if (self::isCommonPassword($password)) {
            return 'This password is too common. Please choose a less predictable password.';
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        return null;
    }

    /**
     * Checks if a password is in the common passwords list.
     *
     * @param string $password The password to check
     * @return bool True if password is too common
     */
    private static function isCommonPassword(string $password): bool
    {
        $lower = strtolower($password);
        return in_array($lower, self::$commonPasswords, true);
    }
}