-- Migration 005 (SQLite variant — see 005_payments.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS payment_intents (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  idempotency_key  TEXT    NOT NULL,
  provider         TEXT    NOT NULL,
  provider_ref     TEXT    DEFAULT NULL,
  direction        TEXT    NOT NULL,
  amount_cents     INTEGER NOT NULL,
  currency         TEXT    NOT NULL DEFAULT 'USD',
  status           TEXT    NOT NULL DEFAULT 'pending',
  user_id          INTEGER NOT NULL,
  wallet_tx_id     INTEGER DEFAULT NULL,
  ref_type         TEXT    DEFAULT NULL,
  ref_id           INTEGER DEFAULT NULL,
  meta             TEXT    DEFAULT NULL,
  created_at       TEXT    NOT NULL,
  updated_at       TEXT    NOT NULL,
  UNIQUE (idempotency_key)
);
CREATE INDEX IF NOT EXISTS payment_intents_provider_ref_idx ON payment_intents (provider_ref);
CREATE INDEX IF NOT EXISTS payment_intents_status_idx ON payment_intents (direction, status, updated_at);

CREATE TABLE IF NOT EXISTS store_webhook_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  provider    TEXT NOT NULL,
  event_uid   TEXT NOT NULL,
  received_at TEXT NOT NULL,
  UNIQUE (provider, event_uid)
);
