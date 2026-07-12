<?php
declare(strict_types=1);

namespace App\Services;

/**
 * A single notification delivery channel (Telegram, Web Push, email,
 * Discord, Ntfy, ...). Implementations should be fast or hand off to the
 * queue themselves (see Jobs\SendWebPushJob) — NotificationService calls
 * every registered channel synchronously.
 */
interface NotificationChannel
{
    /**
     * @param array<string, mixed> $meta
     * @return bool true if the channel accepted the notification for delivery.
     */
    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool;
}
