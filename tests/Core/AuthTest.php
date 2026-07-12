<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Auth;
use App\Core\Security\Jwt;
use PHPUnit\Framework\TestCase;

/**
 * Session-cookie paths (login/logout/session_start) are exercised in
 * integration, not here — PHPUnit's CLI output means headers are already
 * sent, so session_start() would warn. These tests drive the resolver
 * contract via the $_SESSION superglobal and the Bearer header directly.
 */
final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        Auth::reset();
        Jwt::reset();
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        Auth::reset();
        Jwt::reset();
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testGuestResolvesToNull(): void
    {
        $this->assertNull(Auth::user());
        $this->assertNull(Auth::id());
        $this->assertFalse(Auth::check());
        $this->assertFalse(Auth::hasRole('admin'));
    }

    public function testSessionUserResolvesThroughRegisteredResolver(): void
    {
        $calls = 0;
        Auth::resolveUserUsing(function (int $id) use (&$calls): ?array {
            $calls++;
            return ['id' => $id, 'name' => 'Sara', 'roles' => 'admin,member'];
        });
        $_SESSION['user_id'] = 7;

        $user = Auth::user();
        $this->assertNotNull($user);
        $this->assertSame(7, $user['id']);
        $this->assertSame('Sara', $user['name']);
        $this->assertSame(7, Auth::id());
        $this->assertTrue(Auth::hasRole('admin'));
        $this->assertTrue(Auth::hasRole('member'));
        $this->assertFalse(Auth::hasRole('owner'));
        // Memoised: the resolver ran exactly once for the whole request.
        $this->assertSame(1, $calls);
    }

    public function testResolverReturningNullMeansLoggedOut(): void
    {
        Auth::resolveUserUsing(fn (int $id): ?array => null);
        $_SESSION['user_id'] = 7;
        $this->assertNull(Auth::user());
        $this->assertFalse(Auth::check());
    }

    public function testWithoutResolverAMinimalActorIsReturned(): void
    {
        $_SESSION['user_id'] = 3;
        $this->assertSame(['id' => 3], Auth::user());
        $this->assertSame(3, Auth::id());
    }

    public function testBearerTokenResolvesUser(): void
    {
        $token = Jwt::encode(['sub' => 9], 60);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertSame(['id' => 9], Auth::user());
        $this->assertTrue(Auth::check());
    }

    public function testInvalidBearerTokenIsRejected(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not.a.token';
        $this->assertNull(Auth::user());
    }

    public function testRefreshTokenIsNeverAcceptedAsAccessCredentials(): void
    {
        $token = Jwt::encode(['sub' => 9, 'typ' => 'refresh'], 60);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertNull(Auth::user());
    }

    public function testAccessTokenIssuedBeforeForceLogoutEpochIsRejected(): void
    {
        Auth::resolveUserUsing(fn (int $id): ?array => [
            'id' => $id,
            // Epoch one hour in the future: every token minted "now" predates it.
            'force_logout_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $token = Jwt::encode(['sub' => 9, 'typ' => 'access'], 60);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertNull(Auth::user());

        // With the epoch in the past, the same token is fine.
        Auth::reset();
        Auth::resolveUserUsing(fn (int $id): ?array => [
            'id' => $id,
            'force_logout_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertNotNull(Auth::user());
    }

    public function testDeviceClaimIsCheckedThroughTheRegisteredHook(): void
    {
        $token = Jwt::encode(['sub' => 9, 'typ' => 'access', 'did' => 'device-1'], 60);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        Auth::checkDeviceUsing(fn (string $did): bool => false);
        $this->assertNull(Auth::user());

        Auth::reset();
        $seen = null;
        Auth::checkDeviceUsing(function (string $did) use (&$seen): bool {
            $seen = $did;
            return true;
        });
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertNotNull(Auth::user());
        $this->assertSame('device-1', $seen);
    }

    public function testDeviceClaimIsIgnoredWithoutAHook(): void
    {
        $token = Jwt::encode(['sub' => 9, 'typ' => 'access', 'did' => 'device-1'], 60);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertNotNull(Auth::user());
    }

    public function testToucherRunsOncePerResolvedRequest(): void
    {
        Auth::resolveUserUsing(fn (int $id): ?array => ['id' => $id]);
        $touched = [];
        Auth::touchUsing(function (array $user) use (&$touched): void {
            $touched[] = $user['id'];
        });
        $_SESSION['user_id'] = 4;
        Auth::user();
        Auth::user();
        $this->assertSame([4], $touched);
    }
}
