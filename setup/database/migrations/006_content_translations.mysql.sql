-- Migration 006: content_translations. Read/written by App\I18n\Translatable.

CREATE TABLE IF NOT EXISTS `content_translations` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entity_type` VARCHAR(64)  NOT NULL,
  `entity_id`   INT UNSIGNED NOT NULL,
  `field`       VARCHAR(64)  NOT NULL,
  `locale`      VARCHAR(10)  NOT NULL,
  `value`       TEXT         NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  UNIQUE KEY `content_translations_uk` (`entity_type`, `entity_id`, `field`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
