-- Migration 007 (SQLite variant — see 007_users.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS users (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  email           TEXT NOT NULL,
  password_hash   TEXT NOT NULL,
  roles           TEXT NOT NULL DEFAULT '',
  locale          TEXT DEFAULT NULL,
  force_logout_at TEXT DEFAULT NULL,
  last_seen_at    TEXT DEFAULT NULL,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  UNIQUE (email)
);
