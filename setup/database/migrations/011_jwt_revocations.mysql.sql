-- Migration 011: jwt_revocations. Core\Security\Jwt's targeted-revocation
-- table (logout / password-change / compromise flip a single token's jti
-- off without rotating the signing secret for everyone). Framework
-- infrastructure, not a sample table. Jwt::decode() checks
-- Database::tableExists('jwt_revocations') and simply skips the check
-- when this migration hasn't been applied, so revocation is opt-in —
-- apply this migration to make logout actually revoke issued tokens.

CREATE TABLE IF NOT EXISTS `jwt_revocations` (
  `jti`         VARCHAR(32)  NOT NULL PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `revoked_at`  DATETIME     NOT NULL,
  `exp`         DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
