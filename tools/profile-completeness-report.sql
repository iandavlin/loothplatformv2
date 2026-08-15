-- profile-completeness-report.sql — the state of member profiles, reproducibly.
--
-- Written by the featured-members lane 2026-08-12 for backlog item 18 (featured
-- members) and Ian's ruling that the tickbox flow should surface "a percent
-- completed of the profile". Also the quantified business case for backlog item
-- 19 (new-member profiles arrive alive).
--
-- RUN IT (read-only, safe on live):
--   ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -f -" < tools/profile-completeness-report.sql
--   sudo -u postgres psql -d profile_app -f tools/profile-completeness-report.sql      # dev2
--
-- ⚠️ dev2 will NOT match live: dev2's newest rows are test fixtures
-- (proof-existing, ut-test-alice/bob/carol/dave). Live is the honest source for
-- any number quoted to Ian.
--
-- ⚠️⚠️ THE TRAP THIS FILE EXISTS TO STOP YOU REPEATING.
-- `business_name` looks like a filled-in field on 84% of profiles. It is NOT.
-- For 97% of them it is a SUFFIX SLICE OF THE MEMBER'S OWN display_name, left by
-- name parsing at profile creation:
--     display_name 'Brian Kuchta'  -> business_name 'Kuchta'
--     display_name 'Basil Smoke'   -> business_name 'Smoke'
-- Counting it as "what this member does" overstated the featurable population by
-- 22x (1,477 vs the real 66) until it was caught. Every query below therefore
-- tests `display_name NOT LIKE '%'||business_name` before believing it.
-- A non-empty column is not a filled-in field.

\pset footer off

-- The population every section below is measured against: real, public profiles.
-- Excludes the ~146 auto-generated patreon_<NNNNN> placeholder slugs, which /u.php
-- noindexes and the sitemap already skips.
CREATE TEMP VIEW pool AS
  SELECT * FROM users
   WHERE profile_visibility = 'public'
     AND slug !~ '^patreon_[0-9]+$';

-- The eight completeness items, one boolean each. THIS IS THE DEFINITION —
-- if the build and this file ever disagree, one of them is a bug.
CREATE TEMP VIEW completeness AS
  SELECT u.id, u.slug, u.display_name,
    (u.avatar_version > 0)                                            AS has_photo,
    ( (coalesce(u.location_city,'')   <> '')
      OR (coalesce(u.location_region,'') <> '') )                     AS has_where,
    ( (coalesce(u.at_a_glance,'') <> '')
      OR (coalesce(u.business_name,'') <> ''
          AND u.display_name NOT LIKE '%'||u.business_name) )         AS has_whatyoudo,
    EXISTS (SELECT 1 FROM profile_sections s
             WHERE s.user_id = u.id AND s.key = 'about'
               AND coalesce(s.data->>'text','') <> '')                AS has_about,
    EXISTS (SELECT 1 FROM profile_socials     x WHERE x.user_id = u.id) AS has_links,
    ( EXISTS (SELECT 1 FROM profile_skills      x WHERE x.user_id = u.id)
      OR EXISTS (SELECT 1 FROM profile_instruments x WHERE x.user_id = u.id) ) AS has_craft,
    EXISTS (SELECT 1 FROM profile_sections s
             WHERE s.user_id = u.id AND s.key LIKE 'gallery%')        AS has_gallery,
    (u.banner_version > 0)                                            AS has_banner
  FROM pool u;

\echo ''
\echo '=== 1. Population ==='
SELECT count(*) AS public_profiles_with_a_real_slug FROM pool;

\echo ''
\echo '=== 2. Each signal on its own ==='
SELECT signal, members, round(100.0*members/(SELECT count(*) FROM pool)) AS pct_of_pool
FROM (
  SELECT 'photo'                    AS signal, count(*) FILTER (WHERE has_photo)      AS members FROM completeness
  UNION ALL SELECT 'where you are',        count(*) FILTER (WHERE has_where)      FROM completeness
  UNION ALL SELECT 'what you do (REAL)',   count(*) FILTER (WHERE has_whatyoudo)  FROM completeness
  UNION ALL SELECT 'about (any vis)',      count(*) FILTER (WHERE has_about)      FROM completeness
  UNION ALL SELECT 'a link or two',        count(*) FILTER (WHERE has_links)      FROM completeness
  UNION ALL SELECT 'skills/instruments',   count(*) FILTER (WHERE has_craft)      FROM completeness
  UNION ALL SELECT 'gallery',              count(*) FILTER (WHERE has_gallery)    FROM completeness
  UNION ALL SELECT 'banner',               count(*) FILTER (WHERE has_banner)     FROM completeness
) t ORDER BY members DESC;

\echo ''
\echo '=== 3. The business_name trap, quantified (re-run this if the 97% is ever doubted) ==='
SELECT CASE WHEN display_name LIKE '%'||business_name
            THEN 'derived: a slice of display_name (NOT member-written)'
            ELSE 'independent of display_name (real)' END AS kind,
       count(*), round(100.0*count(*)/sum(count(*)) OVER ()) AS pct
FROM pool WHERE coalesce(business_name,'') <> ''
GROUP BY 1 ORDER BY 2 DESC;

\echo ''
\echo '=== 4. The completeness distribution (8 items, 12.5% each) ==='
SELECT items || ' of 8' AS score,
       items*100/8      AS pct,
       count(*)         AS members,
       round(100.0*count(*)/sum(count(*)) OVER (), 1) AS share_of_pool
FROM (
  SELECT (has_photo::int + has_where::int + has_whatyoudo::int + has_about::int
        + has_links::int + has_craft::int + has_gallery::int + has_banner::int) AS items
  FROM completeness
) t GROUP BY items ORDER BY items;

\echo ''
\echo '=== 5. Could they make a featured CARD? (photo + what-you-do + where) ==='
\echo '    NB: distinct from the percentage above — the card renders only these.'
SELECT state, count(*) FROM (
  SELECT CASE
    WHEN has_photo AND has_whatyoudo AND has_where THEN 'READY: complete card'
    WHEN has_photo AND has_where                   THEN 'photo + where, no line about what they do'
    WHEN has_photo AND has_whatyoudo               THEN 'photo + line, no location'
    WHEN has_photo                                 THEN 'photo only'
    ELSE 'not even a photo' END AS state
  FROM completeness
) t GROUP BY state ORDER BY 2 DESC;

\echo ''
\echo '=== 6. Cards that could carry a BIO (About marked public) ==='
\echo '    A members-only About must NEVER be quoted onto the public front page.'
SELECT count(*) AS members_with_a_public_about
FROM pool u
WHERE EXISTS (SELECT 1 FROM profile_sections s
               WHERE s.user_id = u.id AND s.key = 'about'
                 AND s.visibility = 'public'
                 AND coalesce(s.data->>'text','') <> '');
