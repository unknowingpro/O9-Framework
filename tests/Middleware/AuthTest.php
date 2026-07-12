<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\Auth as CoreAuth;
use App\Core\HttpException;
use App\Core\Request;
use App\Middleware\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        CoreAuth::reset();
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        CoreAuth::reset();
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testThrowsUnauthorizedWhenNotLoggedIn(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new Auth())->handle(new Request());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testPassesForAnyAuthenticatedUserWithoutARoleArg(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 1;
        (new Auth())->handle(new Request(), null);
        $this->addToAssertionCount(1); // no exception
    }

    public function testThrowsForbiddenWhenRoleIsMissing(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id, 'roles' => 'member']);
        $_SESSION['user_id'] = 1;
        $this->expectException(HttpException::class);
        try {
            (new Auth())->handle(new Request(), 'admin');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testPassesWhenTheRequiredRoleIsHeld(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id, 'roles' => 'admin,member']);
        $_SESSION['user_id'] = 1;
        (new Auth())->handle(new Request(), 'admin');
        $this->addToAssertionCount(1);
    }
}
