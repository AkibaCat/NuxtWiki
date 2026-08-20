-- =============================================================
-- NuxtWiki · MySQL 数据库结构
-- 字符集: utf8mb4 / utf8mb4_unicode_ci
-- 可用安装向导 (install.php) 自动创建，也可手动导入本文件
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  username   VARCHAR(64)      NOT NULL,
  email      VARCHAR(255)     NOT NULL DEFAULT '',
  password   VARCHAR(255)     NOT NULL,
  nickname   VARCHAR(64)      NOT NULL DEFAULT '',
  bio        TEXT             NULL,
  avatar     VARCHAR(255)     NOT NULL DEFAULT '',
  socials    TEXT             NULL,
  is_admin   TINYINT(1)       NOT NULL DEFAULT 0,
  level      TINYINT(1)       NOT NULL DEFAULT 3,
  status     VARCHAR(16)      NOT NULL DEFAULT 'active',
  reason     TEXT             NULL,
  created_at DATETIME         NOT NULL,
  updated_at DATETIME         NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tag         VARCHAR(191) NOT NULL,
  title       VARCHAR(255) NOT NULL,
  body        LONGTEXT     NOT NULL,
  style       LONGTEXT     NULL,
  comment     VARCHAR(255) NOT NULL DEFAULT '',
  user_id     INT UNSIGNED NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  revision    INT UNSIGNED NOT NULL DEFAULT 0,
  hits        INT UNSIGNED NOT NULL DEFAULT 0,
  acl_read          VARCHAR(16) NOT NULL DEFAULT '0',
  acl_edit          VARCHAR(16) NOT NULL DEFAULT '3',
  acl_history       VARCHAR(16) NOT NULL DEFAULT '3',
  acl_diff          VARCHAR(16) NOT NULL DEFAULT '2',
  acl_backlinks     VARCHAR(16) NOT NULL DEFAULT '3',
  acl_acl           VARCHAR(16) NOT NULL DEFAULT '1',
  acl_contributors  VARCHAR(16) NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_tag (tag),
  KEY idx_pages_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revisions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id    INT UNSIGNED NOT NULL,
  tag        VARCHAR(255) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  body       LONGTEXT     NOT NULL,
  style      LONGTEXT     NULL,
  comment    VARCHAR(255) NOT NULL DEFAULT '',
  user_id    INT UNSIGNED NULL,
  revision   INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rev_page (page_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_watch (page_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regcodes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(32)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_regcodes_code (code),
  KEY idx_regcodes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64) NOT NULL,
  svalue TEXT        NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
