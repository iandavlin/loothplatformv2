-- profile-app — spine increment 2 (location block): user-managed pin precision.
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator applies
-- after review). Separate file from 2026-05-30-block-system-spine.sql, which may
-- already be applied — never edit an applied migration.
--
-- The location block's two-tier model needs the USER's chosen display PRECISION
-- persisted (canon: plan-profile-block-system.md #4 "User-MANAGED pin" — the user
-- places the pin, picks how precise it SHOWS: exact → neighborhood → city). The
-- two visibility tiers already exist (users.location_visibility = approximate;
-- users.location_exact_visibility = exact, from increment 1). Precision is the
-- third managed dimension and has no home yet → this one column.
--
-- NOT re-introducing the old slice-2.75 `location_precision` (that was a derived
-- privacy-shave; this is an explicit USER display choice). Different concept.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_pin_precision text NOT NULL DEFAULT 'exact';
ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_location_pin_precision_ck;
ALTER TABLE users
    ADD CONSTRAINT users_location_pin_precision_ck
    CHECK (location_pin_precision IN ('exact', 'neighborhood', 'city'));

COMMIT;

-- Coarse "near me"/map coords are DERIVED (rounding the stored lat/lng) at read
-- time in Looth\ProfileApp\Block::loadLocation — NO approx-coord column (resolved
-- schema decision A). Exact lat/lng stays the gated pin.
