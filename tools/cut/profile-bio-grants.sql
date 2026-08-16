-- Profile bio flip (backlog: Ian 2026-08-16, "it should just be flipped to
-- the about from the profile") — the "looth-dev" role's read access into the
-- SEPARATE profile_app DB, for platform/lib/lg-profile-bio.php's resolver.
--
-- "looth-dev" is the WP FPM pool role (php8.3-fpm-looth-dev.sock) that
-- actually runs every current call site: lg-layout-v2/blocks/post-footer/
-- render.php and archive-poc/deploy/archive-poc-sync.mu-plugin.php are both
-- WP-loaded code, so their PDO connection peer-auths as "looth-dev", NOT as
-- "archive-poc" (tools/cut/featured-member-grants.sql's role — a different
-- app, a different FPM pool, already granted, does not cover this).
--
-- FOUND THE HARD WAY: the resolver was written and unit-tested successfully,
-- but never proven under the role that will actually run it in production.
-- `sudo -n -u looth-dev php -r '...lg_profile_bio(1);...'` returned
-- "ERROR: permission denied for table users" and the resolver's own
-- fail-closed design swallowed it into a silent null — meaning the flip
-- would have shipped, flag ON, and done NOTHING, forever, with no error
-- anyone would see. GRANT this before ever flipping the flag on anywhere.
--
--   sudo -u postgres psql profile_app -f tools/cut/profile-bio-grants.sql
--
-- ADDITIVE: column-level GRANTs accumulate per role/table (confirmed on this
-- box for "archive-poc" 2026-08-15; same Postgres behaviour applies here).
-- Re-apply after every profile_app PG restore — grants don't survive one.
--
-- Column-scoped to exactly what lg_profile_bio()'s query reads. No PII
-- beyond what the flip is explicitly meant to publish (an About the member
-- already marked public, a headline they already marked public, their
-- business name) — narrower than featured-member-grants.sql's own list,
-- since this resolver has no location/avatar/slug reason to exist.
GRANT SELECT (id, profile_layout, at_a_glance, business_name, profile_visibility)
  ON public.users TO "looth-dev";

GRANT SELECT (user_id, wp_user_id) ON public.wp_user_bridge TO "looth-dev";

-- Read-only, same posture as featured-member-grants.sql's identical grant:
-- the CALLER (lg_profile_bio()) checks visibility='public' before ever
-- reading data->>'text' — granting SELECT here doesn't relax that, the
-- application code does the filtering.
GRANT SELECT (user_id, key, visibility, data) ON public.profile_sections TO "looth-dev";
