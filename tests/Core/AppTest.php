<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\App;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testSingleton(): void
    {
        $this->assertSame(App::getInstance(), App::getInstance());
    }

    public function testSessionExpiryDecision(): void
    {
        $now = 1_000_000;
        // fresh session — no expiry
        $this->assertNull(App::sessionExpiry($now - 100, $now - 100, $now, 1800, 28800));
        // idle: 31 minutes since last activity
        $this->assertSame('idle', App::sessionExpiry($now - 5000, $now - 1860, $now, 1800, 28800));
        // absolute: active constantly but session older than 8h
        $this->assertSame('absolute', App::sessionExpiry($now - 30000, $now - 10, $now, 1800, 28800));
        // idle wins when both exceeded (checked first)
        $this->assertSame('idle', App::sessionExpiry($now - 30000, $now - 4000, $now, 1800, 28800));
        // zero limits disable the checks
        $this->assertNull(App::sessionExpiry($now - 999999, $now - 999999, $now, 0, 0));
    }

    public function testPhpErrorHandlerHonoursSuppressionAndUserErrors(): void
    {
        // E_USER_ERROR must be delegated to PHP (returns false).
        $this->assertFalse(App::handlePhpError(E_USER_ERROR, 'fatal'));

        // Below the current error_reporting threshold → swallowed (true).
        $prev = error_reporting(0);
        try {
            $this->assertTrue(App::handlePhpError(E_WARNING, 'suppressed'));
        } finally {
            error_reporting($prev);
        }
    }
}
