-- ============================================================================
-- Migration 2026-10-11 — admin dashboard entities
-- Run ONCE against an existing `hazeguard` database that already has `users`.
--   mysql -u root hazeguard < database/migrations/2026-10-11-admin-dashboard.sql
-- A fresh install should use database/schema.sql instead (it includes all of this).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------- usergroups ---
CREATE TABLE IF NOT EXISTS usergroups (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(100)    NOT NULL,
  slug        VARCHAR(50)     NOT NULL,
  access      ENUM('site','administrator') NOT NULL DEFAULT 'site',
  description VARCHAR(255)    NULL DEFAULT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usergroups_slug (slug),
  UNIQUE KEY uq_usergroups_title (title),
  KEY idx_usergroups_access (access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usergroups (title, slug, access, description) VALUES
  ('Registered',  'registered', 'site',          'Default group for signed-up members. Front-end access only.'),
  ('Super User',  'superuser',  'administrator', 'Full back-end access to the admin dashboard.'),
  ('Author',      'author',     'site',          'May contribute content from the front end.');

-- -------------------------------------------------------- credential_types ---
CREATE TABLE IF NOT EXISTS credential_types (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100)    NOT NULL,
  type             VARCHAR(30)     NOT NULL,
  brevo_secret_key VARCHAR(255)    NULL DEFAULT NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credential_types_name (name),
  KEY idx_credential_types_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- credential_keys ---
CREATE TABLE IF NOT EXISTS credential_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id       BIGINT UNSIGNED NOT NULL,
  label         VARCHAR(100)    NOT NULL,
  api_key       VARCHAR(255)    NOT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_credential_keys_type (type_id),
  CONSTRAINT fk_credential_keys_type FOREIGN KEY (type_id)
    REFERENCES credential_types (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- users.usergroup_id (FK) ---
-- Existing rows predate the column, so add it nullable, backfill, then tighten.
ALTER TABLE users
  ADD COLUMN usergroup_id BIGINT UNSIGNED NULL AFTER password_hash;

UPDATE users
   SET usergroup_id = (SELECT id FROM usergroups WHERE slug = 'registered')
 WHERE usergroup_id IS NULL;

ALTER TABLE users
  MODIFY usergroup_id BIGINT UNSIGNED NOT NULL,
  ADD KEY idx_users_usergroup (usergroup_id),
  ADD CONSTRAINT fk_users_usergroup FOREIGN KEY (usergroup_id)
    REFERENCES usergroups (id) ON DELETE RESTRICT ON UPDATE CASCADE;
