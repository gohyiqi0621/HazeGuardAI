-- ============================================================================
-- Migration 2026-10-11 — credential type options (multi-select) and key option
-- Run ONCE against an existing `hazeguard` database:
--   mysql -u root hazeguard < database/migrations/2026-10-11-credential-options.sql
-- A fresh install should use database/schema.sql instead (it includes all of this).
--
-- Why: a credential type used to hold ONE `type` value. An "AI KEY" type now
-- offers several options at once (gemini + openai + deepseek), and each key says
-- which of its type's options it belongs to. That is a many-to-many shape, so it
-- gets a real table with a real foreign key rather than a comma-separated column.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- --------------------------------------------- credential_type_options ------
-- The options a credential type offers. ON DELETE RESTRICT: an option cannot be
-- removed while a key still uses it.
CREATE TABLE IF NOT EXISTS credential_type_options (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id    BIGINT UNSIGNED NOT NULL,
  option     VARCHAR(30)     NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cto_type_option (type_id, option),
  -- Redundant against the primary key on its own, but it is what lets
  -- credential_keys below carry the composite foreign key (option_id, type_id).
  UNIQUE KEY uq_cto_id_type (id, type_id),
  CONSTRAINT fk_cto_type FOREIGN KEY (type_id)
    REFERENCES credential_types (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry over the single option each existing type already had, so no data is
-- lost when the old column is dropped below.
INSERT IGNORE INTO credential_type_options (type_id, option)
SELECT id, LOWER(type) FROM credential_types WHERE type IS NOT NULL AND type <> '';

-- ------------------------------------------- credential_keys.option_id ------
ALTER TABLE credential_keys
  ADD COLUMN option_id BIGINT UNSIGNED NULL AFTER type_id;

-- A key inherits its option from the single option its type had, when there is
-- exactly one to inherit.
UPDATE credential_keys k
  JOIN credential_type_options o ON o.type_id = k.type_id
   SET k.option_id = o.id
 WHERE k.option_id IS NULL
   AND (SELECT COUNT(*) FROM credential_type_options x WHERE x.type_id = k.type_id) = 1;

-- If any key still has no option, the ALTER below will fail loudly rather than
-- silently writing 0. Delete or re-point those keys first.

ALTER TABLE credential_keys
  MODIFY option_id BIGINT UNSIGNED NOT NULL,
  ADD KEY idx_credential_keys_option (option_id),
  -- Composite: the option must belong to the SAME credential type as the key.
  -- This is the constraint a plain option_id foreign key could not express.
  ADD CONSTRAINT fk_credential_keys_option FOREIGN KEY (option_id, type_id)
    REFERENCES credential_type_options (id, type_id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- ------------------------------------------------- credential_types.type -----
-- Superseded by credential_type_options. Its index is dropped with it.
ALTER TABLE credential_types DROP COLUMN type;
