<?php
declare(strict_types=1);

namespace Tests\Controllers\Admin;

use App\Controllers\Admin\AdminApiController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminApiControllerTest extends TestCase
{
    // whoami() itself can't be exercised end-to-end: Response::ok() -> Response::json()
    // calls exit() on success (by design — see Response.php), which would kill the
    // PHPUnit process. sanitize() holds the actual security-relevant logic, so it's
    // tested directly via reflection rather than skipping coverage entirely.
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
