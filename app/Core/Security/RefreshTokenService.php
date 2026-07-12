<?php
declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database;

/**
 * Refresh-token issuance, rotation, and reuse detection — the long-lived
 * counterpart to Jwt's short-lived access tokens (see Jwt.php's typ:refresh
 * handling). Every exchange issues a brand-new token in the same "family"
 * and marks the presented one used; presenting an already-used token is
 * treated as a compromise signal (the legitimate client already rotated
 * past it, so this one was stolen and replayed) and revokes the whole
 * family, forcing a fresh login.
 *
 * Tokens are stored as a SHA-256 hash, not the plaintext — they're already
 * high-entropy random values (not a human-memorable secret), so a fast hash
 * is the right tool here, matching how Jwt stores nothing plaintext either.
 * Requires the refresh_tokens migration; no-ops (returns null) when the
 * table doesn't exist yet, matching Jwt's own optional-table pattern.
 */
final class RefreshTokenService
{
    private static ?bool $tableExists = null;

    /** Issue a brand-new refresh token, starting a new rotation family. */
    public static function issue(int $userId): ?string
    {
        return self::store($userId, self::newFamilyId());
    }

    /**
     * Exchange a presented refresh token for a new one. Returns null when the
     * token is unknown, revoked, or already used (and — critically — an
     * already-used token revokes its entire family before returning null, so
     * the legitimate client's own next rotation attempt also fails and the
     * user is forced to re-authenticate).
     *
     * @return array{token: string, userId: int}|null
     */
    public static function rotate(string $plainToken): ?array
    {
        if (!self::available()) {
            return null;
        }
        $db = Database::getInstance();
        $hash = self::hash($plainToken);
        $row = $db->raw('SELECT * FROM refresh_tokens WHERE token_hash = ?', [$hash])->fetch();
        if (!is_array($row) || $row['revoked_at'] !== null) {
            return null;
        }
        if ($row['used_at'] !== null) {
            // Reuse of an already-rotated token: treat as compromised and kill the family.
            self::revokeFamily((string) $row['family_id']);
            return null;
        }
        $db->raw('UPDATE refresh_tokens SET used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
        $userId = (int) $row['user_id'];
        $next = self::store($userId, (string) $row['family_id']);
        return $next === null ? null : ['token' => $next, 'userId' => $userId];
    }

    /** Revoke every token in a rotation family — called on reuse detection, or explicitly on logout. */
    public static function revokeFamily(string $familyId): void
    {
        if (!self::available()) {
            return;
        }
        Database::getInstance()->raw(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $familyId]
        );
    }

    /** Revoke every refresh token for a user, across every family — "log out everywhere". */
    public static function revokeAllForUser(int $userId): void
    {
        if (!self::available()) {
            return;
        }
        Database::getInstance()->raw(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId]
        );
    }

    private static function store(int $userId, string $familyId): ?string
    {
        if (!self::available()) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        Database::getInstance()->raw(
            'INSERT INTO refresh_tokens (user_id, token_hash, family_id, created_at) VALUES (?, ?, ?, ?)',
            [$userId, self::hash($token), $familyId, gmdate('Y-m-d H:i:s')]
        );
        return $token;
    }

    private static function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$tableExists = null;
    }

    private static function available(): bool
    {
        return self::$tableExists ??= Database::getInstance()->tableExists('refresh_tokens');
    }
}
