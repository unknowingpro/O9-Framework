<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session — thin $_SESSION accessor: CSRF tokens and one-shot flash messages.
 *
 * Session bootstrap (name, cookie params, Redis handler, idle/absolute
 * timeouts) is owned entirely by App::startSession() during kernel boot for
 * web routes — the JSON API stays stateless and never starts one. This class
 * doesn't call session_start() itself; it only reads/writes $_SESSION, so
 * there's exactly one place that configures the session cookie.
 */
final class Session
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Wipe all session data and destroy the session (login/logout flows that need a full reset). */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    // ── CSRF ─────────────────────────────────────────────────────────────

    public static function csrf(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        return $expected !== '' && $token !== null && hash_equals($expected, $token);
    }

    // ── Flash messages (one-shot) ────────────────────────────────────────

    public static function flash(string $msg, string $type = 'ok'): void
    {
        $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
    }

    /** @return list<array{msg: string, type: string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? array_values($f) : [];
    }
}
