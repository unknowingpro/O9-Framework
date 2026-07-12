<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Request;

/**
 * Helpers for incremental (delta) sync. A client passes ?updated_since=<cursor>
 * (the `synced_at` it got last time) and receives only rows changed since,
 * plus tombstones for rows deleted since — so it can keep a local cache
 * fresh without refetching everything.
 */
final class Delta
{
    /**
     * Normalise the ?updated_since cursor to the stored 'Y-m-d H:i:s' UTC
     * form. Accepts that format, ISO-8601 ("...T...Z", optional millis), or
     * epoch seconds. Returns null when absent/blank (caller falls back to a
     * full list).
     */
    public static function since(Request $request): ?string
    {
        return self::normalize((string) $request->query('updated_since', ''));
    }

    /** Pure cursor normaliser (testable without a Request). '' -> null. */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return gmdate('Y-m-d H:i:s', (int) $raw);
        }
        $raw = str_replace('T', ' ', $raw);
        $raw = preg_replace('/(?:\.\d+)?Z?$/', '', $raw) ?? $raw; // strip trailing millis / Z
        return substr(trim($raw), 0, 19);
    }

    /**
     * Generic per-user delta over a table: rows whose $tsCol changed after
     * the cursor, plus tombstone ids for rows soft-deleted after it (when
     * the table has deleted_at). $table/$ownerCol/$tsCol are fixed code
     * literals — never request input — so they're safe to interpolate.
     *
     * @return array{changed: list<array<string, mixed>>, deleted: list<int>}
     */
    public static function rows(
        Database $db,
        string $table,
        string $ownerCol,
        int $userId,
        string $since,
        string $tsCol = 'updated_at',
        bool $softDelete = true
    ): array {
        $sql = "SELECT * FROM {$table} WHERE {$ownerCol} = ? "
            . ($softDelete ? 'AND deleted_at IS NULL ' : '')
            . "AND {$tsCol} > ? ORDER BY {$tsCol} ASC";
        $changed = array_values($db->raw($sql, [$userId, $since])->fetchAll());

        $deleted = [];
        if ($softDelete) {
            $rows = $db->raw(
                "SELECT id FROM {$table} WHERE {$ownerCol} = ? AND deleted_at IS NOT NULL AND deleted_at > ?",
                [$userId, $since]
            )->fetchAll();
            $deleted = array_values(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        }
        return ['changed' => $changed, 'deleted' => $deleted];
    }
}
