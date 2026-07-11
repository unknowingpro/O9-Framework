<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Canonical API error-code taxonomy. These are the stable, machine-readable codes a client branches
 * on (in the `error.code` field of the `{ok,data,error}` envelope); each maps to a default HTTP
 * status so call sites don't repeat status numbers. Endpoints adopt these incrementally — use a
 * canonical code where one fits, and `fail()` to emit it.
 */
final class ApiError
{
    public const BAD_REQUEST      = 'bad_request';        // 400
    public const VALIDATION       = 'validation_failed';  // 422
    public const UNAUTHORIZED     = 'unauthorized';       // 401
    public const FORBIDDEN        = 'forbidden';          // 403
    public const NOT_FOUND        = 'not_found';          // 404
    public const CONFLICT         = 'conflict';           // 409
    public const RATE_LIMITED     = 'rate_limited';       // 429
    public const PAYMENT_REQUIRED = 'payment_required';   // 402
    public const SERVER_ERROR     = 'server_error';       // 500

    /** @return list<string> the canonical codes. */
    public static function codes(): array
    {
        return [
            self::BAD_REQUEST, self::VALIDATION, self::UNAUTHORIZED, self::FORBIDDEN,
            self::NOT_FOUND, self::CONFLICT, self::RATE_LIMITED, self::PAYMENT_REQUIRED, self::SERVER_ERROR,
        ];
    }

    /** Default HTTP status for a canonical code (unknown codes default to 400). */
    public static function status(string $code): int
    {
        return match ($code) {
            self::VALIDATION       => 422,
            self::UNAUTHORIZED     => 401,
            self::FORBIDDEN        => 403,
            self::NOT_FOUND        => 404,
            self::CONFLICT         => 409,
            self::RATE_LIMITED     => 429,
            self::PAYMENT_REQUIRED => 402,
            self::SERVER_ERROR     => 500,
            default                => 400,
        };
    }

    /** Human-readable default message for a code. */
    public static function defaultMessage(string $code): string
    {
        return match ($code) {
            self::BAD_REQUEST      => 'Bad request.',
            self::VALIDATION       => 'The request failed validation.',
            self::UNAUTHORIZED     => 'Authentication required.',
            self::FORBIDDEN        => 'You do not have access to this resource.',
            self::NOT_FOUND        => 'Resource not found.',
            self::CONFLICT         => 'The request conflicts with the current state.',
            self::RATE_LIMITED     => 'Too many requests. Please slow down.',
            self::PAYMENT_REQUIRED => 'Payment is required.',
            self::SERVER_ERROR     => 'An unexpected error occurred.',
            default                => 'Request failed.',
        };
    }

    /**
     * Emit the error envelope with the code's canonical status.
     *
     * @param array<string, mixed>|null $details
     */
    public static function fail(string $code, string $message = '', ?array $details = null): never
    {
        Response::error($code, $message !== '' ? $message : self::defaultMessage($code), self::status($code), $details);
    }
}
