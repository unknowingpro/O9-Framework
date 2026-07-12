<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\NotificationChannel;
use App\Services\NotificationService;
use PHPUnit\Framework\TestCase;

final class RecordingChannel implements NotificationChannel
{
    /** @var list<array{userId: int, type: string, title: string, body: string, meta: array<string, mixed>}> */
    public array $calls = [];

    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool
    {
        $this->calls[] = compact('userId', 'type', 'title', 'body', 'meta');
        return true;
    }
}

final class ThrowingChannel implements NotificationChannel
{
    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool
    {
        throw new \RuntimeException('channel exploded');
    }
}

final class NotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        NotificationService::reset();
    }

    protected function tearDown(): void
    {
        NotificationService::reset();
    }

    public function testNotifyFansOutToEveryRegisteredChannel(): void
    {
        $a = new RecordingChannel();
        $b = new RecordingChannel();
        NotificationService::registerChannel('a', $a);
        NotificationService::registerChannel('b', $b);

        (new NotificationService())->notify(1, 2, 'welcome', 'Hi', 'Body text', ['extra' => true]);

        $this->assertCount(1, $a->calls);
        $this->assertCount(1, $b->calls);
        $this->assertSame(1, $a->calls[0]['userId']);
        $this->assertSame('welcome', $a->calls[0]['type']);
        $this->assertSame('Hi', $a->calls[0]['title']);
        $this->assertSame(2, $a->calls[0]['meta']['from_user_id']);
        $this->assertTrue($a->calls[0]['meta']['extra']);
    }

    public function testUnregisterChannelStopsFutureNotifications(): void
    {
        $a = new RecordingChannel();
        NotificationService::registerChannel('a', $a);
        NotificationService::unregisterChannel('a');

        (new NotificationService())->notify(1, null, 'welcome');
        $this->assertCount(0, $a->calls);
    }

    public function testAFailingChannelDoesNotBlockOthers(): void
    {
        $good = new RecordingChannel();
        NotificationService::registerChannel('bad', new ThrowingChannel());
        NotificationService::registerChannel('good', $good);

        (new NotificationService())->notify(5, null, 'alert');

        $this->assertCount(1, $good->calls);
    }
}
