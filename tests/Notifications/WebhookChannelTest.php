<?php
declare(strict_types=1);

namespace Tests\Notifications;

use App\Notifications\WebhookChannel;
use PHPUnit\Framework\TestCase;

final class WebhookChannelTest extends TestCase
{
    public function testSendPostsToConfiguredUrl(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = ['url' => $url, 'payload' => $payload];
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new WebhookChannel('https://hooks.example.com/notify', null, $poster);
        $result = $channel->send(42, 'deploy.success', 'Deploy done', 'v3.2 is live.');

        $this->assertTrue($result);
        $this->assertSame('https://hooks.example.com/notify', $captured['url']);
    }

    public function testPayloadContainsAllFields(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new WebhookChannel('https://hooks.example.com/notify', null, $poster);
        $channel->send(42, 'deploy.success', 'Deploy done', 'v3.2 is live.', ['extra' => 'data']);

        $this->assertSame(42, $captured['user_id']);
        $this->assertSame('deploy.success', $captured['type']);
        $this->assertSame('Deploy done', $captured['title']);
        $this->assertSame('v3.2 is live.', $captured['body']);
        $this->assertSame(['extra' => 'data'], $captured['meta']);
    }

    public function testSendUsesWebhookUrlFromMeta(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = ['url' => $url];
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new WebhookChannel('https://default.example.com', null, $poster);
        $channel->send(1, 'test', '', '', ['webhook_url' => 'https://per-call.example.com']);

        $this->assertSame('https://per-call.example.com', $captured['url']);
    }

    public function testSendReturnsFalseOnNon2xxResponse(): void
    {
        $poster = fn (string $url, array $payload, array $headers): array =>
            ['status' => 500, 'body' => '{}'];

        $channel = new WebhookChannel('https://hooks.example.com/notify', null, $poster);
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testSendReturnsFalseOnPosterException(): void
    {
        $poster = function (string $url, array $payload, array $headers): array {
            throw new \RuntimeException('Connection refused');
        };

        $channel = new WebhookChannel('https://hooks.example.com/notify', null, $poster);
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testSendReturnsFalseWithNoUrlConfigured(): void
    {
        $channel = new WebhookChannel('');
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testReturnsTrueOn3xxResponse(): void
    {
        $poster = fn (string $url, array $payload, array $headers): array =>
            ['status' => 302, 'body' => ''];

        $channel = new WebhookChannel('https://hooks.example.com/notify', null, $poster);
        $this->assertTrue($channel->send(1, 'test', '', 'Body'));
    }
}
