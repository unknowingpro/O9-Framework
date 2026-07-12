-- Migration 012: refresh_tokens. Core\Security\RefreshTokenService's
-- backing table — long-lived refresh tokens paired with short-lived JWT
-- access tokens (see Jwt.php's typ:refresh handling). Rotated on every
-- use; reusing an already-used token revokes its whole family (signals
-- the token was stolen and replayed after the legitimate client already
-- rotated past it).

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64)     NOT NULL,
  `family_id`   CHAR(32)     NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  `used_at`     DATETIME     DEFAULT NULL,
  `revoked_at`  DATETIME     DEFAULT NULL,
  UNIQUE KEY `refresh_tokens_hash_uk` (`token_hash`),
  KEY `refresh_tokens_family_idx` (`family_id`),
  KEY `refresh_tokens_user_idx` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
