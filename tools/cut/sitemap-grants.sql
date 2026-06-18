-- Cut step 11d (SEO sitemap, 2026-06-15) — the archive-poc role serves /sitemap.xml,
-- whose profiles section reads public profile slugs from the SEPARATE profile_app DB.
-- Column-scoped read only (slug, profile_visibility, updated_at) — no PII columns.
-- Re-apply after EVERY profile_app PG restore (grants don't survive a restore),
-- exactly like tools/cut/forums-grant.sql does for the looth DB.
--
-- A fresh PG16 restore also needs CONNECT on the database + USAGE on schema public,
-- or the sitemap profiles section silently returns EMPTY (PG16 revokes public-schema
-- USAGE from PUBLIC by default). Confirmed on the dev2 cut box 2026-06-15: column
-- grant alone → 0 profiles; adding CONNECT + USAGE → 1,904. All three are idempotent.
--
-- Run on the cut box's PG as a superuser, against the profile_app database:
--   sudo -u postgres psql profile_app -f tools/cut/sitemap-grants.sql
GRANT CONNECT ON DATABASE profile_app TO "archive-poc";
GRANT USAGE ON SCHEMA public TO "archive-poc";
GRANT SELECT (slug, profile_visibility, updated_at) ON public.users TO "archive-poc";

-- NOTE: the content section of the sitemap reads discovery.content_item in the looth
-- DB, which the archive-poc role already owns/reads (no extra grant needed there).
