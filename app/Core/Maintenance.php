<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\SettingsService;
use Throwable;

/**
 * Maintenance mode: 503 for everyone except admins (who keep working so they can
 * fix things), the auth routes (so an admin can still log in) and static assets.
 *
 * Two independent switches, either one turns it on:
 *
 *   1. The flag file `storage/maintenance.flag` — `touch` it to go down, delete
 *      it to come back. Its first line, if any, is the message.
 *   2. The `maintenance_on` setting — toggled from the admin panel.
 *
 * The flag file exists precisely because the setting lives in the database. When
 * the database is the thing that's broken — the exact moment you most need to
 * put the site into maintenance — a DB-backed toggle cannot be read or written,
 * so it can't be turned on at all. The file needs nothing but a filesystem, and
 * is checked first.
 */
final class Maintenance
{
    /** @var list<string> path prefixes that stay reachable while down. */
    private const DEFAULT_ALLOW = ['/assets', '/admin', '/auth', '/login', '/logout', '/health'];

    public static function flagPath(): string
    {
        return storage_path('maintenance.flag');
    }

    public static function isOn(): bool
    {
        if (is_file(self::flagPath())) {
            return true;
        }

        try {
            return (string) (SettingsService::get('maintenance_on') ?? '') === '1';
        } catch (Throwable) {
            return false; // DB unreachable — the flag file is the only reliable switch.
        }
    }

    public static function message(): string
    {
        $flag = self::flagPath();
        if (is_file($flag)) {
            $raw = trim((string) @file_get_contents($flag));
            if ($raw !== '') {
                return (string) strtok($raw, "\n");
            }
        }

        try {
            $msg = trim((string) (SettingsService::get('maintenance_msg') ?? ''));
        } catch (Throwable) {
            $msg = '';
        }

        return $msg !== '' ? $msg : ApiError::defaultMessage(ApiError::MAINTENANCE);
    }

    public static function shouldBlock(Request $request): bool
    {
        if (!self::isOn()) {
            return false;
        }

        $path = $request->path();
        /** @var list<string> $allow */
        $allow = (array) config('app.maintenance.allow_paths', self::DEFAULT_ALLOW);
        foreach ($allow as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/')) {
                return false;
            }
        }

        // Admins bypass entirely so an operator is never locked out of their own site.
        return !(Auth::check() && Auth::hasRole('admin'));
    }

    /**
     * The 503, as a throwable response — the same short-circuit every other
     * layer uses (Response, View::redirect, Router 404s). App::run() catches it
     * and emits status + headers + body.
     */
    public static function serve(Request $request): never
    {
        $message = self::message();
        $headers = ['Retry-After' => (string) (int) config('app.maintenance.retry_after', 3600)];

        if ($request->wantsJson()) {
            throw new HttpResponse(503, [
                'ok'    => false,
                'data'  => null,
                'error' => ['code' => ApiError::MAINTENANCE, 'message' => $message],
            ], $headers);
        }

        throw new HttpResponse(503, self::html($message), $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** The app's own pages/maintenance view when it has one, else a self-contained page. */
    private static function html(string $message): string
    {
        try {
            if (is_file(base_path('app/Views/pages/maintenance.php'))) {
                return View::capture('pages/maintenance', ['message' => $message]);
            }
        } catch (Throwable) {
            // fall through to the built-in page
        }

        return '<!doctype html><meta charset="utf-8"><title>503 — Service unavailable</title>'
            . '<div style="font-family:system-ui,sans-serif;padding:48px;text-align:center">'
            . '<h1>503</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div>';
    }
}
