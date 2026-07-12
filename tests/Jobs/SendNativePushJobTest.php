<?php
declare(strict_types=1);

namespace Tests\Jobs;

use App\Jobs\SendNativePushJob;
use PHPUnit\Framework\TestCase;

final class SendNativePushJobTest extends TestCase
{
    protected function tearDown(): void
    {
        SendNativePushJob::handleUsing(null);
    }

    public function testNoOpWithoutARegisteredHandler(): void
    {
        (new SendNativePushJob())->handle(['user_id' => 5, 'data' => ['title' => 'Hi']]);
        $this->addToAssertionCount(1);
    }

    public function testInvokesTheRegisteredHandlerWithUserIdAndData(): void
    {
        $seen = null;
        SendNativePushJob::handleUsing(function (int $userId, array $data) use (&$seen): void {
            $seen = [$userId, $data];
        });
        (new SendNativePushJob())->handle(['user_id' => 12, 'data' => ['badge' => 3]]);
        $this->assertSame([12, ['badge' => 3]], $seen);
    }

    public function testSkipsInvocationForNonPositiveUserId(): void
    {
        $called = false;
        SendNativePushJob::handleUsing(function () use (&$called): void {
            $called = true;
        });
        (new SendNativePushJob())->handle(['user_id' => -1, 'data' => []]);
        $this->assertFalse($called);
    }
}
