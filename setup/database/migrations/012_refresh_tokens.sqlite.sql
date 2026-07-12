-- Migration 012 (SQLite variant — see 012_refresh_tokens.mysql.sql for
-- column documentation).

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  token_hash TEXT    NOT NULL,
  family_id  TEXT    NOT NULL,
  created_at TEXT    NOT NULL,
  used_at    TEXT    DEFAULT NULL,
  revoked_at TEXT    DEFAULT NULL,
  UNIQUE (token_hash)
);
CREATE INDEX IF NOT EXISTS refresh_tokens_family_idx ON refresh_tokens (family_id);
CREATE INDEX IF NOT EXISTS refresh_tokens_user_idx ON refresh_tokens (user_id);
