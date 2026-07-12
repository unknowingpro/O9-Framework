<?php
declare(strict_types=1);

namespace App\Notifications;

use App\Core\HttpClient;
use App\Services\NotificationChannel;

/**
 * Webhook notification channel — POSTs a JSON payload to a configurable URL.
 *
 * URL resolution, in priority order:
 *   1. $meta['webhook_url']  (per-notification override)
 *   2. Constructor $defaultUrl argument
 *   3. config('notifications.webhook.default_url')
 *
 * The payload shape is:
 *   { user_id, type, title, body, meta }
 *
 * Usage:
 *   NotificationService::registerChannel('webhook', new WebhookChannel(
 *       defaultUrl: 'https://hooks.example.com/notify',
 *   ));
 *   $service->notify($userId, null, 'deploy.success', 'Deploy finished', 'v3.2 is live.');
 *
 * Testing: pass a $poster callable to intercept the HTTP request (same pattern
 * as MailgunTransport's $poster), or a pre-configured HttpClient.
 */
final class WebhookChannel implements NotificationChannel
{
    private readonly HttpClient $http;

    /**
     * @param (callable(string, array<string, mixed>, list<string>): array{status: int, body: string})|null $poster
     *        Test-only: when set, called instead of HttpClient for the actual request.
     *        Receives (url, jsonPayload, headersList). Return {status, body}.
     */
    public function __construct(
        private readonly ?string $defaultUrl = null,
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
     * Send a notification via webhook POST.
     *
     * @param array<string, mixed> $meta
     *        Supports: webhook_url (per-call override), plus any custom fields
     *        that should be forwarded in the payload body.
     *
     * @return bool true on any 2xx/3xx response from the webhook endpoint.
     */
    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool
    {
        $url = $meta['webhook_url']
            ?? $this->defaultUrl
            ?? (string) config('notifications.webhook.default_url', '');

        if ($url === '') {
            return false;
        }

        $payload = [
            'user_id' => $userId,
            'type'    => $type,
            'title'   => $title,
            'body'    => $body,
            'meta'    => $meta,
        ];

        try {
            if ($this->poster !== null) {
                $result = ($this->poster)($url, $payload, ['Content-Type: application/json']);
                return $result['status'] < 400;
            }
            $response = $this->http->post($url, ['json' => $payload]);
            return $response->getStatusCode() < 400;
        } catch (\Throwable) {
            return false;
        }
    }
}
