-- Migration 010: jobs. Core\Queue's backing table (push/reserve/delete) --
-- the DB-backed background-job queue every `console queue:work` worker
-- polls. Framework infrastructure, not a sample table.

CREATE TABLE IF NOT EXISTS `jobs` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `queue`        VARCHAR(64)  NOT NULL DEFAULT 'default',
  `job`          VARCHAR(191) NOT NULL,
  `payload`      TEXT         NOT NULL,
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` INT UNSIGNED NOT NULL,
  `reserved_at`  INT UNSIGNED DEFAULT NULL,
  `created_at`   INT UNSIGNED NOT NULL,
  KEY `jobs_queue_reserved_available_idx` (`queue`, `reserved_at`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
