-- Featured members (backlog 18, Ian 8/11; design rulings 8/14) — the archive-poc
-- role's read access into the SEPARATE profile_app DB, widened for the
-- front-page card. archive-poc/web/index.php resolves a real member's card
-- (photo, tagline, location, bio) from profile_app when a member has been
-- selected in the admin dash and the feature flag is on.
--
-- ADDITIVE, not a replacement: tools/cut/sitemap-grants.sql already grants
-- CONNECT + schema USAGE + SELECT (slug, profile_visibility, updated_at) to
-- "archive-poc". Postgres column-level GRANTs accumulate per role/table (a
-- second GRANT SELECT (other, columns) does not revoke the first) — confirmed
-- on dev2 2026-08-15 before writing this file. Re-apply after every
-- profile_app PG restore, same as sitemap-grants.sql (grants don't survive one).
--
--   sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql
--
-- Column-scoped, same posture as the sitemap grant — no PII beyond what the
-- public card itself will render (no email, no exact address/lat-lng, no
-- phone). uuid is the join key the config stores (member_uuid); id is needed
-- to look up the About section, a separate table keyed on user_id.
-- profile_layout added in review 2026-08-15: Visibility::locationPrecision()
-- (profile-app/src/Visibility.php, the app-of-record for this rule) treats a
-- member removing the Location SECTION from their layout as off-map for
-- EVERYONE regardless of their precision dial — a check this cross-DB
-- resolver cannot call directly (Visibility is a profile-app class, not
-- loaded in archive-poc's PHP process) but must still honour, since it is
-- the same "not just is this a member" rule the charter names.
-- featured_opt_in_at added 2026-08-20 (#107, Ian's "the tick is consent" ruling).
-- The resolver must tell an INFORMED tick (made after the tickbox copy said the
-- one-liner may be republished on the public card) from one made under the old
-- wording, and that is the only column carrying it.
--
-- ⚠️ THIS ONE IS LOAD-BEARING AND FAILS SILENTLY IF MISSING. Measured on dev2
-- before it was granted: `SELECT featured_opt_in_at` as "archive-poc" returns
-- "permission denied for table users" — not a null, an EXCEPTION — and the
-- resolver's call site is wrapped in a try/catch that degrades to "no band" so
-- the front page cannot 500. The visible symptom of a missing grant is
-- therefore the featured band vanishing for every visitor, with nothing to say
-- why. Gate 39 §G2 asserts the role can really read it, for exactly that
-- reason. Apply this file BEFORE flipping platform/config/featured-consent.php.
GRANT SELECT (id, uuid, slug, display_name, avatar_url, at_a_glance, business_name,
              location_city, location_region, location_public_precision,
              location_members_precision, profile_visibility, featured_opt_in,
              featured_opt_in_at, profile_layout)
  ON public.users TO "archive-poc";

-- The About section — read-only, and the CALLER (index.php) is responsible for
-- checking visibility='public' before ever rendering `data->>'text'` on the
-- public front page. A members-only or private About must never reach here as
-- rendered text; granting SELECT on the table doesn't relax that rule, the
-- application code does the filtering (same posture as internal-byline-socials.php
-- trusting its caller — this is the analogous case one layer further out).
GRANT SELECT (user_id, key, visibility, data) ON public.profile_sections TO "archive-poc";
