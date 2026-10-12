-- ============================================================================
-- Providers become data, not a hard-coded list.
--
-- Before: the providers a credential type offered were the literal keys of a
-- PHP array in Entities.php ('gemini' => 'Gemini', ...), and
-- credential_type_options stored the raw string. Adding a provider meant editing
-- code.
--
-- After: providers are rows in their own table, so an operator can add one from
-- the UI and it immediately appears in every provider dropdown.
--
--   providers                 the master list           (id, name, slug)
--   credential_type_providers which providers a type offers
--   credential_keys           now points at (type_id, provider_id)
--
-- The composite foreign key is preserved and made *stronger*: it now references
-- credential_type_providers (type_id, provider_id) directly, so the database
-- still rejects a key whose provider the chosen type does not offer — and the
-- column is now a real provider id rather than an opaque join-row id.
--
-- Apply once:  mysql -u root hazeguard < database/migrations/2026-10-11-providers.sql
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------- providers ---
CREATE TABLE IF NOT EXISTS providers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60)     NOT NULL,          -- shown in the dropdowns
  slug       VARCHAR(60)     NOT NULL,          -- stable identifier
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_providers_name (name),
  UNIQUE KEY uq_providers_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed with the providers that used to be hard-coded, so nothing is lost.
INSERT IGNORE INTO providers (name, slug) VALUES
  ('Gemini',   'gemini'),
  ('OpenAI',   'openai'),
  ('DeepSeek', 'deepseek'),
  ('Claude',   'claude'),
  ('Brevo',    'brevo'),
  ('Other',    'other');

-- Any provider that exists in the data but was not in that list is carried over
-- too, so an operator's earlier free-text entry survives the migration.
INSERT IGNORE INTO providers (name, slug)
SELECT DISTINCT CONCAT(UPPER(LEFT(o.option, 1)), SUBSTRING(o.option, 2)), LOWER(o.option)
  FROM credential_type_options o;

-- ---------------------------------------------- credential_type_providers ---
-- Which providers a credential type offers. Replaces credential_type_options.
CREATE TABLE IF NOT EXISTS credential_type_providers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id     BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Doubles as the referenced index for the composite foreign key on
  -- credential_keys below, so no extra redundant key is needed.
  UNIQUE KEY uq_ctp_type_provider (type_id, provider_id),
  KEY idx_ctp_provider (provider_id),
  CONSTRAINT fk_ctp_type FOREIGN KEY (type_id)
    REFERENCES credential_types (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ctp_provider FOREIGN KEY (provider_id)
    REFERENCES providers (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry over the existing type -> provider pairings.
INSERT IGNORE INTO credential_type_providers (type_id, provider_id)
SELECT o.type_id, p.id
  FROM credential_type_options o
  JOIN providers p ON p.slug = LOWER(o.option);

-- --------------------------------------------------------- credential_keys ---
-- Added nullable first so the existing rows can be backfilled before the
-- NOT NULL constraint goes on.
ALTER TABLE credential_keys
  ADD COLUMN IF NOT EXISTS provider_id BIGINT UNSIGNED NULL AFTER type_id;

UPDATE credential_keys k
  JOIN credential_type_options o ON o.id = k.option_id
  JOIN providers p ON p.slug = LOWER(o.option)
   SET k.provider_id = p.id
 WHERE k.provider_id IS NULL;

-- Anything still unresolved would break the NOT NULL below, loudly, which is
-- the right outcome: a silent NULL would hide a lost key.
ALTER TABLE credential_keys
  DROP FOREIGN KEY IF EXISTS fk_credential_keys_option;

ALTER TABLE credential_keys
  DROP KEY IF EXISTS idx_credential_keys_option;

ALTER TABLE credential_keys
  DROP COLUMN IF EXISTS option_id;

ALTER TABLE credential_keys
  MODIFY provider_id BIGINT UNSIGNED NOT NULL,
  ADD KEY IF NOT EXISTS idx_credential_keys_provider (provider_id),
  ADD CONSTRAINT fk_credential_keys_provider
    FOREIGN KEY (type_id, provider_id)
    REFERENCES credential_type_providers (type_id, provider_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- The old table has no remaining references.
DROP TABLE IF EXISTS credential_type_options;
