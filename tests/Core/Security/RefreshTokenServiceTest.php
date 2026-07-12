<?php
declare(strict_types=1);

namespace Tests\Core\Security;

use App\Core\Database;
use App\Core\Security\RefreshTokenService;
use PHPUnit\Framework\TestCase;

final class RefreshTokenServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS refresh_tokens');
        $this->db->pdo()->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE,
                family_id TEXT, created_at TEXT, used_at TEXT, revoked_at TEXT
            )'
        );
        RefreshTokenService::reset();
    }

    protected function tearDown(): void
    {
        RefreshTokenService::reset();
    }

    public function testIssueThenRotateSucceedsAndReturnsANewDifferentToken(): void
    {
        $token = RefreshTokenService::issue(7);
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token)); // 32 random bytes, hex-encoded

        $result = RefreshTokenService::rotate($token);
        $this->assertNotNull($result);
        $this->assertSame(7, $result['userId']);
        $this->assertNotSame($token, $result['token']);
    }

    public function testRotatingTheSameTokenTwiceDetectsReuseAndKillsTheWholeFamily(): void
    {
        $token = RefreshTokenService::issue(7);
        $first = RefreshTokenService::rotate($token);
        $this->assertNotNull($first);

        // Replaying the ALREADY-ROTATED token (e.g. an attacker who stole it
        // after the legitimate client already exchanged it) must fail...
        $this->assertNull(RefreshTokenService::rotate($token));

        // ...and must also have revoked the legitimate client's own new
        // token from the same family, forcing a fresh login for everyone.
        $this->assertNull(RefreshTokenService::rotate($first['token']));
    }

    public function testRotatingAnUnknownTokenReturnsNull(): void
    {
        $this->assertNull(RefreshTokenService::rotate('not-a-real-token'));
    }

    public function testRevokeAllForUserBlocksFutureRotation(): void
    {
        $token = RefreshTokenService::issue(9);
        RefreshTokenService::revokeAllForUser(9);
        $this->assertNull(RefreshTokenService::rotate($token));
    }

    public function testGracefullyNoOpsWithoutTheTable(): void
    {
        $this->db->pdo()->exec('DROP TABLE refresh_tokens');
        RefreshTokenService::reset();
        $this->assertNull(RefreshTokenService::issue(1));
        $this->assertNull(RefreshTokenService::rotate('anything'));
    }

    public function testPlaintextTokenIsNeverStored(): void
    {
        $token = RefreshTokenService::issue(1);
        $row = $this->db->raw('SELECT token_hash FROM refresh_tokens LIMIT 1')->fetch();
        $this->assertIsArray($row);
        $this->assertNotSame($token, $row['token_hash']);
        $this->assertSame(hash('sha256', (string) $token), $row['token_hash']);
    }
}
