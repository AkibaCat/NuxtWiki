-- =============================================================
-- NuxtWiki · SQLite 数据库结构（本地开发 / 测试用）
-- 生产环境请使用 MySQL（schema.mysql.sql）
-- =============================================================
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  username   TEXT NOT NULL UNIQUE,
  email      TEXT NOT NULL DEFAULT '',
  password   TEXT NOT NULL,
  nickname   TEXT NOT NULL DEFAULT '',
  bio        TEXT NOT NULL DEFAULT '',
  avatar     TEXT NOT NULL DEFAULT '',
  socials    TEXT NOT NULL DEFAULT '',
  is_admin   INTEGER NOT NULL DEFAULT 0,
  level      INTEGER NOT NULL DEFAULT 3,
  status     TEXT NOT NULL DEFAULT 'active',
  reason     TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tag         TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  body        TEXT NOT NULL,
  style       TEXT NOT NULL DEFAULT '',
  comment     TEXT NOT NULL DEFAULT '',
  user_id     INTEGER NULL,
  created_by  INTEGER NULL,
  created_at  TEXT NOT NULL,
  updated_at  TEXT NOT NULL,
  revision    INTEGER NOT NULL DEFAULT 0,
  hits        INTEGER NOT NULL DEFAULT 0,
  acl_read          TEXT NOT NULL DEFAULT '0',
  acl_edit          TEXT NOT NULL DEFAULT '3',
  acl_history       TEXT NOT NULL DEFAULT '3',
  acl_diff          TEXT NOT NULL DEFAULT '2',
  acl_backlinks     TEXT NOT NULL DEFAULT '3',
  acl_acl           TEXT NOT NULL DEFAULT '1',
  acl_contributors  TEXT NOT NULL DEFAULT '0'
);
CREATE INDEX IF NOT EXISTS idx_pages_updated ON pages (updated_at);

CREATE TABLE IF NOT EXISTS revisions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  page_id    INTEGER NOT NULL,
  tag        TEXT NOT NULL,
  title      TEXT NOT NULL,
  body       TEXT NOT NULL,
  style      TEXT NOT NULL DEFAULT '',
  comment    TEXT NOT NULL DEFAULT '',
  user_id    INTEGER NULL,
  revision   INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rev_page ON revisions (page_id, revision);

CREATE TABLE IF NOT EXISTS watchers (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  page_id    INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (page_id, user_id)
);

CREATE TABLE IF NOT EXISTS regcodes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  code       TEXT NOT NULL UNIQUE,
  user_id    INTEGER NULL,
  created_at TEXT NOT NULL,
  used_at    TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_regcodes_user ON regcodes (user_id);

CREATE TABLE IF NOT EXISTS settings (
  skey   TEXT NOT NULL PRIMARY KEY,
  svalue TEXT NULL
);
