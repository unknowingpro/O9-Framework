<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Loads /app/Lang/{locale}.php arrays and resolves keys (flat key=>string
 * fast path, or dot-notation into nested arrays for locale files that use
 * sections).
 *
 * Locale resolution order (web context; first match wins):
 *   1. ?lang=XX query string (persisted to cookie +, via a registered hook,
 *      the user's profile)
 *   2. logged-in user's saved locale (Auth::user()['locale'])
 *   3. lang cookie
 *   4. Accept-Language header best match
 *   5. config('app.default_locale') default
 *
 * English is the base/fallback locale. Missing keys fall back to English
 * (config('app.fallback_locale')), then to the key itself.
 *
 * The framework doesn't know how an app stores per-user locale preference.
 * remember() always sets the cookie; persisting to a user record is opt-in
 * via persistUserLocaleUsing(), e.g.:
 *
 *   Lang::persistUserLocaleUsing(fn (int $userId, string $locale) =>
 *       (new UserModel())->setLocale($userId, $locale));
 */
final class Lang
{
    private static ?string $locale = null;
    /** @var array<string, array<array-key, mixed>> */
    private static array $messages = [];

    /** @var (callable(int, string): void)|null */
    private static $userLocalePersister = null;

    /** Register how a locale gets saved to the current user's profile. @param (callable(int, string): void)|null $fn */
    public static function persistUserLocaleUsing(?callable $fn): void
    {
        self::$userLocalePersister = $fn;
    }

    /**
     * The locale registry from config/locales.php.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        /** @var array<string, array<string, mixed>> */
        return (array) config('locales', []);
    }

    /**
     * Supported locale codes, derived from the registry.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        $reg = self::registry();
        if ($reg !== []) {
            return array_keys($reg);
        }
        if (class_exists(\App\Services\I18n\LanguageService::class)) {
            return \App\Services\I18n\LanguageService::SUPPORTED_CODES;
        }
        /** @var list<string> */
        return (array) config('app.supported_locales', ['en']);
    }

    /** @return array<string, mixed> */
    public static function meta(?string $locale = null): array
    {
        $locale = $locale ?? self::locale();
        return self::registry()[$locale] ?? [];
    }

    public static function setLocale(string $locale): void
    {
        if (!in_array($locale, self::supported(), true)) {
            return;
        }
        self::$locale = $locale;
    }

    /**
     * Switch + REMEMBER a locale: set it for this request and persist it to
     * both the cookie and (via the registered hook, when logged in) the
     * user's profile.
     */
    public static function remember(string $locale): bool
    {
        if (!in_array($locale, self::supported(), true)) {
            return false;
        }
        self::$locale = $locale;
        self::persistCookie($locale);
        self::persistUser($locale);
        return true;
    }

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }
        $supported = self::supported();
        $default   = (string) config('app.default_locale', config('app.locale', 'en'));

        // 1. explicit ?lang — persist to cookie (+ profile if logged in)
        if (!empty($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
            $fromQuery = (string) $_GET['lang'];
            self::$locale = $fromQuery;
            self::persistCookie($fromQuery);
            self::persistUser($fromQuery);
            return $fromQuery;
        }
        // 2. logged-in user's saved locale
        $user = class_exists(Auth::class) ? Auth::user() : null;
        if ($user && !empty($user['locale']) && in_array($user['locale'], $supported, true)) {
            return self::$locale = (string) $user['locale'];
        }
        // 3. cookie
        if (!empty($_COOKIE['lang']) && in_array($_COOKIE['lang'], $supported, true)) {
            return self::$locale = (string) $_COOKIE['lang'];
        }
        // 4. Accept-Language best match
        $fromHeader = self::matchAcceptLanguage((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $supported);
        if ($fromHeader !== null) {
            return self::$locale = $fromHeader;
        }
        // 5. default
        return self::$locale = $default;
    }

    public static function direction(?string $locale = null): string
    {
        $meta = self::meta($locale);
        return ($meta['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }

    /** ICU locale id used by intl formatters (digits, calendar, etc.). */
    public static function intlId(?string $locale = null): string
    {
        $meta = self::meta($locale);
        return (string) ($meta['intl'] ?? ($locale ?? self::locale()));
    }

    public static function calendar(?string $locale = null): string
    {
        return (string) (self::meta($locale)['calendar'] ?? 'gregorian');
    }

    public static function fontGroup(?string $locale = null): string
    {
        return (string) (self::meta($locale)['font'] ?? 'latin');
    }

    public static function currency(?string $locale = null): string
    {
        return (string) (self::meta($locale)['currency'] ?? 'USD');
    }

    /** True when the key resolves to a string in the given (or current) locale or the fallback. */
    public static function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?? self::locale();
        if (self::dig($locale, $key) !== null) {
            return true;
        }
        $fallback = (string) config('app.fallback_locale', 'en');
        return $fallback !== $locale && self::dig($fallback, $key) !== null;
    }

    /**
     * Raw string lookup in a specific locale with fallback-locale fallback,
     * WITHOUT any placeholder substitution. Null when missing everywhere.
     */
    public static function raw(string $key, ?string $locale = null): ?string
    {
        $locale = $locale ?? self::locale();
        $value  = self::dig($locale, $key);
        if ($value === null) {
            $fallback = (string) config('app.fallback_locale', 'en');
            if ($fallback !== $locale) {
                $value = self::dig($fallback, $key);
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $params */
    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        $value = self::raw($key, $locale);
        if ($value === null) {
            return $key;
        }
        if ($params !== []) {
            foreach ($params as $k => $v) {
                $value = str_replace(':' . $k, (string) $v, $value);
            }
        }
        return $value;
    }

    /**
     * Plural-aware translation. The stored value is a set of forms separated
     * by '|', each optionally prefixed with its CLDR category, e.g.:
     *   "one::%count% file|other::%count% files"   or just  "%count% file|%count% files"
     *
     * @param array<string, mixed> $params
     */
    public static function choice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? self::locale();
        $raw = self::raw($key, $locale);
        if ($raw === null) {
            return $key;
        }

        $forms = array_map('trim', explode('|', $raw));
        $byCat = [];
        $positional = [];
        foreach ($forms as $form) {
            if (str_contains($form, '::')) {
                [$cat, $text] = array_map('trim', explode('::', $form, 2));
                $byCat[$cat] = $text;
            } else {
                $positional[] = $form;
            }
        }

        $category = PluralRules::category($locale, $count);
        if (isset($byCat[$category])) {
            $value = $byCat[$category];
        } elseif (isset($byCat['other'])) {
            $value = $byCat['other'];
        } else {
            // positional fallback: [singular, plural]
            $value = $count === 1 ? ($positional[0] ?? '') : ($positional[count($positional) - 1] ?? '');
        }

        $params['count'] = $count;
        foreach ($params as $k => $v) {
            $value = str_replace('%' . $k . '%', (string) $v, $value);
        }
        return $value;
    }

    /** Reset the cached per-locale message arrays (call after adding/editing a language). */
    public static function flush(): void
    {
        self::$messages = [];
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$locale = null;
        self::$messages = [];
        self::$userLocalePersister = null;
    }

    private static function persistCookie(string $locale): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = config('app.env') === 'production'
            ? true
            : (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('lang', $locale, [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'samesite' => 'Lax',
            'httponly' => true,
            'secure'   => $secure,
        ]);
    }

    private static function persistUser(string $locale): void
    {
        if (self::$userLocalePersister === null || !class_exists(Auth::class)) {
            return;
        }
        $id = Auth::id();
        if ($id !== null) {
            (self::$userLocalePersister)($id, $locale);
        }
    }

    /**
     * Parse an Accept-Language header and return the best supported base code.
     *
     * @param list<string> $supported
     */
    private static function matchAcceptLanguage(string $header, array $supported): ?string
    {
        if ($header === '') {
            return null;
        }
        $ranges = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $q = 1.0;
            if (preg_match('/;q=([0-9.]+)/', $part, $m)) {
                $q = (float) $m[1];
            }
            $tag = strtolower(trim(explode(';', $part)[0]));
            $base = explode('-', $tag)[0];
            $ranges[] = [$base, $q];
        }
        usort($ranges, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        foreach ($ranges as [$base, ]) {
            if (in_array($base, $supported, true)) {
                return (string) $base;
            }
        }
        return null;
    }

    private static function dig(string $locale, string $key): ?string
    {
        $messages = self::messages($locale);
        // Flat-key fast path (app/Lang/*.php ship as flat key=>string arrays).
        if (isset($messages[$key]) && is_string($messages[$key])) {
            return $messages[$key];
        }
        // Dot-notation traversal (supports any nested locale file).
        $parts = explode('.', $key);
        $cur = $messages;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }
        return is_string($cur) ? $cur : null;
    }

    /** @return array<array-key, mixed> */
    private static function messages(string $locale): array
    {
        if (isset(self::$messages[$locale])) {
            return self::$messages[$locale];
        }
        $path = base_path('app/Lang/' . $locale . '.php');
        if (!is_file($path)) {
            return self::$messages[$locale] = [];
        }
        /** @var array<array-key, mixed> $loaded */
        $loaded = require $path;
        return self::$messages[$locale] = is_array($loaded) ? $loaded : [];
    }
}
