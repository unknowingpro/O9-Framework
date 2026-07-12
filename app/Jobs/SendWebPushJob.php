<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Job;

/**
 * Deliver a Web Push (VAPID) notification out of the request path, so a
 * slow/failed push neither blocks the response nor is silently lost — the
 * queue retries it (see Core\Queue).
 *
 * The framework doesn't ship a push-sending service (VAPID keys, subscription
 * storage, and the push payload shape are all app-specific). Apps register
 * how to actually deliver via handleUsing(), typically in app/bootstrap.php:
 *
 *   SendWebPushJob::handleUsing(
 *       fn (int $userId, array $data) => (new WebPushService())->pushToUser($userId, $data)
 *   );
 *
 * Without a registered handler, the job is a silent no-op — safe default for
 * an app that hasn't wired push yet.
 */
final class SendWebPushJob implements Job
{
    /** @var (callable(int, array<string, mixed>): void)|null */
    private static $handler = null;

    /** @param (callable(int, array<string, mixed>): void)|null $fn */
    public static function handleUsing(?callable $fn): void
    {
        self::$handler = $fn;
    }

    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $data = $payload['data'] ?? [];
        if ($userId > 0 && self::$handler !== null) {
            (self::$handler)($userId, is_array($data) ? $data : []);
        }
    }
}
