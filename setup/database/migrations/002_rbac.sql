-- Migration 002: RBAC (roles, permissions, role_permissions, user_roles).
-- Read/written by App\Identity\Rbac. No-op (empty roles/permissions) for any
-- app that hasn't run this migration yet.

CREATE TABLE IF NOT EXISTS `roles` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(64) NOT NULL,
  `created_at` DATETIME    NOT NULL,
  UNIQUE KEY `roles_name_uk` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(96) NOT NULL,
  `created_at` DATETIME    NOT NULL,
  UNIQUE KEY `permissions_name_uk` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id`    INT UNSIGNED NOT NULL,
  `role_id`    INT UNSIGNED NOT NULL,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  `granted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
