<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Middleware contract. Implementations either pass-through (returning normally)
 * or terminate the request by emitting/throwing an error response.
 *
 * Routes may reference middleware three ways:
 *   - an instance:            new RateLimit(240, 60, 'api')
 *   - a class-string:         RateLimit::class
 *   - a short name with arg:  'RateLimit:auth'  → App\Middleware\RateLimit,
 *     handle() receives 'auth' as $arg
 */
interface Middleware
{
    public function handle(Request $request, ?string $arg = null): void;
}
