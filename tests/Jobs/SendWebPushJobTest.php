<?php
declare(strict_types=1);

namespace Tests\Jobs;

use App\Jobs\SendWebPushJob;
use PHPUnit\Framework\TestCase;

final class SendWebPushJobTest extends TestCase
{
    protected function tearDown(): void
    {
        SendWebPushJob::handleUsing(null);
    }

    public function testNoOpWithoutARegisteredHandler(): void
    {
        (new SendWebPushJob())->handle(['user_id' => 5, 'data' => ['title' => 'Hi']]);
        $this->addToAssertionCount(1); // must not throw
    }

    public function testInvokesTheRegisteredHandlerWithUserIdAndData(): void
    {
        $seen = null;
        SendWebPushJob::handleUsing(function (int $userId, array $data) use (&$seen): void {
            $seen = [$userId, $data];
        });
        (new SendWebPushJob())->handle(['user_id' => 9, 'data' => ['title' => 'Hi']]);
        $this->assertSame([9, ['title' => 'Hi']], $seen);
    }

    public function testSkipsInvocationForNonPositiveUserId(): void
    {
        $called = false;
        SendWebPushJob::handleUsing(function () use (&$called): void {
            $called = true;
        });
        (new SendWebPushJob())->handle(['user_id' => 0, 'data' => []]);
        $this->assertFalse($called);
    }

    public function testNonArrayDataCoercesToEmptyArray(): void
    {
        $seen = null;
        SendWebPushJob::handleUsing(function (int $userId, array $data) use (&$seen): void {
            $seen = $data;
        });
        (new SendWebPushJob())->handle(['user_id' => 1, 'data' => 'not-an-array']);
        $this->assertSame([], $seen);
    }
}
