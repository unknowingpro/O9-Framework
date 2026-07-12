<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * DB-backed runtime settings: the admin-switchable counterpart to the
 * static config/*.php files. A value written here overrides nothing by
 * itself — call sites that want it admin-editable read through
 * SettingsService::get($key, config('some.default')) explicitly.
 *
 * Values are stored as JSON so any scalar/array round-trips; get() decodes
 * transparently. Reads are cached per-process; write through set()/forget()
 * to keep the cache correct.
 */
final class SettingsService
{
    /** @var array<string, mixed> */
    private static array $cache = [];
    private static bool $loaded = false;

    /** @internal test reset */
    public static function reset(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureLoaded();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function set(string $key, mixed $value, ?int $byUserId = null): void
    {
        self::ensureLoaded();
        $db = Database::getInstance();
        $now = gmdate('Y-m-d H:i:s');
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updated = $db->raw(
            'UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE key_name = ?',
            [$encoded, $byUserId, $now, $key]
        )->rowCount();
        if ($updated === 0) {
            try {
                $db->raw(
                    'INSERT INTO settings (key_name, value, updated_by, updated_at) VALUES (?, ?, ?, ?)',
                    [$key, $encoded, $byUserId, $now]
                );
            } catch (\PDOException) {
                $db->raw(
                    'UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE key_name = ?',
                    [$encoded, $byUserId, $now, $key]
                );
            }
        }
        self::$cache[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::ensureLoaded();
        Database::getInstance()->raw('DELETE FROM settings WHERE key_name = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /** @return array<string, mixed> every setting, key => decoded value. */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$cache;
    }

    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        try {
            $rows = Database::getInstance()->raw('SELECT key_name, value FROM settings')->fetchAll();
        } catch (\Throwable) {
            return; // table not migrated yet — behave as if empty
        }
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            self::$cache[(string) $row['key_name']] = $decoded;
        }
    }
}
