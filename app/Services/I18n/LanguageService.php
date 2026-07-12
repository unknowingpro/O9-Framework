<?php
declare(strict_types=1);

namespace App\Services\I18n;

use App\Core\Cache\Cache;
use App\Core\Database;
use App\Core\Lang;

/**
 * LanguageService — DB-driven ACTIVE-language registry, layered on top of
 * App\Core\Lang (which does the actual string lookup, English fallback, and
 * plural handling). This service adds the pieces Lang doesn't own:
 *   - which languages are currently active (the `languages` table),
 *   - the request-scoped "current language" used by bot/webapp-style callers
 *     that don't go through the web session/cookie resolution chain,
 *   - a chat-bot-style language-picker keyboard,
 *   - pick() for choosing a translated field out of a translations array.
 *
 * Add a language without touching code:
 *   1. INSERT INTO languages VALUES ('de', 'German', 'Deutsch', '🇩🇪', 'ltr', 1, 12);
 *   2. Create app/Lang/de.php returning the same keys as app/Lang/en.php.
 *   3. Call LanguageService::getInstance()->flushCache();
 */
class LanguageService
{
    /**
     * Canonical allow-list of supported language codes — the full superset
     * mirrored by app/Lang/*.php and the `languages` table seed. Single
     * source of truth for both Lang::supported()'s DB-less fallback and any
     * language-preference endpoint.
     *
     * @var list<string>
     */
    public const SUPPORTED_CODES = [
        'en', 'ar', 'fa', 'ur', 'hi', 'bn', 'zh', 'ja', 'ko', 'tr', 'ru',
        'de', 'fr', 'es', 'pt', 'it', 'nl', 'pl', 'id', 'vi', 'th',
    ];

    private static ?self $instance = null;
    private string $currentLang = 'en';
    /** @var array<string, array<string, mixed>> */
    private array $activeLangs = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** Set the language for this request. Falls back to 'en' if the code isn't active. */
    public function setLang(string $code): void
    {
        $active = $this->getActiveLangs();
        $this->currentLang = array_key_exists($code, $active) ? $code : 'en';
        // Keep Lang in sync so Lang::get()/__() resolve to the same language.
        Lang::setLocale($this->currentLang);
    }

    public function getLang(): string { return $this->currentLang; }

    /**
     * Translate a key. Delegates the raw string lookup to Lang (fallback-locale
     * then the key itself), then applies sprintf-style positional args.
     */
    public function t(string $key, mixed ...$args): string
    {
        $str = Lang::raw($key, $this->currentLang) ?? $key;
        return $args !== [] ? sprintf($str, ...$args) : $str;
    }

    /** Translate in a specific language (for notifications, digests, etc.). */
    public function tIn(string $lang, string $key, mixed ...$args): string
    {
        $str = Lang::raw($key, $lang) ?? $key;
        return $args !== [] ? sprintf($str, ...$args) : $str;
    }

    /**
     * Translate a key in the current request language (gettext/Lang::get()
     * style, :param substitution). Used by the global __() helper.
     *
     * @param array<string, mixed> $params
     */
    public function get(string $key, array $params = []): string
    {
        return Lang::get($key, $params, $this->currentLang);
    }

    /**
     * Pluralized translation using CLDR plural categories (see Lang::choice()
     * for the stored-string format). Falls back to the fallback locale, then
     * to the key itself when missing.
     *
     * @param array<string, mixed> $params
     */
    public function choice(string $key, int $count, array $params = [], string $lang = ''): string
    {
        return Lang::choice($key, $count, $params, $lang !== '' ? $lang : $this->currentLang);
    }

    /**
     * Alias for choice() — gettext-style __n().
     *
     * @param array<string, mixed> $params
     */
    public function __n(string $key, int $count, string $lang = '', array $params = []): string
    {
        return $this->choice($key, $count, $params, $lang);
    }

    /** Text direction for the current language ('ltr' or 'rtl'). */
    public function dir(): string
    {
        return (string) ($this->getActiveLangs()[$this->currentLang]['dir'] ?? 'ltr');
    }

    /**
     * All active languages from the DB (cached 1h). Falls back to the static
     * SUPPORTED_CODES list (via fallback()) when the table is empty or missing
     * (fresh install, pre-migration).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getActiveLangs(): array
    {
        if ($this->activeLangs !== []) {
            return $this->activeLangs;
        }

        $cached = Cache::get('active_languages');
        if (is_array($cached) && $cached !== []) {
            /** @var array<string, array<string, mixed>> $cached */
            $this->activeLangs = $cached;
            return $cached;
        }

        try {
            $rows = Database::getInstance()
                ->table('languages')
                ->where('is_active', '=', 1)
                ->orderBy('sort_order')
                ->get();
            $langs = [];
            foreach ($rows as $r) {
                $langs[(string) $r['code']] = $r;
            }
            $this->activeLangs = $langs !== [] ? $langs : $this->fallback();
        } catch (\Throwable) {
            $this->activeLangs = $this->fallback();
        }

        Cache::set('active_languages', $this->activeLangs, 3600);
        return $this->activeLangs;
    }

    /**
     * Build a chat-bot-style language selection keyboard: rows of
     * {text, callback_data} pairs, two per row.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function langKeyboard(): array
    {
        $langs = $this->getActiveLangs();
        $rows  = [];
        $row   = [];
        foreach ($langs as $code => $info) {
            $row[] = [
                'text'          => ((string) ($info['flag'] ?? '')) . ' ' . (string) ($info['native'] ?? $info['name'] ?? $code),
                'callback_data' => "set_lang:{$code}",
            ];
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row !== []) { $rows[] = $row; }
        return $rows;
    }

    /**
     * Pick a translated value out of a translations array, e.g.
     * $translations = [['lang' => 'en', 'title' => '...'], ...].
     *
     * @param list<array<string, mixed>> $translations
     */
    public function pick(array $translations, string $field = 'title', string $fallback = ''): string
    {
        $byLang = [];
        foreach ($translations as $t) {
            $byLang[(string) $t['lang']] = (string) ($t[$field] ?? '');
        }
        return $byLang[$this->currentLang]
            ?? $byLang['en']
            ?? ($byLang !== [] ? (string) reset($byLang) : $fallback);
    }

    /** Flush all language caches (call after adding/editing a language). */
    public function flushCache(): void
    {
        Cache::forget('active_languages');
        $this->activeLangs = [];
        Lang::flush();
    }

    // ── Private ──────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function fallback(): array
    {
        // Mirrors app/Lang/*.php and the languages migration seed.
        // dir: 'rtl' for Arabic-script/Hebrew languages; 'ltr' for the rest.
        return [
            'en' => ['code' => 'en', 'name' => 'English',    'native' => 'English',            'flag' => '🇬🇧', 'dir' => 'ltr', 'sort_order' => 1],
            'ar' => ['code' => 'ar', 'name' => 'Arabic',     'native' => 'العربية',            'flag' => '🇸🇦', 'dir' => 'rtl', 'sort_order' => 2],
            'fa' => ['code' => 'fa', 'name' => 'Persian',    'native' => 'فارسی',              'flag' => '🇮🇷', 'dir' => 'rtl', 'sort_order' => 3],
            'ur' => ['code' => 'ur', 'name' => 'Urdu',       'native' => 'اردو',               'flag' => '🇵🇰', 'dir' => 'rtl', 'sort_order' => 4],
            'hi' => ['code' => 'hi', 'name' => 'Hindi',      'native' => 'हिन्दी',             'flag' => '🇮🇳', 'dir' => 'ltr', 'sort_order' => 5],
            'bn' => ['code' => 'bn', 'name' => 'Bengali',    'native' => 'বাংলা',              'flag' => '🇧🇩', 'dir' => 'ltr', 'sort_order' => 6],
            'zh' => ['code' => 'zh', 'name' => 'Chinese',    'native' => '中文',               'flag' => '🇨🇳', 'dir' => 'ltr', 'sort_order' => 7],
            'ja' => ['code' => 'ja', 'name' => 'Japanese',   'native' => '日本語',             'flag' => '🇯🇵', 'dir' => 'ltr', 'sort_order' => 8],
            'ko' => ['code' => 'ko', 'name' => 'Korean',     'native' => '한국어',             'flag' => '🇰🇷', 'dir' => 'ltr', 'sort_order' => 9],
            'tr' => ['code' => 'tr', 'name' => 'Turkish',    'native' => 'Türkçe',             'flag' => '🇹🇷', 'dir' => 'ltr', 'sort_order' => 10],
            'ru' => ['code' => 'ru', 'name' => 'Russian',    'native' => 'Русский',            'flag' => '🇷🇺', 'dir' => 'ltr', 'sort_order' => 11],
            'de' => ['code' => 'de', 'name' => 'German',     'native' => 'Deutsch',            'flag' => '🇩🇪', 'dir' => 'ltr', 'sort_order' => 12],
            'fr' => ['code' => 'fr', 'name' => 'French',     'native' => 'Français',           'flag' => '🇫🇷', 'dir' => 'ltr', 'sort_order' => 13],
            'es' => ['code' => 'es', 'name' => 'Spanish',    'native' => 'Español',            'flag' => '🇪🇸', 'dir' => 'ltr', 'sort_order' => 14],
            'pt' => ['code' => 'pt', 'name' => 'Portuguese', 'native' => 'Português',          'flag' => '🇧🇷', 'dir' => 'ltr', 'sort_order' => 15],
            'it' => ['code' => 'it', 'name' => 'Italian',    'native' => 'Italiano',           'flag' => '🇮🇹', 'dir' => 'ltr', 'sort_order' => 16],
            'nl' => ['code' => 'nl', 'name' => 'Dutch',      'native' => 'Nederlands',         'flag' => '🇳🇱', 'dir' => 'ltr', 'sort_order' => 17],
            'pl' => ['code' => 'pl', 'name' => 'Polish',     'native' => 'Polski',             'flag' => '🇵🇱', 'dir' => 'ltr', 'sort_order' => 18],
            'id' => ['code' => 'id', 'name' => 'Indonesian', 'native' => 'Bahasa Indonesia',   'flag' => '🇮🇩', 'dir' => 'ltr', 'sort_order' => 19],
            'vi' => ['code' => 'vi', 'name' => 'Vietnamese', 'native' => 'Tiếng Việt',         'flag' => '🇻🇳', 'dir' => 'ltr', 'sort_order' => 20],
            'th' => ['code' => 'th', 'name' => 'Thai',       'native' => 'ภาษาไทย',           'flag' => '🇹🇭', 'dir' => 'ltr', 'sort_order' => 21],
        ];
    }
}
