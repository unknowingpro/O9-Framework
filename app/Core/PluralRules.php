<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CLDR plural-category resolver for the framework's supported locales.
 * Returns one of: zero, one, two, few, many, other.
 *
 * Rules transcribed from the Unicode CLDR plural specification. English and
 * most Latin languages use the simple one/other split; Arabic uses all six
 * categories; Russian/Ukrainian use one/few/many; Polish has its own
 * one/few/many rule; fa/zh/id/tr/ja/ko/th/vi have a single "other" form.
 */
final class PluralRules
{
    public static function category(string $locale, int $n): string
    {
        $n = abs($n);
        $base = explode('_', explode('-', $locale)[0])[0];

        return match ($base) {
            // No plural distinction — one bucket.
            'fa', 'zh', 'id', 'tr', 'ja', 'ko', 'th', 'vi' => 'other',

            // Arabic — full six-way.
            'ar' => self::arabic($n),

            // Russian / Ukrainian — one/few/many.
            'ru', 'uk' => self::eastSlavic($n),

            // Polish — one/few/many (distinct rule from East-Slavic).
            'pl' => self::polish($n),

            // French / Portuguese — 0 and 1 are "one".
            'fr', 'pt' => ($n === 0 || $n === 1) ? 'one' : 'other',

            // Hindi / Bengali — 0 and 1 are "one".
            'hi', 'bn' => ($n === 0 || $n === 1) ? 'one' : 'other',

            // English, Spanish, German, Dutch, Italian, Urdu, and the rest — one/other.
            default => $n === 1 ? 'one' : 'other',
        };
    }

    private static function arabic(int $n): string
    {
        if ($n === 0) return 'zero';
        if ($n === 1) return 'one';
        if ($n === 2) return 'two';
        $mod100 = $n % 100;
        if ($mod100 >= 3 && $mod100 <= 10) return 'few';
        if ($mod100 >= 11) return 'many';
        return 'other';
    }

    private static function eastSlavic(int $n): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'one';
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) return 'few';
        return 'many';
    }

    private static function polish(int $n): string
    {
        if ($n === 1) return 'one';
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) return 'few';
        return 'many';
    }
}
