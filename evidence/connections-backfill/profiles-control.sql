-- ============================================================
-- The control that closed BRIDGE-GAP-INVENTORY section F.1.  READ ONLY.
--
-- `profiles` looked like a bridge gap (41.0% cutover vs 17.0% late). It is not.
-- The table records a profile CLAIM, and only two things write it:
--   * sql/0004_active_semantics_and_backfill.sql -- one-shot, predicate is
--     users.location_text <> '' , NOT wp_user_bridge
--   * Profile::claim()  -- runtime, an explicit member action
-- So the right control is location_text, not the bridge. Query A holds it
-- constant; query B shows the provenance directly.
--
-- Run against live read-only:
--   ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -f -" < profiles-control.sql
-- ============================================================

\echo '=== A. profiles coverage, CONTROLLED for location_text (0004 predicate) ==='
-- Expected on live 2026-07-28: cutover/no-location = 0.0%. That zero is the
-- whole finding -- it proves the backfill is the only mechanism behind the
-- cutover cohort's headline number.
WITH coh AS (
  SELECT u.id,
         CASE WHEN b.synced_at < '2026-06-03' THEN 'cutover' ELSE 'late' END AS cohort,
         (u.location_text IS NOT NULL AND u.location_text <> '') AS has_loc
  FROM public.users u JOIN public.wp_user_bridge b ON b.user_id = u.id
)
SELECT c.cohort, c.has_loc AS has_location_text, count(*) AS members,
       count(p.user_id) AS with_profiles_row,
       round(100.0 * count(p.user_id) / count(*), 1) AS pct
FROM coh c LEFT JOIN public.profiles p ON p.user_id = c.id
GROUP BY 1, 2 ORDER BY 1, 2;

\echo ''
\echo '=== B. claimed_via provenance by cohort ==='
-- Expected: cutover is 655 backfill_location (synthetic); late is 36 onboard
-- (genuine member actions). The late cohort was not skipped -- it was never
-- eligible for the courtesy, and it claims for real instead.
SELECT CASE WHEN b.synced_at < '2026-06-03' THEN 'cutover' ELSE 'late' END AS cohort,
       coalesce(p.claimed_via, '(null)') AS claimed_via, count(*) AS n
FROM public.profiles p JOIN public.wp_user_bridge b ON b.user_id = p.user_id
GROUP BY 1, 2 ORDER BY 1, 3 DESC;

\echo ''
\echo '=== C. the residue: late members with a location but no claim ==='
-- These see a first-visit interstitial their cutover peers were spared.
-- Cosmetic, and arguably correct. No action recommended.
SELECT count(*) AS late_with_location_unclaimed
FROM public.users u
JOIN public.wp_user_bridge b ON b.user_id = u.id
LEFT JOIN public.profiles p ON p.user_id = u.id
WHERE b.synced_at >= '2026-06-03'
  AND u.location_text IS NOT NULL AND u.location_text <> ''
  AND p.user_id IS NULL;
