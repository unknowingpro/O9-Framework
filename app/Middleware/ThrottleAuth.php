<?php
declare(strict_types=1);

namespace App\Middleware;

/** Tight throttle for auth endpoints: 5 attempts per 5 minutes per IP+path. */
final class ThrottleAuth extends RateLimit
{
    protected function limit(): int { return 5; }
    protected function windowSeconds(): int { return 300; }
}
