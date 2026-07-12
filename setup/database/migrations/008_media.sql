-- Migration 008: media. The sample MediaModel's table -- a minimal record
-- of a file StorageManager has stored, keyed by its driver-relative path.

CREATE TABLE IF NOT EXISTS `media` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `path`        VARCHAR(500) NOT NULL, -- StorageManager-relative path
  `driver`      VARCHAR(32)  NOT NULL DEFAULT 'local',
  `filename`    VARCHAR(255) NOT NULL,
  `mime`        VARCHAR(127) DEFAULT NULL,
  `size`        INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  KEY `media_user_idx` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
