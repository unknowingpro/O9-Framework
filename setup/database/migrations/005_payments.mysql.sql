-- Migration 005: payment_intents + store_webhook_events. Read/written by
-- App\Payments\PaymentService and App\Subscriptions\SubscriptionService
-- (webhook idempotency claim).

CREATE TABLE IF NOT EXISTS `payment_intents` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `idempotency_key`  VARCHAR(191) NOT NULL,
  `provider`         VARCHAR(32)  NOT NULL,
  `provider_ref`     VARCHAR(191) DEFAULT NULL,
  `direction`        VARCHAR(4)   NOT NULL,               -- in|out
  `amount_cents`     INT UNSIGNED NOT NULL,
  `currency`         VARCHAR(8)   NOT NULL DEFAULT 'USD',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending|settling|succeeded|failed|refunded
  `user_id`          INT UNSIGNED NOT NULL,
  `wallet_tx_id`     INT UNSIGNED DEFAULT NULL,
  `ref_type`         VARCHAR(32)  DEFAULT NULL,
  `ref_id`           INT UNSIGNED DEFAULT NULL,
  `meta`             TEXT         DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  UNIQUE KEY `payment_intents_idempotency_uk` (`idempotency_key`),
  KEY `payment_intents_provider_ref_idx` (`provider_ref`),
  KEY `payment_intents_status_idx` (`direction`, `status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_webhook_events` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `provider`     VARCHAR(32)  NOT NULL,
  `event_uid`    VARCHAR(191) NOT NULL,
  `received_at`  DATETIME     NOT NULL,
  UNIQUE KEY `store_webhook_events_uk` (`provider`, `event_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
