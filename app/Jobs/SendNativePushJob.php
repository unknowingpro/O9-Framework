<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Job;

/**
 * Deliver a native push (FCM → Android + iOS-via-APNs-relay) out of the
 * request path, alongside SendWebPushJob, so a slow/failed push neither
 * blocks the response nor is silently lost.
 *
 * As with SendWebPushJob, the framework doesn't ship a push-sending service
 * (FCM/APNs credentials and device-token storage are app-specific). Apps
 * register the actual delivery via handleUsing(), typically in
 * app/bootstrap.php:
 *
 *   SendNativePushJob::handleUsing(
 *       fn (int $userId, array $data) => (new NativePushService())->pushToUser($userId, $data)
 *   );
 *
 * Without a registered handler, the job is a silent no-op.
 */
final class SendNativePushJob implements Job
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
