<?php
declare(strict_types=1);

namespace Tests\Controllers\Admin;

use App\Controllers\Admin\AdminApiController;
use App\Core\Auth;
use App\Core\HttpResponse;
use App\Core\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminApiControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Auth::reset();
        $_SESSION = [];
    }

    public function testWhoamiNeverLeaksThePasswordHashEvenWhenTheResolverReturnsTheRawRow(): void
    {
        // End-to-end coverage, made possible by Response::ok() throwing HttpResponse
        // instead of exiting the process — previously the only way to test this
        // controller at all was reflecting into sanitize() directly (see below).
        Auth::resolveUserUsing(fn (int $id): array => [
            'id'            => $id,
            'email'         => 'admin@example.com',
            'password_hash' => '$2y$10$somehashvaluehere',
            'roles'         => 'admin',
        ]);
        $_SESSION['user_id'] = 1;

        try {
            (new AdminApiController())->whoami(new Request());
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $body = json_decode((string) $r->payload, true);
            $this->assertArrayNotHasKey('password_hash', $body['data']['user']);
            $this->assertSame('admin@example.com', $body['data']['user']['email']);
        }
    }

    // sanitize() also gets direct reflection-based coverage of its edge cases
    // (null input, a row with no password_hash to begin with) that would be
    // awkward to set up through the full Auth::resolveUserUsing() path above.
    private function sanitize(?array $user): ?array
    {
        $m = new ReflectionMethod(AdminApiController::class, 'sanitize');
        $m->setAccessible(true);
        return $m->invoke(null, $user);
    }

    public function testStripsThePasswordHashFromARawUserRow(): void
    {
        $safe = $this->sanitize([
            'id'            => 1,
            'email'         => 'admin@example.com',
            'password_hash' => '$2y$10$somehashvaluehere',
            'roles'         => 'admin',
        ]);
        $this->assertArrayNotHasKey('password_hash', $safe);
        $this->assertSame('admin@example.com', $safe['email']);
    }

    public function testPassesNullThrough(): void
    {
        $this->assertNull($this->sanitize(null));
    }

    public function testLeavesARowWithoutAPasswordHashUnaffected(): void
    {
        $safe = $this->sanitize(['id' => 1]);
        $this->assertSame(['id' => 1], $safe);
    }
}
