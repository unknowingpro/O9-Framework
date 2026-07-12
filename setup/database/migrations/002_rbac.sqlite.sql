-- Migration 002 (SQLite variant — see 002_rbac.mysql.sql for column
-- documentation).

CREATE TABLE IF NOT EXISTS roles (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS permissions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INTEGER NOT NULL,
  permission_id INTEGER NOT NULL,
  PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE IF NOT EXISTS user_roles (
  user_id    INTEGER NOT NULL,
  role_id    INTEGER NOT NULL,
  granted_by INTEGER DEFAULT NULL,
  granted_at TEXT    NOT NULL,
  PRIMARY KEY (user_id, role_id)
);
