<?php
declare(strict_types=1);

namespace Tests\Controllers\Bot;

use App\Controllers\Bot\AdminBotController;
use PHPUnit\Framework\TestCase;

final class AdminBotControllerTest extends TestCase
{
    protected function setUp(): void
    {
        AdminBotController::reset();
    }

    protected function tearDown(): void
    {
        AdminBotController::reset();
    }

    public function testNonAdminGetsNoReply(): void
    {
        AdminBotController::adminsUsing(fn (): array => [42]);
        $this->assertNull((new AdminBotController())->dispatch(1, '/ping'));
    }

    public function testAdminPingRepliesPong(): void
    {
        AdminBotController::adminsUsing(fn (): array => [42]);
        $this->assertSame('pong', (new AdminBotController())->dispatch(42, '/ping'));
    }

    public function testUnknownCommandFromAnAdminIsIgnored(): void
    {
        AdminBotController::adminsUsing(fn (): array => [42]);
        $this->assertNull((new AdminBotController())->dispatch(42, '/nope'));
    }

    public function testStatsCountsUpWorkersFromMetrics(): void
    {
        AdminBotController::adminsUsing(fn (): array => [42]);
        $reply = (new AdminBotController())->dispatch(42, '/stats');
        $this->assertSame('Workers up: 0', $reply);
    }

    public function testDefaultAdminSourceReadsConfigAndIsEmptyByDefault(): void
    {
        // No adminsUsing() hook installed — falls back to config('bot.admin_ids'),
        // which ships empty, so nobody is an admin by default.
        $this->assertNull((new AdminBotController())->dispatch(1, '/ping'));
    }
}
