<?php
declare(strict_types=1);

namespace Tests\Controllers\Api;

use App\Controllers\Api\AuthController;
use App\Core\Database;
use App\Core\HttpResponse;
use App\Core\Request;
use App\Core\Security\Jwt;
use App\Core\Security\RefreshTokenService;
use App\Models\UserModel;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        foreach (['users', 'refresh_tokens', 'jwt_revocations'] as $t) {
            $this->db->pdo()->exec("DROP TABLE IF EXISTS $t");
        }
        $this->db->pdo()->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password_hash TEXT,'
            . ' roles TEXT DEFAULT "", locale TEXT, force_logout_at TEXT, last_seen_at TEXT,'
            . ' created_at TEXT, updated_at TEXT)'
        );
        $this->db->pdo()->exec(
            'CREATE TABLE refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,'
            . ' token_hash TEXT UNIQUE, family_id TEXT, created_at TEXT, used_at TEXT, revoked_at TEXT)'
        );
        $this->db->pdo()->exec(
            'CREATE TABLE jwt_revocations (jti TEXT PRIMARY KEY, user_id INTEGER, revoked_at TEXT, exp TEXT)'
        );
        RefreshTokenService::reset();
        Jwt::reset();
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        RefreshTokenService::reset();
        Jwt::reset();
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function req(array $body): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/v1/auth/x';
        $_POST = $body;
        return new Request();
    }

    private function body(HttpResponse $r): array
    {
        return json_decode((string) $r->payload, true);
    }

    public function testRegisterCreatesAUserAndReturnsBothTokens(): void
    {
        try {
            (new AuthController())->register($this->req([
                'email' => 'new@example.com', 'password' => 'Correct-Horse-99',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(201, $r->status);
            $body = $this->body($r);
            $this->assertTrue($body['ok']);
            $this->assertNotEmpty($body['data']['access_token']);
            $this->assertNotEmpty($body['data']['refresh_token']);
            $this->assertSame('Bearer', $body['data']['token_type']);
        }
        $this->assertNotNull((new UserModel())->findByEmail('new@example.com'));
    }

    public function testRegisterRejectsADuplicateEmailWithValidation422(): void
    {
        (new UserModel())->register('taken@example.com', 'Correct-Horse-99');
        try {
            (new AuthController())->register($this->req([
                'email' => 'taken@example.com', 'password' => 'Correct-Horse-99',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(422, $r->status);
            $this->assertSame('validation_failed', $this->body($r)['error']['code']);
        }
    }

    public function testRegisterRejectsAWeakPasswordWith422(): void
    {
        try {
            (new AuthController())->register($this->req([
                'email' => 'weak@example.com', 'password' => 'short',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(422, $r->status);
            $this->assertSame('weak_password', $this->body($r)['error']['code']);
        }
        $this->assertNull((new UserModel())->findByEmail('weak@example.com'));
    }

    public function testLoginWithCorrectCredentialsReturnsTokens(): void
    {
        (new UserModel())->register('user@example.com', 'Correct-Horse-99');
        try {
            (new AuthController())->login($this->req([
                'email' => 'user@example.com', 'password' => 'Correct-Horse-99',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $this->assertNotEmpty($this->body($r)['data']['access_token']);
        }
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        (new UserModel())->register('user@example.com', 'Correct-Horse-99');
        try {
            (new AuthController())->login($this->req([
                'email' => 'user@example.com', 'password' => 'wrong-one',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(401, $r->status);
        }
    }

    public function testLoginWithAnUnknownEmailReturnsTheSame401AsAWrongPassword(): void
    {
        // Same status + generic message either way — no enumeration signal.
        try {
            (new AuthController())->login($this->req([
                'email' => 'nobody@example.com', 'password' => 'whatever-Pass1',
            ]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(401, $r->status);
            $this->assertSame('Invalid email or password.', $this->body($r)['error']['message']);
        }
    }

    public function testRefreshRotatesTheTokenAndReturnsANewAccessToken(): void
    {
        $id = (new UserModel())->register('user@example.com', 'Correct-Horse-99');
        $refreshToken = RefreshTokenService::issue($id);

        try {
            (new AuthController())->refresh($this->req(['refresh_token' => $refreshToken]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $body = $this->body($r);
            $this->assertNotEmpty($body['data']['access_token']);
            $this->assertNotSame($refreshToken, $body['data']['refresh_token']);
        }

        // The presented token was single-use — replaying it must now fail.
        $this->assertNull(RefreshTokenService::rotate($refreshToken));
    }

    public function testRefreshWithAnInvalidTokenReturns401(): void
    {
        try {
            (new AuthController())->refresh($this->req(['refresh_token' => 'garbage']));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(401, $r->status);
        }
    }

    public function testLogoutRevokesBothTheAccessTokenAndTheRefreshTokenFamily(): void
    {
        $id = (new UserModel())->register('user@example.com', 'Correct-Horse-99');
        $access = Jwt::encode(['sub' => $id, 'typ' => 'access']);
        $refreshToken = RefreshTokenService::issue($id);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;
        try {
            (new AuthController())->logout($this->req(['refresh_token' => $refreshToken]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $this->assertTrue($this->body($r)['data']['logged_out']);
        }

        $this->assertNull(Jwt::decode($access));
        $this->assertNull(RefreshTokenService::rotate($refreshToken));
    }

    public function testLogoutNeverFailsEvenWithNoTokensPresented(): void
    {
        try {
            (new AuthController())->logout($this->req([]));
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
        }
    }
}
