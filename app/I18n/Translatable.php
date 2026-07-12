<?php
declare(strict_types=1);

namespace App\I18n;

use App\Core\Database;
use App\Core\Lang;

/**
 * Per-locale translations for dynamic DB content (product/listing names, and
 * any other entity that opts in). The base (fallback-locale) value lives on
 * the source row; this stores one row per (entity_type, entity_id, field,
 * locale) in `content_translations`. Reads resolve the active locale and
 * fall back to the base value when no translation exists — the same
 * fallback shape as Core\Lang for UI keys, so content i18n scales to every
 * supported locale instead of a single hardcoded extra column.
 *
 * Static API (ergonomic at call sites, like __()); all DB access goes
 * through the shared Database singleton.
 */
final class Translatable
{
    /** Locales for which the base column already holds the value — never looked up. */
    private static function isBaseLocale(string $locale): bool
    {
        return $locale === (string) config('app.fallback_locale', 'en');
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Resolve one field's value for the active (or given) locale, falling
     * back to the base value when there's no translation.
     */
    public static function text(string $type, int $id, string $base, string $field = 'name', ?string $locale = null): string
    {
        $locale = $locale ?? Lang::locale();
        if ($id <= 0 || self::isBaseLocale($locale)) {
            return $base;
        }
        $row = self::db()->raw(
            'SELECT value FROM content_translations WHERE entity_type = ? AND entity_id = ? AND field = ? AND locale = ? LIMIT 1',
            [$type, $id, $field, $locale]
        )->fetchColumn();
        $val = is_string($row) ? trim($row) : '';
        return $val !== '' ? $val : $base;
    }

    /**
     * Batch-resolve a field for many ids in one query — for list rendering,
     * to avoid an N+1. Returns [id => translated value] only for ids that
     * HAVE a translation in the active locale; callers merge over their
     * base values.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function map(string $type, array $ids, string $field = 'name', ?string $locale = null): array
    {
        $locale = $locale ?? Lang::locale();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || self::isBaseLocale($locale)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::db()->raw(
            "SELECT entity_id, value FROM content_translations
             WHERE entity_type = ? AND field = ? AND locale = ? AND entity_id IN ($ph)",
            array_merge([$type, $field, $locale], $ids)
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $v = trim((string) $r['value']);
            if ($v !== '') {
                $out[(int) $r['entity_id']] = $v;
            }
        }
        return $out;
    }

    /**
     * All translations of one field for an entity, as [locale => value] —
     * for a per-locale editor UI.
     *
     * @return array<string, string>
     */
    public static function forField(string $type, int $id, string $field = 'name'): array
    {
        if ($id <= 0) {
            return [];
        }
        $rows = self::db()->raw(
            'SELECT locale, value FROM content_translations WHERE entity_type = ? AND entity_id = ? AND field = ? ORDER BY locale',
            [$type, $id, $field]
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['locale']] = (string) $r['value'];
        }
        return $out;
    }

    /**
     * Upsert one translation. An empty value removes it (so clearing a field
     * in the editor deletes the row rather than storing a blank). Never
     * stores a translation for the base locale — that value belongs on the
     * source row.
     */
    public static function put(string $type, int $id, string $field, string $locale, string $value): void
    {
        $value = trim($value);
        if ($id <= 0 || $locale === '' || self::isBaseLocale($locale) || !in_array($locale, Lang::supported(), true)) {
            return;
        }
        if ($value === '') {
            self::remove($type, $id, $field, $locale);
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $db = self::db();
        // Driver-portable upsert: update first, insert when nothing matched.
        $updated = $db->raw(
            'UPDATE content_translations SET value = ?, updated_at = ? WHERE entity_type = ? AND entity_id = ? AND field = ? AND locale = ?',
            [$value, $now, $type, $id, $field, $locale]
        )->rowCount();
        if ($updated === 0) {
            // 0 changed rows is ambiguous on MySQL: row absent, OR present but the
            // UPDATE was a no-op (same-second identical re-save). Try INSERT; on
            // the composite UNIQUE collision, re-apply via UPDATE rather than
            // fataling with a duplicate-key error.
            try {
                $db->raw(
                    'INSERT INTO content_translations (entity_type, entity_id, field, locale, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$type, $id, $field, $locale, $value, $now, $now]
                );
            } catch (\PDOException) {
                $db->raw(
                    'UPDATE content_translations SET value = ?, updated_at = ? WHERE entity_type = ? AND entity_id = ? AND field = ? AND locale = ?',
                    [$value, $now, $type, $id, $field, $locale]
                );
            }
        }
    }

    /**
     * Replace ALL translations of a field from a [locale => value] map (a
     * form editor submits one input per locale). Locales absent from the map
     * keep their current value; an empty value clears that locale.
     *
     * @param array<string, string> $byLocale
     */
    public static function putMany(string $type, int $id, string $field, array $byLocale): void
    {
        foreach ($byLocale as $locale => $value) {
            self::put($type, $id, $field, (string) $locale, (string) $value);
        }
    }

    public static function remove(string $type, int $id, string $field, string $locale): void
    {
        self::db()->raw(
            'DELETE FROM content_translations WHERE entity_type = ? AND entity_id = ? AND field = ? AND locale = ?',
            [$type, $id, $field, $locale]
        );
    }

    /** Drop every translation for an entity (e.g. when the row is deleted). */
    public static function purge(string $type, int $id): void
    {
        self::db()->raw(
            'DELETE FROM content_translations WHERE entity_type = ? AND entity_id = ?',
            [$type, $id]
        );
    }
}
