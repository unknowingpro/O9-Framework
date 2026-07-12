<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Middleware;
use App\Core\Request;
use App\Identity\Rbac;

/**
 * Capability-scoped admin gate: requires the authenticated user to hold a
 * specific permission (via Identity\Rbac), not just an 'admin' role. So
 * /admin/* endpoints are scoped exactly to what each admin was granted — a
 * limited admin can't reach an endpoint outside their permissions.
 *
 * Usage (the router accepts instantiated middleware):
 *   [new RequireCap('moderation')]
 * or the 'ShortName:arg' form: 'RequireCap:moderation'.
 *
 * State-changing requests are reported via an optional auditUsing() hook
 * (the same injectable pattern as Entitlements\EntitlementService), so apps
 * that want an admin audit trail can wire one without this middleware
 * depending on a specific logging service.
 */
final class RequireCap implements Middleware
{
    /** @var (callable(int, string, string, string): void)|null */
    private static $auditor = null;

    public function __construct(private readonly string $cap)
    {
    }

    /** @param (callable(int, string, string, string): void)|null $fn */
    public static function auditUsing(?callable $fn): void
    {
        self::$auditor = $fn;
    }

    public function handle(Request $request, ?string $arg = null): void
    {
        $cap = $arg ?? $this->cap;
        if (!Auth::check()) {
            throw HttpException::unauthorized();
        }
        $user = Auth::user();
        if ($user === null || !Rbac::can($user, $cap)) {
            if (class_exists(Logger::class)) {
                Logger::warning('auth.capability_denied', [
                    'ip'     => $request->ip(),
                    'uid'    => Auth::id(),
                    'cap'    => $cap,
                    'method' => $request->method(),
                    'path'   => $request->path(),
                ], 'security');
            }
            throw HttpException::forbidden();
        }
        if (self::$auditor !== null && !in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $userId = Auth::id();
            if ($userId !== null) {
                (self::$auditor)($userId, $cap, $request->method(), $request->path());
            }
        }
    }
}
