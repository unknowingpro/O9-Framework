<?php
declare(strict_types=1);

/**
 * Locale registry — static per-locale metadata for Lang's meta()/direction()/
 * intlId()/calendar()/fontGroup()/currency(). The ACTIVE-language LIST (which
 * of these are currently enabled) is DB-driven via LanguageService; this file
 * only supplies metadata for string resolution and is not itself the source
 * of truth for "is this language on".
 *
 * Each entry:
 *   native    Native-language display name
 *   en        English name (admin / debugging)
 *   dir       'ltr' | 'rtl'
 *   calendar  'gregorian' | 'persian' (Jalali) | 'islamic' (Hijri)
 *   intl      ICU locale id (drives digit shaping where used)
 *   font      Script group → Noto fallback group
 *   currency  Default ISO-4217 code
 */

return [
    'en' => ['native' => 'English',            'en' => 'English',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'en',                     'font' => 'latin',      'currency' => 'USD'],
    'ar' => ['native' => 'العربية',            'en' => 'Arabic',     'dir' => 'rtl', 'calendar' => 'islamic',   'intl' => 'ar@calendar=islamic',    'font' => 'arabic',     'currency' => 'SAR'],
    'fa' => ['native' => 'فارسی',              'en' => 'Persian',    'dir' => 'rtl', 'calendar' => 'persian',   'intl' => 'fa_IR@calendar=persian', 'font' => 'arabic',     'currency' => 'IRR'],
    'ur' => ['native' => 'اردو',               'en' => 'Urdu',       'dir' => 'rtl', 'calendar' => 'gregorian', 'intl' => 'ur',                     'font' => 'arabic',     'currency' => 'PKR'],
    'hi' => ['native' => 'हिन्दी',              'en' => 'Hindi',      'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'hi',                     'font' => 'devanagari', 'currency' => 'INR'],
    'bn' => ['native' => 'বাংলা',              'en' => 'Bengali',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'bn',                     'font' => 'bengali',    'currency' => 'BDT'],
    'zh' => ['native' => '中文',               'en' => 'Chinese',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'zh',                     'font' => 'cjk',        'currency' => 'CNY'],
    'ja' => ['native' => '日本語',             'en' => 'Japanese',   'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'ja',                     'font' => 'cjk',        'currency' => 'JPY'],
    'ko' => ['native' => '한국어',             'en' => 'Korean',     'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'ko',                     'font' => 'cjk',        'currency' => 'KRW'],
    'tr' => ['native' => 'Türkçe',             'en' => 'Turkish',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'tr',                     'font' => 'latin',      'currency' => 'TRY'],
    'ru' => ['native' => 'Русский',            'en' => 'Russian',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'ru',                     'font' => 'latin',      'currency' => 'RUB'],
    'de' => ['native' => 'Deutsch',            'en' => 'German',     'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'de',                     'font' => 'latin',      'currency' => 'EUR'],
    'fr' => ['native' => 'Français',           'en' => 'French',     'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'fr',                     'font' => 'latin',      'currency' => 'EUR'],
    'es' => ['native' => 'Español',            'en' => 'Spanish',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'es',                     'font' => 'latin',      'currency' => 'EUR'],
    'pt' => ['native' => 'Português',          'en' => 'Portuguese', 'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'pt',                     'font' => 'latin',      'currency' => 'BRL'],
    'it' => ['native' => 'Italiano',           'en' => 'Italian',    'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'it',                     'font' => 'latin',      'currency' => 'EUR'],
    'nl' => ['native' => 'Nederlands',         'en' => 'Dutch',      'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'nl',                     'font' => 'latin',      'currency' => 'EUR'],
    'pl' => ['native' => 'Polski',             'en' => 'Polish',     'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'pl',                     'font' => 'latin',      'currency' => 'PLN'],
    'id' => ['native' => 'Bahasa Indonesia',   'en' => 'Indonesian', 'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'id',                     'font' => 'latin',      'currency' => 'IDR'],
    'vi' => ['native' => 'Tiếng Việt',         'en' => 'Vietnamese', 'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'vi',                     'font' => 'latin',      'currency' => 'VND'],
    'th' => ['native' => 'ภาษาไทย',            'en' => 'Thai',       'dir' => 'ltr', 'calendar' => 'gregorian', 'intl' => 'th',                     'font' => 'thai',       'currency' => 'THB'],
];
