<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth as CoreAuth;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;

/**
 * Blocks requests that lack an authenticated actor (web session or bearer
 * JWT — see Core\Auth). Optionally requires a specific role:
 *   'Auth'            any authenticated user
 *   'Auth:admin'      authenticated AND Core\Auth::hasRole('admin')
 */
final class Auth implements Middleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        if (!CoreAuth::check()) {
            throw HttpException::unauthorized();
        }
        if ($arg !== null && $arg !== '' && !CoreAuth::hasRole($arg)) {
            throw HttpException::forbidden();
        }
    }
}
