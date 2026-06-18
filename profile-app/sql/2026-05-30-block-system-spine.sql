-- profile-app — Phase 1 block-system SPINE schema deltas.  DEV-FINAL.
--
-- ⚠️ WRITE-ONLY THIS TURN — apply-ready but NOT YET APPLIED. The spine is the ONE
-- migration target; coordinator applies + tests after review. Idempotent
-- (IF NOT EXISTS / DROP-then-ADD constraint) → safe to re-run.
--
-- Resolved schema (canon: plan-profile-block-system.md "Schema — RESOLVED
-- dev-final", 2026-05-30, Ian). THREE adds:
--   1. users.at_a_glance               — single-source author BIO (header field;
--                                         fills WP "about author" + every byline;
--                                         backfilled from WP user `description`).
--   2. users.location_exact_visibility — exact-address tier vis (default private;
--                                         city tier stays users.location_visibility).
--   3. practices.type                  — practice kind, set by user at creation
--                                         (practices are GREENFIELD — never backfilled).
--
-- NOT in this migration (resolved OUT):
--   • Header visibility = the profile's OWN vis = the section CAP, stored on the
--     header `profile_sections` row (key='header'.visibility). NO new column.
--   • NO approximate-coords column — coarse "near me"/map coords come from the
--     city/state CENTROID the directory geocoder already returns; exact lat/lng
--     (existing) stays the gated pin.
--   • `members` DB literal KEPT (plural). UI/JSON maps to "member" at one
--     normalize point (Looth\ProfileApp\Block::normalizeVis). No enum rename.
--
-- Already shipped by the retiring chat (commit 23fe81b) — DO NOT REDO:
--   users.location_address, profile_socials kind 'linktree'.

BEGIN;

-- 1. Single-source author bio. Person-level; practice uses practices.tagline.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS at_a_glance text;

-- 2. Exact-address tier visibility (separate from the city tier). Default private.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_exact_visibility text NOT NULL DEFAULT 'private';
ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_location_exact_visibility_ck;
ALTER TABLE users
    ADD CONSTRAINT users_location_exact_visibility_ck
    CHECK (location_exact_visibility IN ('members','private','on_request'));
-- exact must never be looser than the city tier (location_visibility) — enforced
-- in the app layer (Block) since a cross-column CHECK would need a trigger.

-- 3. Typed practices (greenfield; user sets type at creation, never backfilled).
ALTER TABLE practices
    ADD COLUMN IF NOT EXISTS type text;
ALTER TABLE practices
    DROP CONSTRAINT IF EXISTS practices_type_ck;
ALTER TABLE practices
    ADD CONSTRAINT practices_type_ck
    CHECK (type IS NULL OR type IN ('repair','build','touring_tech','retail','teaching','other'));

COMMIT;

-- Header-visibility (the ceiling) needs NO DDL: it lives on the existing
-- profile_sections(user_id, key='header', visibility) row, written by the
-- profile-header /me endpoint. Migration default at cut = everyone members-only
-- (handled by the profiles-only crib, a later turn — not here).
