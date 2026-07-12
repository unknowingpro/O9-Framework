<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

/**
 * Verifies the CSRF token on every state-changing web request (POST/PUT/
 * DELETE/PATCH). Safe methods pass through. The JSON API is exempt — it
 * authenticates with a bearer JWT, not a session cookie, so it isn't
 * vulnerable to classic CSRF.
 *
 * The token is the per-session value from Session::csrf(); forms emit it as
 * a hidden `_csrf` field. Comparison is constant-time (Session::checkCsrf()).
 *
 * Origin/Referer are also checked as a cheap defense-in-depth layer ahead of
 * the token check, not a replacement for it — a browser-set Origin header
 * can't be forged by the requesting page's own JS, so a mismatch here is a
 * strong forgery signal. When neither header is present (some privacy tools
 * strip both), this check defers entirely to the token, per how sparse that
 * signal legitimately can be.
 */
final class VerifyCsrf implements Middleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        $method = $request->method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        // API routes use token auth — skip cookie-CSRF there.
        if (str_starts_with($request->path(), '/api/')) {
            return;
        }

        if (!$this->originMatchesHost($request)) {
            $this->reject($request);
        }

        $given = (string) $request->bodyParam('_csrf', '');
        if (!Session::checkCsrf($given)) {
            $this->reject($request);
        }
    }

    /** True when Origin/Referer is absent (can't verify — defer to the token) or its host matches this request's own Host. */
    private function originMatchesHost(Request $request): bool
    {
        $origin = $request->header('origin', '');
        $source = $origin !== '' ? $origin : $request->header('referer', '');
        if ($source === '') {
            return true;
        }
        $host = (string) (parse_url($source, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return true;
        }
        return strtolower($host) === strtolower((string) preg_replace('/:\d+$/', '', $request->header('host', '')));
    }

    private function reject(Request $request): never
    {
        if (class_exists(Logger::class)) {
            Logger::warning('auth.csrf_rejected', [
                'ip'     => $request->ip(),
                'method' => $request->method(),
                'path'   => $request->path(),
            ], 'security');
        }
        if ($request->wantsJson()) {
            throw HttpException::csrfMismatch();
        }
        Session::flash('Your session expired — please try again.', 'error');
        // Bounce back to the referring page (or home) rather than resubmitting.
        View::redirect(safe_back('/'));
    }
}
