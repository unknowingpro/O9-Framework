-- Migration 006 (SQLite variant — see 006_content_translations.mysql.sql
-- for column documentation).

CREATE TABLE IF NOT EXISTS content_translations (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT    NOT NULL,
  entity_id   INTEGER NOT NULL,
  field       TEXT    NOT NULL,
  locale      TEXT    NOT NULL,
  value       TEXT    NOT NULL,
  created_at  TEXT    NOT NULL,
  updated_at  TEXT    NOT NULL,
  UNIQUE (entity_type, entity_id, field, locale)
);
