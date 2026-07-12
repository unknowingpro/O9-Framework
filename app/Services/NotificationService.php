<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Channel-contract notification dispatcher. The framework ships no concrete
 * channel (Telegram, Web Push, Ntfy, Discord, email...) — each is app-
 * specific wiring — but this gives every channel one contract and one
 * fan-out point, so a single notify() call reaches every registered
 * channel without the caller knowing which ones are configured.
 *
 * Register channels in app/bootstrap.php:
 *
 *   NotificationService::registerChannel('telegram', new TelegramChannel());
 *   NotificationService::registerChannel('webpush', new class implements NotificationChannel {
 *       public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool {
 *           \App\Core\Queue::push(\App\Jobs\SendWebPushJob::class, ['user_id' => $userId, 'data' => compact('title', 'body')]);
 *           return true;
 *       }
 *   });
 *
 * A channel that throws is isolated and logged — one broken integration
 * never blocks the others or the caller.
 */
final class NotificationService
{
    /** @var array<string, NotificationChannel> */
    private static array $channels = [];

    public static function registerChannel(string $name, NotificationChannel $channel): void
    {
        self::$channels[$name] = $channel;
    }

    public static function unregisterChannel(string $name): void
    {
        unset(self::$channels[$name]);
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$channels = [];
    }

    /**
     * Notify a user through every registered channel. $type is a short,
     * app-defined event key (e.g. 'subscription_payment_failed') a channel
     * can use to pick a template; $meta carries structured context.
     *
     * @param array<string, mixed> $meta
     */
    public function notify(int $userId, ?int $fromUserId, string $type, string $title = '', string $body = '', array $meta = []): void
    {
        foreach (self::$channels as $name => $channel) {
            try {
                $channel->send($userId, $type, $title, $body, $meta + ['from_user_id' => $fromUserId]);
            } catch (\Throwable $e) {
                if (class_exists(Logger::class)) {
                    Logger::error('notification.channel_failed', ['channel' => $name, 'type' => $type, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
