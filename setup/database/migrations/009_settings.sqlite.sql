-- Migration 009 (SQLite variant — see 009_settings.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS settings (
  key_name   TEXT PRIMARY KEY,
  value      TEXT DEFAULT NULL,
  updated_by INTEGER DEFAULT NULL,
  updated_at TEXT NOT NULL
);
