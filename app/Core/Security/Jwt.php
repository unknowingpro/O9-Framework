<?php
declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database;

/**
 * HS256 JSON Web Token encode/decode without external dependencies.
 *
 * Every issued token carries iat + a unique jti so individual tokens can be
 * revoked (logout / password change / compromise) without rotating the
 * signing secret for everyone. Revocations live in a `jwt_revocations` table;
 * apps without that table simply skip the check.
 *
 * decode() returns null on any problem (invalid, expired, revoked).
 * decodeStrict() distinguishes expiry (ExpiredException) from everything
 * else (UnexpectedValueException) for refresh-token flows.
 */
final class Jwt
{
    /** @var array<string, bool> Per-request denylist cache. */
    private static array $revCache = [];
    private static ?bool $revTableExists = null;

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload, ?int $ttlSeconds = null, ?Key $key = null): string
    {
        $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
        // Stamp iat + jti (unique token id). The jti enables targeted
        // revocation: logout / password-change / compromise can flip a
        // single token off without rotating the JWT secret for everyone.
        $payload = array_merge($payload, [
            'iat' => time(),
            'jti' => bin2hex(random_bytes(8)),
        ]);
        if ($ttlSeconds !== null) {
            // Clamp: never issue an already-expired (<=0) or effectively-infinite token. The ceiling
            // is the configured max TTL so a stray caller can't mint a multi-year credential.
            $maxTtl = max(60, (int) config('app.jwt.max_ttl', 30 * 86400));
            $payload['exp'] = time() + max(1, min($ttlSeconds, $maxTtl));
        }
        $segments = [
            self::b64((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), self::secret($key), true);
        $segments[] = self::b64($signature);
        return implode('.', $segments);
    }

    /** @return array<string, mixed>|null */
    public static function decode(string $token, ?Key $key = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$h.$p", self::secret($key), true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $payload = json_decode(self::b64Decode($p), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }
        // Revocation check: if this jti is on the denylist, the token is dead
        // even if otherwise-valid. Tokens without a jti pass through for
        // back-compat — they expire on their own.
        if (!empty($payload['jti']) && self::isRevoked((string) $payload['jti'])) {
            return null;
        }
        return $payload;
    }

    /**
     * Firebase-style strict decode: throws instead of returning null so the
     * caller can distinguish an expired token (refresh it) from a forged or
     * malformed one (reject the session).
     *
     * @return array<string, mixed>
     * @throws ExpiredException          when only the exp claim failed
     * @throws \UnexpectedValueException on any other problem
     */
    public static function decodeStrict(string $token, ?Key $key = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \UnexpectedValueException('Malformed token');
        }
        [$h, $p, $s] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$h.$p", self::secret($key), true));
        if (!hash_equals($expected, $s)) {
            throw new \UnexpectedValueException('Signature verification failed');
        }
        $payload = json_decode(self::b64Decode($p), true);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Invalid payload');
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw new ExpiredException('Token expired');
        }
        if (!empty($payload['jti']) && self::isRevoked((string) $payload['jti'])) {
            throw new \UnexpectedValueException('Token revoked');
        }
        return $payload;
    }

    /**
     * Mark a token as revoked. Called from logout / password-change paths.
     * Safe to call with any token — invalid tokens are ignored. The original
     * exp is persisted so a purge cron can clean up rows past their natural
     * expiry. No-op when the app has no jwt_revocations table.
     */
    public static function revoke(string $token): void
    {
        if (!self::revocationsAvailable()) {
            return;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) return;
        $payload = json_decode(self::b64Decode($parts[1]), true);
        if (!is_array($payload) || empty($payload['jti']) || empty($payload['sub'])) return;
        Database::getInstance()->raw(
            'INSERT OR IGNORE INTO jwt_revocations (jti, user_id, revoked_at, exp) VALUES (?, ?, ?, ?)',
            [
                (string) $payload['jti'],
                (int)    $payload['sub'],
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s', (int) ($payload['exp'] ?? time() + 86400)),
            ]
        );
        // Invalidate the per-request cache so a same-request decode after
        // revoke sees the new state.
        self::$revCache[(string) $payload['jti']] = true;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$revCache = [];
        self::$revTableExists = null;
    }

    /** Look up a jti against the denylist; cached per-request. */
    private static function isRevoked(string $jti): bool
    {
        if (array_key_exists($jti, self::$revCache)) return self::$revCache[$jti];
        if (!self::revocationsAvailable()) {
            return self::$revCache[$jti] = false;
        }
        $row = Database::getInstance()->raw(
            'SELECT 1 FROM jwt_revocations WHERE jti = ?', [$jti]
        )->fetch();
        return self::$revCache[$jti] = (bool) $row;
    }

    /** The revocation table is optional — probe once per request. */
    private static function revocationsAvailable(): bool
    {
        return self::$revTableExists ??= Database::getInstance()->tableExists('jwt_revocations');
    }

    private static function secret(?Key $key): string
    {
        if ($key !== null) {
            return $key->keyMaterial;
        }
        return (string) config('app.jwt.secret', 'change-me');
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64Decode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
