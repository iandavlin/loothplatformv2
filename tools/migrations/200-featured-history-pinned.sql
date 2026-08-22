-- #200 — record WHETHER A FEATURED STINT WAS CONSENTED OR PINNED.
--
-- Ian, 2026-08-22: members he places by hand appear on the front page whether or
-- not they ticked the featured box. That is the ruling, and it is fine — but it
-- means discovery.featured_history can no longer be read as a list of people who
-- consented to being featured, which is exactly what someone auditing consent
-- would read it as. Every row before this migration WAS a consented pick, so the
-- default is correct history rather than a convenient guess.
--
-- Additive, non-breaking, and safe to run twice. Existing rows keep their
-- meaning; only the new column appears.
--
--   sudo -u postgres psql looth -f tools/migrations/200-featured-history-pinned.sql
--
-- ⚠️ THE CODE DOES NOT REQUIRE THIS TO HAVE RUN. archive-poc/api/v0/_config.php
-- checks information_schema for the column once per request and omits it from
-- the INSERT when it is absent, the same shape as u.php's featured_opt_in probe.
-- So a box that has not been migrated keeps writing history exactly as before
-- rather than failing its history write — which matters because live is that
-- box until Ian runs this, and a failed INSERT there would silently stop
-- recording stints altogether (the write is wrapped in a catch by design, so it
-- would not even be visible on the page).

ALTER TABLE discovery.featured_history
  ADD COLUMN IF NOT EXISTS pinned boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN discovery.featured_history.pinned IS
  'true = an admin placed this member by hand (#200); false = they ticked the '
  'featured box themselves and were chosen from the self-serve pool. Rows '
  'predating 2026-08-22 are all false, which is accurate: pinning did not exist.';
