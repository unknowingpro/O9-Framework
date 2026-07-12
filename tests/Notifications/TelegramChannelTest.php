<?php
declare(strict_types=1);

namespace Tests\Notifications;

use App\Notifications\TelegramChannel;
use PHPUnit\Framework\TestCase;

final class TelegramChannelTest extends TestCase
{
    public function testSendPostsToTelegramApiWithUserIdAsDefaultChatId(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload, array $headers) use (&$captured): array {
            $captured = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $result = $channel->send(42, 'alert', 'Server Down', 'Check it now.', []);

        $this->assertTrue($result);
        $this->assertStringContainsString('api.telegram.org/bot', $captured['url']);
        $this->assertStringContainsString('test-bot-token', $captured['url']);
        $this->assertStringContainsString('/sendMessage', $captured['url']);
        $this->assertSame(42, $captured['payload']['chat_id']);
        $this->assertStringContainsString('Server Down', $captured['payload']['text']);
        $this->assertSame('Markdown', $captured['payload']['parse_mode']);
        $this->assertSame('Content-Type: application/json', $captured['headers'][0]);
    }

    public function testSendUsesChatIdFromMetaWhenProvided(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $channel->send(42, 'alert', 'Title', 'Body', ['chat_id' => -1001234567890]);

        $this->assertSame(-1001234567890, $captured['chat_id']);
    }

    public function testSendFormatsTitleAsBoldInBody(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $channel->send(1, 'test', 'Important', 'Details here.');

        $this->assertSame("*Important*\n\nDetails here.", $captured['text']);
    }

    public function testSendWithoutTitleSendsOnlyBody(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $channel->send(1, 'test', '', 'Just body.');

        $this->assertSame('Just body.', $captured['text']);
    }

    public function testSendReturnsFalseOnNon200Response(): void
    {
        $poster = fn (string $url, array $payload, array $headers): array =>
            ['status' => 403, 'body' => '{}'];

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testSendReturnsFalseOnPosterException(): void
    {
        $poster = function (string $url, array $payload, array $headers): array {
            throw new \RuntimeException('Connection failed');
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testSendReturnsFalseOnEmptyToken(): void
    {
        $channel = new TelegramChannel('');
        $this->assertFalse($channel->send(1, 'test', '', 'Body'));
    }

    public function testSendCanDisableWebPagePreview(): void
    {
        $captured = null;
        $poster = function (string $url, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['status' => 200, 'body' => '{}'];
        };

        $channel = new TelegramChannel('test-bot-token', null, $poster);
        $channel->send(1, 'test', '', 'Body', ['disable_web_page_preview' => false]);

        $this->assertFalse($captured['disable_web_page_preview']);
    }
}
