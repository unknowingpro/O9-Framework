<?php
declare(strict_types=1);

namespace App\Core;

/**
 * EventListeners — central registration of the app's event listeners.
 *
 * Called once from the web/API bootstrap (App::run) and the CLI worker
 * (setup/bin/console) so events fire for both request- and CLI-triggered
 * actions. Keeping listeners here decouples cross-cutting side effects from
 * the services that dispatch the events. Idempotent.
 *
 * The framework ships this file empty — each app fills register() with its
 * own Events::listen() calls, e.g.:
 *
 *   Events::listen('user.registered', static function (array $p): void {
 *       (new \App\Services\NotificationService())->welcome((int) $p['user_id']);
 *   });
 */
final class EventListeners
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // App listeners go here (or in app/bootstrap.php via Events::listen()).
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$registered = false;
        Events::flush();
    }
}
