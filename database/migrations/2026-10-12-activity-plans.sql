-- ============================================================================
-- Saved activity plans — the planner screen's storage.
--
-- Before: "Save plan" only raised a toast. Nothing was written anywhere, so a
-- planned run disappeared on reload and the planner could not answer "what did
-- I plan?".
--
-- After: one row per saved plan, owned by the account that saved it.
--
--   activity       the built-in label ("Running") or the user's own free text
--                  ("Skateboarding"). Free text is deliberate here — unlike
--                  user_preferences.activities, which is a slug allowlist — so
--                  the column is capped at 60 chars and stored as given.
--   intensity      light|moderate|intense. Kept beside the activity because it
--                  is what a custom activity supplies and what the threshold
--                  was derived from at save time.
--   place_name     the label shown to the user (a city, or "lat, lon").
--   hours_offset   hours from now that the activity starts, which is how the
--                  row survives the clock moving on. `starts_at` is the
--                  resolved UTC timestamp so a listing can sort and print a
--                  real date without recomputing anything.
--   verdict_aqi    the AQI the verdict was computed from, so a saved plan
--                  records what it was judged against rather than silently
--                  re-judging itself against today's air.
--   prefs          comma-separated preference slugs from the collapsed
--                  "Preferences" block (one of the four offered, or empty).
--
-- Apply once:  mysql -u root hazeguard < database/migrations/2026-10-12-activity-plans.sql
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

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

-- The schema.sql import path has to produce the same table, so the file that
-- builds a fresh database carries an identical statement. Keep the two in step.
