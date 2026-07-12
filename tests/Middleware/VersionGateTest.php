<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\Request;
use App\Middleware\VersionGate;
use PHPUnit\Framework\TestCase;

final class VersionGateTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testBlocksIsFalseWhenGateDisabled(): void
    {
        $this->assertFalse(VersionGate::blocks(false, 'ios', '1.0.0', '2.0.0'));
    }

    public function testBlocksIsFalseForNonNativePlatforms(): void
    {
        $this->assertFalse(VersionGate::blocks(true, 'web', '1.0.0', '2.0.0'));
        $this->assertFalse(VersionGate::blocks(true, 'unknown', '1.0.0', '2.0.0'));
    }

    public function testBlocksIsFalseWithoutAVersionHeader(): void
    {
        $this->assertFalse(VersionGate::blocks(true, 'ios', '', '2.0.0'));
    }

    public function testBlocksIsTrueWhenCurrentIsOlderThanMin(): void
    {
        $this->assertTrue(VersionGate::blocks(true, 'ios', '1.0.0', '2.0.0'));
        $this->assertTrue(VersionGate::blocks(true, 'android', '1.9.9', '2.0.0'));
    }

    public function testBlocksIsFalseWhenCurrentMeetsOrExceedsMin(): void
    {
        $this->assertFalse(VersionGate::blocks(true, 'ios', '2.0.0', '2.0.0'));
        $this->assertFalse(VersionGate::blocks(true, 'ios', '2.1.0', '2.0.0'));
    }

    public function testHandlePassesThroughWithTheDefaultDisabledConfig(): void
    {
        // config('mobile.version_gate.enabled') defaults to false, so the
        // gate is fully inert out of the box regardless of headers sent.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/v1/health';
        $_SERVER['HTTP_X_APP_VERSION'] = '0.0.1';
        (new VersionGate())->handle(new Request());
        $this->addToAssertionCount(1);
    }
}
