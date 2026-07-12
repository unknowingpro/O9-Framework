<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Middleware;
use App\Core\Request;

/**
 * Authenticates JSON API requests with an API key. The key may be presented
 * as an `X-API-Key` header, an `Authorization: Bearer <key>` header, an
 * `api_key` parameter (query or body), or `_k` — matching what a variety of
 * existing clients send.
 *
 * The framework doesn't own API-key storage (that's an app-specific table
 * and scope model), so lookup and scope-checking are injectable hooks —
 * the same pattern as Auth/Lang — wired in app/bootstrap.php:
 *
 *   ApiKey::resolveUsing(fn (string $key) => (new ApiKeyModel())->verify($key));
 *   ApiKey::scopeCheckUsing(fn (array $row, string $scope) => ApiKeyModel::hasScope($row, $scope));
 *
 * On success the resolved key row is available via ApiKey::current() for
 * the rest of the request. On failure an HttpException (401/403) is thrown.
 */
final class ApiKey implements Middleware
{
    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    /** @var (callable(string): (array<string, mixed>|null))|null */
    private static $resolver = null;

    /** @var (callable(array<string, mixed>, string): bool)|null */
    private static $scopeChecker = null;

    /** @param (callable(string): (array<string, mixed>|null))|null $fn */
    public static function resolveUsing(?callable $fn): void
    {
        self::$resolver = $fn;
    }

    /** @param (callable(array<string, mixed>, string): bool)|null $fn */
    public static function scopeCheckUsing(?callable $fn): void
    {
        self::$scopeChecker = $fn;
    }

    /**
     * The resolved key row for the current request, or null before/without a match.
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$current = null;
        self::$resolver = null;
        self::$scopeChecker = null;
    }

    public function handle(Request $request, ?string $arg = null): void
    {
        $key = $this->extractKey($request);
        if ($key === '') {
            throw HttpException::unauthorized('Missing API key.');
        }
        if (self::$resolver === null) {
            throw new \RuntimeException('ApiKey middleware: no resolver registered (call ApiKey::resolveUsing()).');
        }

        $row = (self::$resolver)($key);
        if ($row === null) {
            // Security event: a key was presented but didn't match an active key
            // (brute-force / stolen-key probe). Log a redacted prefix only —
            // never the full key — so the attempt is auditable without leaking secrets.
            if (class_exists(Logger::class)) {
                Logger::warning('auth.invalid_key', [
                    'ip'         => $request->ip(),
                    'method'     => $request->method(),
                    'path'       => $request->path(),
                    'key_prefix' => substr($key, 0, 8),
                ], 'security');
            }
            throw HttpException::unauthorized('Invalid or inactive API key.');
        }

        self::$current = $row;

        // Read-only keys (a scopes list without 'write') may not mutate. GET/HEAD/OPTIONS are reads.
        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            && self::$scopeChecker !== null && !(self::$scopeChecker)($row, 'write')) {
            throw HttpException::forbidden('This API key is read-only (missing "write" scope).');
        }
    }

    private function extractKey(Request $request): string
    {
        $header = trim($request->header('X-API-Key'));
        if ($header !== '') {
            return $header;
        }
        $bearer = $request->bearerToken();
        if (is_string($bearer) && trim($bearer) !== '') {
            return trim($bearer);
        }
        $key = trim((string) $request->input('api_key', ''));
        if ($key !== '') {
            return $key;
        }
        return trim((string) $request->input('_k', ''));
    }
}
