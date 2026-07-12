-- Migration 011 (SQLite variant — see 011_jwt_revocations.mysql.sql for
-- column documentation).

CREATE TABLE IF NOT EXISTS jwt_revocations (
  jti        TEXT NOT NULL PRIMARY KEY,
  user_id    INTEGER DEFAULT NULL,
  revoked_at TEXT NOT NULL,
  exp        TEXT DEFAULT NULL
);
