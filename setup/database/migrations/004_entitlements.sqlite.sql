-- Migration 004 (SQLite variant — see 004_entitlements.mysql.sql for
-- column documentation).

CREATE TABLE IF NOT EXISTS entitlement_overrides (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  ent_key    TEXT    NOT NULL,
  value      TEXT    NOT NULL,
  reason     TEXT    DEFAULT NULL,
  expires_at TEXT    DEFAULT NULL,
  created_at TEXT    NOT NULL,
  UNIQUE (user_id, ent_key)
);
