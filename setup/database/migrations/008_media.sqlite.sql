-- Migration 008 (SQLite variant — see 008_media.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS media (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER DEFAULT NULL,
  path       TEXT    NOT NULL,
  driver     TEXT    NOT NULL DEFAULT 'local',
  filename   TEXT    NOT NULL,
  mime       TEXT    DEFAULT NULL,
  size       INTEGER DEFAULT NULL,
  created_at TEXT    NOT NULL,
  updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS media_user_idx ON media (user_id);
