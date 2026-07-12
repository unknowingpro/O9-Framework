-- Migration 009: settings. Read/written by App\Services\SettingsService --
-- a generic DB-backed key/value store for runtime-editable configuration
-- (the admin-switchable counterpart to the static config/*.php files).

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name`   VARCHAR(191) NOT NULL PRIMARY KEY,
  `value`      TEXT         DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
