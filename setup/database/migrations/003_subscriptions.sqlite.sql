-- Migration 003 (SQLite variant — see 003_subscriptions.mysql.sql for
-- column documentation).

CREATE TABLE IF NOT EXISTS user_subscriptions (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id             INTEGER NOT NULL,
  tier                TEXT    NOT NULL DEFAULT 'basic',
  status              TEXT    NOT NULL DEFAULT 'active',
  source              TEXT    DEFAULT NULL,
  provider            TEXT    DEFAULT NULL,
  provider_sub_id     TEXT    DEFAULT NULL,
  billing_interval    TEXT    DEFAULT NULL,
  price_cents         INTEGER DEFAULT NULL,
  current_period_end  TEXT    DEFAULT NULL,
  scheduled_tier      TEXT    DEFAULT NULL,
  scheduled_interval  TEXT    DEFAULT NULL,
  scheduled_at        TEXT    DEFAULT NULL,
  canceled_at         TEXT    DEFAULT NULL,
  started_at          TEXT    NOT NULL,
  updated_at          TEXT    NOT NULL,
  UNIQUE (user_id)
);
CREATE INDEX IF NOT EXISTS user_subscriptions_provider_sub_idx ON user_subscriptions (provider_sub_id);
