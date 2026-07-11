<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A throwable that carries an HTTP status + a canonical {@see ApiError} code + a
 * user-safe message. Lets any layer (service, controller, middleware) signal an
 * outcome — "not found", "forbidden", "conflict" — by THROWING, and have the
 * global handler render the correct status for both web (HTML) and API (JSON),
 * instead of every uncaught throwable collapsing to a 500.
 *
 * The base RuntimeException `message` is the developer/log message; `publicMessage`
 * is what's safe to show an end user (defaults to the code's catalog message so we
 * never leak internals). `details` carry structured context (e.g. validation field
 * errors) surfaced in the API `error.details`.
 *
 * Throw via the named factories — `throw HttpException::notFound('Plan not found')` —
 * so call sites read intent-first and never repeat status numbers.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed>|null $details */
    final public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $logMessage = '',
        public readonly ?string $publicMessage = null,
        public readonly ?array $details = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : ApiError::defaultMessage($errorCode), 0, $previous);
    }

    /** The message safe to show an end user (never the raw internal message). */
    public function userMessage(): string
    {
        return $this->publicMessage ?? ApiError::defaultMessage($this->errorCode);
    }

    /** @param array<string,mixed>|null $details */
    public static function make(string $code, string $publicMessage = '', ?array $details = null, ?\Throwable $previous = null): self
    {
        return new static(ApiError::status($code), $code, $publicMessage, $publicMessage !== '' ? $publicMessage : null, $details, $previous);
    }

    /** @param array<string,mixed>|null $details */
    public static function badRequest(string $msg = '', ?array $details = null): self    { return self::make(ApiError::BAD_REQUEST, $msg, $details); }
    public static function unauthorized(string $msg = ''): self                            { return self::make(ApiError::UNAUTHORIZED, $msg); }
    public static function forbidden(string $msg = ''): self                               { return self::make(ApiError::FORBIDDEN, $msg); }
    public static function notFound(string $msg = ''): self                                { return self::make(ApiError::NOT_FOUND, $msg); }
    public static function conflict(string $msg = ''): self                                { return self::make(ApiError::CONFLICT, $msg); }
    public static function tooManyRequests(string $msg = ''): self                         { return self::make(ApiError::RATE_LIMITED, $msg); }
    public static function paymentRequired(string $msg = ''): self                         { return self::make(ApiError::PAYMENT_REQUIRED, $msg); }
    public static function server(string $msg = ''): self                                  { return self::make(ApiError::SERVER_ERROR, $msg); }

    /** @param array<string,list<string>> $fieldErrors */
    public static function validation(array $fieldErrors, string $msg = ''): self
    {
        return self::make(ApiError::VALIDATION, $msg, $fieldErrors);
    }
}
