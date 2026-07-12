<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Security\Jwt;

/**
 * Session + JWT identity layer. The current user is resolved from either
 * a $_SESSION['user_id'] (web) or a Bearer JWT (API). Result is memoised
 * per request.
 *
 * The framework does not know the app's user storage. Apps register hooks
 * (usually in app/bootstrap.php):
 *
 *   Auth::resolveUserUsing(fn (int $id) => (new UserModel())->find($id));
 *   Auth::touchUsing(fn (array $user) => ...);         // optional last-seen stamping
 *   Auth::checkDeviceUsing(fn (string $did) => ...);   // optional per-device logout
 *
 * Without a resolver, an authenticated request still yields a minimal
 * ['id' => <sub>] actor — enough for stateless JWT-only APIs with no user
 * table. Force-logout epoch checks only fire when the resolved user row
 * carries a non-empty `force_logout_at`.
 */
final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $cached = null;
    private static bool $resolved = false;

    /** @var (callable(int): (array<string, mixed>|null))|null */
    private static $userResolver = null;
    /** @var (callable(array<string, mixed>): void)|null */
    private static $toucher = null;
    /** @var (callable(string): bool)|null */
    private static $deviceChecker = null;

    /** Register how a user id becomes a user row. @param callable(int): (array<string, mixed>|null) $fn */
    public static function resolveUserUsing(callable $fn): void
    {
        self::$userResolver = $fn;
    }

    /** Register a last-seen/activity stamper, called once per resolved request. @param (callable(array<string, mixed>): void)|null $fn */
    public static function touchUsing(?callable $fn): void
    {
        self::$toucher = $fn;
    }

    /** Register the per-device (`did` claim) liveness check for access tokens. @param (callable(string): bool)|null $fn */
    public static function checkDeviceUsing(?callable $fn): void
    {
        self::$deviceChecker = $fn;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;

        // Only resume an EXISTING session (its cookie is present). A cookieless request — the
        // stateless JWT/API path, or CLI — must not spin up a fresh session; it falls through to
        // the bearer token below. (Web requests already have an active session via App::startSession.)
        $sessionName = (string) config('app.session_name', 'o9_session');
        if (session_status() !== PHP_SESSION_ACTIVE && isset($_COOKIE[$sessionName])) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? null;

        $jwt = null; // access-token payload (when the bearer path is taken) — drives the checks below
        if (!$userId) {
            $token = (new Request())->bearerToken();
            if ($token) {
                $payload = Jwt::decode($token);
                if ($payload && isset($payload['sub'])) {
                    // A refresh token is an opaque secret, never a JWT; reject
                    // anything that decodes with typ:refresh as access creds.
                    if (($payload['typ'] ?? null) !== 'refresh') {
                        $userId = (int) $payload['sub'];
                        $jwt = $payload;
                    }
                }
            }
        }
        if (!$userId) {
            return self::$cached = null;
        }
        $user = self::$userResolver !== null
            ? (self::$userResolver)((int) $userId)
            : ['id' => (int) $userId];
        // Force-logout epoch (admin): if this is a web session whose auth_at
        // predates the user's force_logout_at, the admin revoked it — destroy
        // the session and treat the request as logged-out. Bearer/JWT requests
        // (no auth_at) are unaffected here; they expire on their own TTL.
        if ($user && isset($_SESSION['user_id'], $_SESSION['auth_at']) && !empty($user['force_logout_at'])
            && (string) $_SESSION['auth_at'] < (string) $user['force_logout_at']) {
            self::logout();
            return self::$cached = null;
        }
        // Native access-token enforcement (typ:access only — legacy typ-absent
        // bearers keep their old behaviour). Honour the force-logout epoch via
        // the token's iat, and per-device logout via the `did` claim.
        if ($user && $jwt !== null && ($jwt['typ'] ?? null) === 'access') {
            if (!empty($user['force_logout_at'])) {
                $iat = (int) ($jwt['iat'] ?? 0);
                if ($iat > 0 && $iat < (int) strtotime((string) $user['force_logout_at'] . ' UTC')) {
                    return self::$cached = null;
                }
            }
            $did = (string) ($jwt['did'] ?? '');
            if ($did !== '' && self::$deviceChecker !== null && !(self::$deviceChecker)($did)) {
                return self::$cached = null;
            }
        }
        if ($user && self::$toucher !== null) {
            (self::$toucher)($user);
        }
        return self::$cached = $user ?: null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // Prevent session fixation: an attacker who plants their session ID on
        // the victim before they log in must not end up sharing a session with
        // them after. Regenerating here gives the authenticated session a new
        // ID and drops the old one server-side.
        @session_regenerate_id(true);
        // Rotate the CSRF token at the same time. Without this, a token the
        // attacker captured during the pre-login phase would still be valid
        // for post-login form submissions (CSRF in the authenticated session).
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = $userId;
        // Session epoch for admin "force logout all": a session is valid only
        // while its auth_at is >= the user's force_logout_at (see user()).
        $_SESSION['auth_at'] = gmdate('Y-m-d H:i:s');
        self::$cached = null;
        self::$resolved = false;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // Wipe everything, including non-user_id session data (flashes, CSRF
        // tokens, anything else stashed there), then issue a fresh ID so the
        // logged-out cookie can't be replayed.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                (string) session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }
        @session_regenerate_id(true);
        self::$cached = null;
        self::$resolved = true;
    }

    public static function hasRole(string $role): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $roles = explode(',', (string) ($u['roles'] ?? ''));
        return in_array($role, $roles, true);
    }

    /** @internal test reset — clears memoised state and all registered hooks. */
    public static function reset(): void
    {
        self::$cached = null;
        self::$resolved = false;
        self::$userResolver = null;
        self::$toucher = null;
        self::$deviceChecker = null;
    }
}
