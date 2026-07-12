-- Migration 001 (SQLite variant — see 001_languages.mysql.sql for column
-- documentation). Same schema and seed data, translated to SQLite DDL:
-- no ENUM/AUTO_INCREMENT/ENGINE clause, INSERT OR IGNORE instead of
-- INSERT IGNORE.

CREATE TABLE IF NOT EXISTS languages (
  code       TEXT    NOT NULL PRIMARY KEY,
  name       TEXT    NOT NULL,
  native     TEXT    NOT NULL,
  flag       TEXT    DEFAULT NULL,
  dir        TEXT    NOT NULL DEFAULT 'ltr',
  is_active  INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

INSERT OR IGNORE INTO languages (code, name, native, flag, dir, is_active, sort_order) VALUES
('en', 'English',    'English',          '🇬🇧', 'ltr', 1,  1),
('ar', 'Arabic',     'العربية',          '🇸🇦', 'rtl', 1,  2),
('fa', 'Persian',    'فارسی',            '🇮🇷', 'rtl', 1,  3),
('ur', 'Urdu',       'اردو',             '🇵🇰', 'rtl', 1,  4),
('hi', 'Hindi',      'हिन्दी',           '🇮🇳', 'ltr', 1,  5),
('bn', 'Bengali',    'বাংলা',            '🇧🇩', 'ltr', 1,  6),
('zh', 'Chinese',    '中文',             '🇨🇳', 'ltr', 1,  7),
('ja', 'Japanese',   '日本語',           '🇯🇵', 'ltr', 1,  8),
('ko', 'Korean',     '한국어',           '🇰🇷', 'ltr', 1,  9),
('tr', 'Turkish',    'Türkçe',           '🇹🇷', 'ltr', 1, 10),
('ru', 'Russian',    'Русский',          '🇷🇺', 'ltr', 1, 11),
('de', 'German',     'Deutsch',          '🇩🇪', 'ltr', 1, 12),
('fr', 'French',     'Français',         '🇫🇷', 'ltr', 1, 13),
('es', 'Spanish',    'Español',          '🇪🇸', 'ltr', 1, 14),
('pt', 'Portuguese', 'Português',        '🇧🇷', 'ltr', 1, 15),
('it', 'Italian',    'Italiano',         '🇮🇹', 'ltr', 1, 16),
('nl', 'Dutch',      'Nederlands',       '🇳🇱', 'ltr', 1, 17),
('pl', 'Polish',     'Polski',           '🇵🇱', 'ltr', 1, 18),
('id', 'Indonesian', 'Bahasa Indonesia', '🇮🇩', 'ltr', 1, 19),
('vi', 'Vietnamese', 'Tiếng Việt',       '🇻🇳', 'ltr', 1, 20),
('th', 'Thai',       'ภาษาไทย',         '🇹🇭', 'ltr', 1, 21);
