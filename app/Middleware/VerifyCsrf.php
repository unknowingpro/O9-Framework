<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
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

        $given = (string) $request->bodyParam('_csrf', '');
        if (!Session::checkCsrf($given)) {
            if ($request->wantsJson()) {
                throw HttpException::csrfMismatch();
            }
            Session::flash('Your session expired — please try again.', 'error');
            // Bounce back to the referring page (or home) rather than resubmitting.
            View::redirect(safe_back('/'));
        }
    }
}
