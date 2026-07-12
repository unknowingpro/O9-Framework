-- Migration 003: user_subscriptions. Read/written by
-- App\Subscriptions\SubscriptionService and App\Entitlements\EntitlementService
-- (tierOf/setTier).

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`             INT UNSIGNED NOT NULL,
  `tier`                VARCHAR(32)  NOT NULL DEFAULT 'basic',
  `status`               VARCHAR(16)  NOT NULL DEFAULT 'active', -- active|past_due|canceled|none
  `source`               VARCHAR(16)  DEFAULT NULL,               -- purchase|iap|manual
  `provider`             VARCHAR(32)  DEFAULT NULL,
  `provider_sub_id`      VARCHAR(191) DEFAULT NULL,
  `billing_interval`     VARCHAR(8)   DEFAULT NULL,               -- month|year
  `price_cents`          INT UNSIGNED DEFAULT NULL,
  `current_period_end`   DATETIME     DEFAULT NULL,
  `scheduled_tier`        VARCHAR(32)  DEFAULT NULL,
  `scheduled_interval`    VARCHAR(8)   DEFAULT NULL,
  `scheduled_at`          DATETIME     DEFAULT NULL,
  `canceled_at`           DATETIME     DEFAULT NULL,
  `started_at`            DATETIME     NOT NULL,
  `updated_at`            DATETIME     NOT NULL,
  UNIQUE KEY `user_subscriptions_user_uk` (`user_id`),
  KEY `user_subscriptions_provider_sub_idx` (`provider_sub_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
