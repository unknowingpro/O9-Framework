-- Migration 004: entitlement_overrides. Read/written by
-- App\Entitlements\EntitlementService (setOverride/clearOverride/resolve).

CREATE TABLE IF NOT EXISTS `entitlement_overrides` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED  NOT NULL,
  `ent_key`    VARCHAR(64)   NOT NULL,
  `value`      VARCHAR(191)  NOT NULL,
  `reason`     VARCHAR(255)  DEFAULT NULL,
  `expires_at` DATETIME      DEFAULT NULL,
  `created_at` DATETIME      NOT NULL,
  UNIQUE KEY `entitlement_overrides_user_key_uk` (`user_id`, `ent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
