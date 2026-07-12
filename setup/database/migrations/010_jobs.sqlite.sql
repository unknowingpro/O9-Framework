-- Migration 010 (SQLite variant — see 010_jobs.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS jobs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  queue        TEXT    NOT NULL DEFAULT 'default',
  job          TEXT    NOT NULL,
  payload      TEXT    NOT NULL,
  attempts     INTEGER NOT NULL DEFAULT 0,
  available_at INTEGER NOT NULL,
  reserved_at  INTEGER DEFAULT NULL,
  created_at   INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_queue_reserved_available_idx ON jobs (queue, reserved_at, available_at);
