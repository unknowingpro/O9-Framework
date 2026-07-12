-- Migration 007: users. The sample UserModel's table, and the row shape
-- App\Core\Auth (roles, locale, force_logout_at) and App\Core\Lang (locale)
-- expect when a resolver is wired to it.

CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`            VARCHAR(191) NOT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `roles`            VARCHAR(191) NOT NULL DEFAULT '', -- comma-separated, e.g. "admin,member"
  `locale`           VARCHAR(10)  DEFAULT NULL,
  `force_logout_at`  DATETIME     DEFAULT NULL,
  `last_seen_at`     DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  UNIQUE KEY `users_email_uk` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
