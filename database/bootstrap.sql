-- ============================================================================
-- HazeGuard AI — one-time database bootstrap
-- Run once as an administrator (XAMPP default admin is root with no password):
--   mysql -u root < database/bootstrap.sql
--
-- Creates the database and a least-privilege application user.
-- Replace CHANGE_ME_STRONG_PASSWORD below with the value you also put in `.env`
-- (DB_PASS). Never commit the real password.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS hazeguard
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

-- Application user: full CRUD on the app schema only — no DDL, no other DBs.
CREATE USER IF NOT EXISTS 'hazeguard_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
CREATE USER IF NOT EXISTS 'hazeguard_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON hazeguard.*
  TO 'hazeguard_app'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON hazeguard.*
  TO 'hazeguard_app'@'127.0.0.1';

FLUSH PRIVILEGES;
