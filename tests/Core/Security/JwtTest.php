<?php
declare(strict_types=1);

namespace Tests\Core\Security;

use App\Core\Database;
use App\Core\Security\ExpiredException;
use App\Core\Security\Jwt;
use App\Core\Security\Key;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    protected function setUp(): void
    {
        Jwt::reset();
    }

    protected function tearDown(): void
    {
        Jwt::reset();
    }

    public function testEncodeDecodeRoundTripStampsIatAndJti(): void
    {
        $token = Jwt::encode(['sub' => 42], 60);
        $payload = Jwt::decode($token);
        $this->assertNotNull($payload);
        $this->assertSame(42, $payload['sub']);
        $this->assertIsInt($payload['iat']);
        $this->assertIsString($payload['jti']);
        $this->assertSame(16, strlen($payload['jti']));
        $this->assertEqualsWithDelta(time() + 60, $payload['exp'], 2);
    }

    public function testTtlIsClampedToConfiguredMax(): void
    {
        $token = Jwt::encode(['sub' => 1], 10 * 365 * 86400);
        $payload = Jwt::decode($token);
        $this->assertNotNull($payload);
        $maxTtl = max(60, (int) config('app.jwt.max_ttl', 30 * 86400));
        $this->assertLessThanOrEqual(time() + $maxTtl + 2, $payload['exp']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1, 'role' => 'user'], 60);
        [$h, $p, $s] = explode('.', $token);
        $forged = json_decode($this->b64d($p), true);
        $forged['role'] = 'admin';
        $tampered = $h . '.' . $this->b64((string) json_encode($forged)) . '.' . $s;
        $this->assertNull(Jwt::decode($tampered));
    }

    public function testWrongKeyIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1], 60);
        $this->assertNull(Jwt::decode($token, new Key('a-completely-different-secret')));
        $custom = Jwt::encode(['sub' => 2], 60, new Key('custom-secret-material'));
        $this->assertNull(Jwt::decode($custom));
        $payload = Jwt::decode($custom, new Key('custom-secret-material'));
        $this->assertNotNull($payload);
        $this->assertSame(2, $payload['sub']);
    }

    public function testExpiredTokenDecodesToNullAndStrictThrowsExpired(): void
    {
        $token = $this->craft(['sub' => 1, 'exp' => time() - 10]);
        $this->assertNull(Jwt::decode($token));
        $this->expectException(ExpiredException::class);
        Jwt::decodeStrict($token);
    }

    public function testDecodeStrictThrowsOnMalformedAndForged(): void
    {
        try {
            Jwt::decodeStrict('not-a-jwt');
            $this->fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            $this->assertNotInstanceOf(ExpiredException::class, $e);
        }
        $token = Jwt::encode(['sub' => 1], 60);
        try {
            Jwt::decodeStrict($token . 'x');
            $this->fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            $this->assertNotInstanceOf(ExpiredException::class, $e);
        }
    }

    public function testRevocationKillsAnOtherwiseValidToken(): void
    {
        Database::getInstance()->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS jwt_revocations (
                jti TEXT PRIMARY KEY, user_id INTEGER, revoked_at TEXT, exp TEXT
            )'
        );
        Database::getInstance()->pdo()->exec('DELETE FROM jwt_revocations');
        Jwt::reset(); // re-probe the table now that it exists

        $token = Jwt::encode(['sub' => 5], 3600);
        $this->assertNotNull(Jwt::decode($token));

        Jwt::revoke($token);
        $this->assertNull(Jwt::decode($token));

        Jwt::reset(); // drop the per-request cache — the denylist row must persist
        $this->assertNull(Jwt::decode($token));
        try {
            Jwt::decodeStrict($token);
            $this->fail('expected UnexpectedValueException for revoked token');
        } catch (\UnexpectedValueException $e) {
            $this->assertSame('Token revoked', $e->getMessage());
        }

        // Other tokens of the same user stay valid.
        $other = Jwt::encode(['sub' => 5], 3600);
        $this->assertNotNull(Jwt::decode($other));
    }

    public function testRevokeIgnoresGarbageTokens(): void
    {
        Jwt::revoke('garbage');
        Jwt::revoke('a.b.c');
        $this->assertNotNull(Jwt::decode(Jwt::encode(['sub' => 1], 60)));
    }

    /**
     * Build a signed token with an arbitrary payload (encode() refuses to
     * mint already-expired tokens, so expiry tests craft their own).
     *
     * @param array<string, mixed> $payload
     */
    private function craft(array $payload): string
    {
        $payload += ['iat' => time() - 100, 'jti' => bin2hex(random_bytes(8))];
        $secret = (string) config('app.jwt.secret');
        $h = $this->b64((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $p = $this->b64((string) json_encode($payload));
        $s = $this->b64(hash_hmac('sha256', "$h.$p", $secret, true));
        return "$h.$p.$s";
    }

    private function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64d(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
