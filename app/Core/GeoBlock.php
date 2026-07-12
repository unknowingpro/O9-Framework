<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\SettingsService;
use Throwable;

/**
 * Country geo-blocking. When enabled, requests from a blocked country are
 * refused with 451. The visitor's country comes from a CDN edge header
 * (Cloudflare's CF-IPCountry, or a generic X-Geo-Country / X-Country) — fast and
 * free, no per-request lookup.
 *
 * Two deliberate safety properties:
 *
 *   - FAIL OPEN when no country can be determined. A missing header (app moved
 *     off the CDN, health checks, local dev) must never lock the whole site out.
 *   - Admins, auth routes and assets are always exempt, so an operator can't
 *     geo-block themselves out of the panel that turns geo-blocking off.
 *
 * Trusting a client-supplied header is only sound behind a CDN/proxy that
 * overwrites it. Do not enable this when the app is directly reachable — a
 * caller could simply send their own CF-IPCountry.
 */
final class GeoBlock
{
    /** @var list<string> */
    private const DEFAULT_ALLOW = ['/assets', '/admin', '/auth', '/login', '/logout', '/health'];

    /** @var list<string> */
    private const HEADERS = ['CF-IPCountry', 'X-Geo-Country', 'X-Country'];

    public static function isOn(): bool
    {
        try {
            return (string) (SettingsService::get('security.geo_blocking') ?? '') === '1';
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> upper-case ISO-3166 alpha-2 codes */
    public static function blockedCountries(): array
    {
        try {
            $raw = SettingsService::get('security.geo_blocked_countries');
        } catch (Throwable) {
            return [];
        }

        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        if (!is_array($list)) {
            return [];
        }

        $codes = [];
        foreach ($list as $c) {
            $code = strtoupper(trim((string) $c));
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** The request's country from a CDN edge header, or null when unknown. */
    public static function countryFor(Request $request): ?string
    {
        foreach (self::HEADERS as $h) {
            $v = strtoupper(trim($request->header($h)));
            // Cloudflare sends 'XX' for unknown and 'T1' for Tor — both are "no country".
            if ($v !== '' && $v !== 'XX' && $v !== 'T1' && preg_match('/^[A-Z]{2}$/', $v)) {
                return $v;
            }
        }

        return null;
    }

    public static function shouldBlock(Request $request): bool
    {
        if (!self::isOn()) {
            return false;
        }

        $path = $request->path();
        /** @var list<string> $allow */
        $allow = (array) config('app.geo.allow_paths', self::DEFAULT_ALLOW);
        foreach ($allow as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/')) {
                return false;
            }
        }

        if (Auth::check() && Auth::hasRole('admin')) {
            return false;
        }

        $blocked = self::blockedCountries();
        if ($blocked === []) {
            return false;
        }

        $country = self::countryFor($request);

        // Fail open: unknown country is never blocked.
        return $country !== null && in_array($country, $blocked, true);
    }

    /** The 451, as a throwable response — App::run() catches it and emits it. */
    public static function serve(Request $request): never
    {
        $message = ApiError::defaultMessage(ApiError::GEO_BLOCKED);

        if ($request->wantsJson()) {
            throw new HttpResponse(451, [
                'ok'    => false,
                'data'  => null,
                'error' => ['code' => ApiError::GEO_BLOCKED, 'message' => $message],
            ]);
        }

        throw new HttpResponse(451, self::html($message), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private static function html(string $message): string
    {
        try {
            if (is_file(base_path('app/Views/pages/geo-blocked.php'))) {
                return View::capture('pages/geo-blocked', ['message' => $message]);
            }
        } catch (Throwable) {
            // fall through to the built-in page
        }

        return '<!doctype html><meta charset="utf-8"><title>451 — Unavailable</title>'
            . '<div style="font-family:system-ui,sans-serif;padding:48px;text-align:center">'
            . '<h1>451</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div>';
    }
}
