-- ============================================================================
-- HazeGuard AI — database schema
-- Target: MySQL 8 / MariaDB 10.4+ (InnoDB, utf8mb4)
-- Import:  mysql -u <user> -p hazeguard < database/schema.sql
-- All timestamps are stored in UTC (DATETIME, no implicit zone conversion).
--
-- Foreign keys are enforced by the database, not just the application:
--   users.usergroup_id                     -> usergroups.id                 (RESTRICT)
--   email_otps.user_id                     -> users.id                      (CASCADE)
--   email_verification_tokens.*            -> users.id                      (CASCADE)
-- RESTRICT means an operator cannot delete a parent row while children still
-- reference it; the admin panels surface that as a friendly error.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------- usergroups ---
-- Access level decides where a member may sign in:
--   site          = front-end / user interface only
--   administrator = back-end admin dashboard (Joomla-style "Super User")
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

-- Default groups. IGNORE keeps re-imports idempotent.
INSERT IGNORE INTO usergroups (title, slug, access, description) VALUES
  ('Registered',  'registered', 'site',          'Default group for signed-up members. Front-end access only.'),
  ('Super User',  'superuser',  'administrator', 'Full back-end access to the admin dashboard.'),
  ('Author',      'author',     'site',          'May contribute content from the front end.');

-- ------------------------------------------------------------------ users ---
-- One row per account. `status` gates sign-in:
--   pending   = registered, email not yet verified
--   active    = verified, may sign in
--   suspended = blocked by an operator
CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username          VARCHAR(20)     NOT NULL,
  email             VARCHAR(254)    NOT NULL,
  password_hash     VARCHAR(255)    NOT NULL,
  usergroup_id      BIGINT UNSIGNED NOT NULL,
  email_verified_at DATETIME        NULL DEFAULT NULL,
  status            ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  -- Stamped whenever the password changes (reset or profile change). PHP sessions
  -- live in files, so there is no list of them to clear on a password change; this
  -- column is how a session minted before the change is recognised as stale.
  password_changed_at DATETIME      NULL DEFAULT NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status),
  KEY idx_users_usergroup (usergroup_id),
  CONSTRAINT fk_users_usergroup FOREIGN KEY (usergroup_id)
    REFERENCES usergroups (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- credential_types ---
-- A named credential and the key that goes with it, e.g. "AI KEY".
-- `api_key` holds ciphertext, not the key itself: the application encrypts with
-- AES-256-GCM (see libraries/HazeGuard/Crypto.php) under CREDENTIAL_KEY from
-- .env, so a database dump or a stolen backup does not leak a working secret.
-- 512 chars because base64 of the nonce, tag and plaintext runs about 1.5x the
-- plaintext; the form still caps what an operator types at 255.
CREATE TABLE IF NOT EXISTS credential_types (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100)    NOT NULL,
  api_key    VARCHAR(512)    NULL DEFAULT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credential_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- email_verification_tokens ------
-- Hashed, single-use tokens for link-based email verification.
CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64)        NOT NULL,           -- sha256 hex of the raw token
  expires_at DATETIME        NOT NULL,
  used_at    DATETIME        NULL DEFAULT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evt_token_hash (token_hash),
  KEY idx_evt_user (user_id),
  KEY idx_evt_expires (expires_at),
  CONSTRAINT fk_evt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- email_otps ---
-- Hashed 6-digit codes for BOTH email verification and OTP login.
-- Codes are stored hashed, expire quickly, are single-use, and are attempt-capped.
CREATE TABLE IF NOT EXISTS email_otps (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  purpose       ENUM('verify_email','login','reset_password') NOT NULL,
  otp_hash      CHAR(64)        NOT NULL,        -- sha256 hex of the 6-digit code
  expires_at    DATETIME        NOT NULL,
  used_at       DATETIME        NULL DEFAULT NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_user_purpose (user_id, purpose),
  KEY idx_otp_expires (expires_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset uses email_otps with purpose='reset_password'. It used to have
-- its own table of emailed link tokens; that was dropped in
-- 2026-10-12-reset-uses-otp.php when the flow moved to a typed 6-digit code.

-- ------------------------------------------------------------ rate_limits ---
-- Fixed-window counters for throttling (OTP requests, login attempts).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       VARCHAR(191) NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- user_preferences ---
-- One row per account, created on first save. `activities` is a comma-separated
-- list of slugs validated against the fixed set in ProfileModel, not free text,
-- so nothing arbitrary can be stored here.
--
-- Notification toggles are deliberately absent: nothing delivers a notification,
-- so a stored "Daily AQI summary: on" would be a promise the app cannot keep.
-- That table arrives with the alerts feature (T6.3) or not at all.
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id         BIGINT UNSIGNED NOT NULL,
  activities      VARCHAR(191)     NOT NULL DEFAULT '',
  location_name   VARCHAR(120)     NULL DEFAULT NULL,
  location_lat    DECIMAL(10,6)    NULL DEFAULT NULL,
  location_lon    DECIMAL(10,6)    NULL DEFAULT NULL,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- activity_plans ---
-- The planner screen's saved plans, one row each, owned by the account that
-- saved it. `activity` is free text on purpose (the planner lets someone type
-- their own activity), so it is capped rather than allow-listed; `intensity`
-- travels with it because that is what the AQI threshold was derived from.
--
-- `hours_offset` is the hours from *now* that the activity starts, kept beside
-- the resolved `starts_at` so a listing prints a real date without recomputing
-- the offset from a wall clock that has moved on. `verdict_aqi` records the
-- reading the verdict was judged against, so a saved plan does not silently
-- re-judge itself against later air.
CREATE TABLE IF NOT EXISTS activity_plans (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  activity     VARCHAR(60)     NOT NULL,
  intensity    ENUM('light','moderate','intense') NOT NULL DEFAULT 'moderate',
  place_name   VARCHAR(120)    NOT NULL,
  place_lat    DECIMAL(10,6)   NULL DEFAULT NULL,
  place_lon    DECIMAL(10,6)   NULL DEFAULT NULL,
  hours_offset SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  starts_at    DATETIME        NOT NULL,
  verdict_aqi  SMALLINT UNSIGNED NOT NULL,
  prefs        VARCHAR(191)    NOT NULL DEFAULT '',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plans_user_starts (user_id, starts_at),
  CONSTRAINT fk_plans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
