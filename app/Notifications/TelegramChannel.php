<?php
declare(strict_types=1);

namespace App\Notifications;

use App\Core\HttpClient;
use App\Services\NotificationChannel;

/**
 * Telegram notification channel — sends messages via the Bot API.
 *
 * Token resolution, in priority order:
 *   1. Constructor argument ($botToken)
 *   2. config('bot.token')   — the Telegram bot token from config/bot.php
 *
 * Chat ID resolution, in priority order:
 *   1. $meta['chat_id']
 *   2. $userId (the notification target)
 *
 * Usage:
 *   NotificationService::registerChannel('telegram', new TelegramChannel());
 *   $service->notify($userId, null, 'alert', 'Server down', 'Check it now.', [
 *       'chat_id' => -1001234567890,   // optional — defaults to $userId
 *   ]);
 *
 * Testing: pass a $poster callable to intercept the HTTP request (same pattern
 * as MailgunTransport's $poster), or a pre-configured HttpClient for full
 * integration tests.
 *
 * @see https://core.telegram.org/bots/api#sendmessage
 */
final class TelegramChannel implements NotificationChannel
{
    private readonly HttpClient $http;

    /**
     * @param (callable(string, array<string, mixed>, list<string>): array{status: int, body: string})|null $poster
     *        Test-only: when set, called instead of HttpClient for the actual request.
     *        Receives (url, jsonPayload, headersList). Return {status, body}.
     */
    public function __construct(
        private readonly ?string $botToken = null,
        ?HttpClient $http = null,
        private readonly mixed $poster = null,
    ) {
        $this->http = $http ?? new HttpClient([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }

    /**
     * Send a notification via Telegram.
     *
     * @param array<string, mixed> $meta
     *        Supports: chat_id (override for $userId, e.g. a group chat id),
     *        parse_mode (default 'Markdown'), disable_web_page_preview (bool),
     *        disable_notification (bool).
     */
    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool
    {
        $token = $this->botToken ?: (string) config('bot.token', '');
        if ($token === '') {
            return false;
        }

        $chatId    = $meta['chat_id'] ?? $userId;
        $parseMode = (string) ($meta['parse_mode'] ?? 'Markdown');
        $text      = $title !== '' ? "*$title*\n\n$body" : $body;

        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => $meta['disable_web_page_preview'] ?? true,
        ];
        if (isset($meta['disable_notification'])) {
            $payload['disable_notification'] = (bool) $meta['disable_notification'];
        }

        $url     = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $headers = ['Content-Type: application/json'];

        try {
            if ($this->poster !== null) {
                $result = ($this->poster)($url, $payload, $headers);
                return $result['status'] === 200;
            }
            $response = $this->http->post($url, ['json' => $payload]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}
