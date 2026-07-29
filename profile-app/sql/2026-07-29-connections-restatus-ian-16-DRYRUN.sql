-- ============================================================
-- 16 of 18 -- DRY RUN.  READ ONLY.  Writes nothing, changes nothing.
--
-- SIX SETS EXIST.  DO NOT MIX THEM UP.  Each has its OWN tag table so that
-- rolling one back cannot disturb another.
--
--    1/ 2/ 3  restore 746 incl. pending   SUPERSEDED - do not run
--    4/ 5/ 6  restore 135 Ian incl. pend  SUPERSEDED - do not run
--    7/ 8/ 9  restore  83 Ian accepted    ALREADY APPLIED on live 2026-07-28
--   10/11/12  re-status  81 everyone      built, waiting
--   13/14/15  restore  271 everyone acc.  built, waiting
--   16/17/18  re-status  23 IAN ONLY      <-- THIS SET, the canary
--
-- THE CANARY. Ian's ruling 2026-07-29: fix HIS wrong-status rows first, look at
-- his own profile, and only then let the other 58 across 75 members follow. Same
-- pattern that earned trust for the 83.
--
-- These are pairs that are CONFIRMED friendships in wp_bp_friends but sit as
-- 'pending' today because Ian re-requested them on 2026-07-27. They already have
-- a row, with the wrong status, so no INSERT can fix them -- they need an UPDATE.
-- The restore sets are blind to them by construction (their predicate is "no row
-- at all").
--
-- SCOPE: exactly 23 rows, every one touching Ian, every one 'pending' today.
-- These 23 are a strict subset of the 81 in files 10/11/12.
--
-- ADDRESSED BY (requester_uuid, addressee_uuid) PAIR plus status='pending' --
-- never by id -- so it cannot touch a row that has since been accepted properly,
-- declined (deleted), or changed by anyone.
--
-- Tag table: public.connections_restatus_20260729_ian_only
-- ============================================================
\set ON_ERROR_STOP on

DO $$ DECLARE n int; BEGIN
  IF to_regclass('public.connections_restatus_20260729_ian_only') IS NULL THEN
    RAISE NOTICE 'tag table: DOES NOT EXIST YET (this canary has not been applied)';
  ELSE
    EXECUTE 'SELECT count(*) FROM public.connections_restatus_20260729_ian_only' INTO n;
    RAISE NOTICE 'tag table: EXISTS, % rows tagged (this canary IS applied)', n;
  END IF;
END $$;

WITH payload(requester_uuid, addressee_uuid) AS (VALUES
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8b657748-2754-5393-a1a8-9488d48e894f'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bb581485-df8f-56a8-87d8-35539026d664'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'46f85582-05a7-5bdd-bcb7-48842ddb5a05'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'cb3ef8cd-31ee-59c4-9e91-f7125fb0d191'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1a17bd5f-e197-5b99-84dd-202728f7359e'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'00529b9c-0c53-559e-b4e2-f72a196d9a2a'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'108002e3-5b9e-5377-aa00-99908e47e5c2'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3dc8607d-fc22-5e54-aecb-3e0bbe87e385'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c3567b65-9e49-5caa-8d17-5d87dab13c32'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5247ac7f-b46c-5937-8e55-f91967efec43'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'afeffd27-9d3d-573b-b85a-f86010bf2e9c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'fb341eef-be46-5383-9a46-38896f1bfd84'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'ac93c62f-da4a-5e5c-8c5f-bb49b22bda34'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'35b48003-f0bb-56b9-b216-8bc2178a8f9c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'86db4fef-189c-599d-b80b-7ada26d9907c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'caf47785-932a-5cfd-b1a7-c5a35d8a9fe8'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'15c79cc5-1609-593a-987d-17452477f743'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e8156818-5ff5-5217-8084-0973bb9f1360'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'d72a8938-a318-5e54-810c-cbc2254dfb71'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'4800f7cc-e1d8-586e-bbe4-31dbef75d78f'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3d8ddd3c-dc05-59ae-8a19-06c5f09328bc'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8b3dcdda-6d73-5a5d-b2c9-a74f49d5e98b'::uuid)
),
targets AS (
  SELECT c.id, c.requester_uuid, c.addressee_uuid, c.status
  FROM public.connections c
  JOIN payload p ON p.requester_uuid = c.requester_uuid
                AND p.addressee_uuid = c.addressee_uuid
),
flippable AS (SELECT * FROM targets WHERE status = 'pending'),
ian AS (SELECT u.uuid FROM public.users u JOIN public.wp_user_bridge b ON b.user_id=u.id WHERE b.wp_user_id=1)
SELECT 'pairs in this canary'                  AS what, (SELECT count(*) FROM payload)::text AS n
UNION ALL SELECT 'bridge says Ian uuid is',      (SELECT uuid FROM ian)::text
UNION ALL SELECT 'UUID MATCH (must say true)',   ((SELECT uuid FROM ian) = 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid)::text
UNION ALL SELECT 'every pair touches Ian',       ((SELECT count(*) FROM payload WHERE requester_uuid<>'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid AND addressee_uuid<>'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid)=0)::text
UNION ALL SELECT 'rows found on this box',       (SELECT count(*) FROM targets)::text
UNION ALL SELECT 'WILL FLIP pending -> accepted',(SELECT count(*) FROM flippable)::text
UNION ALL SELECT 'already accepted (skipped)',   (SELECT count(*) FROM targets WHERE status='accepted')::text
UNION ALL SELECT 'other status (skipped)',       (SELECT count(*) FROM targets WHERE status NOT IN ('pending','accepted'))::text
UNION ALL SELECT 'gone since measurement',       ((SELECT count(*) FROM payload)-(SELECT count(*) FROM targets))::text
UNION ALL SELECT '--- WHO GETS NOTIFIED ---', ''
UNION ALL SELECT 'members who get a NEW incoming-request badge', '0'
UNION ALL SELECT 'members who get a bell notification',          '0'
UNION ALL SELECT 'members who get an email',                     '0'
UNION ALL SELECT 'members whose STALE incoming badge CLEARS',
                                                 (SELECT count(DISTINCT addressee_uuid) FROM flippable)::text
UNION ALL SELECT '  (this fix REMOVES pending requests, it creates none. The'    , ''
UNION ALL SELECT '   friends badge counts status=pending ONLY, so every affected', ''
UNION ALL SELECT '   inbox goes DOWN. A bell is a row in notifications, written' , ''
UNION ALL SELECT '   only by Notifications::push() in PHP -- a raw SQL UPDATE'   , ''
UNION ALL SELECT '   mints none. No per-event email exists at all. Ian sent all' , ''
UNION ALL SELECT '   23 of these himself, so the people who stop seeing a stale' , ''
UNION ALL SELECT '   request from him are the only ones affected at all.)'       , ''
UNION ALL SELECT '--- IAN ---', ''
UNION ALL SELECT 'Ian accepted now',   (SELECT count(*) FROM public.connections c WHERE c.status='accepted' AND (c.requester_uuid=(SELECT uuid FROM ian) OR c.addressee_uuid=(SELECT uuid FROM ian)))::text
UNION ALL SELECT 'Ian accepted AFTER', (SELECT count(*)+(SELECT count(*) FROM flippable) FROM public.connections c WHERE c.status='accepted' AND (c.requester_uuid=(SELECT uuid FROM ian) OR c.addressee_uuid=(SELECT uuid FROM ian)))::text
UNION ALL SELECT 'Ian pending OUT now',(SELECT count(*) FROM public.connections c WHERE c.status='pending'  AND c.requester_uuid=(SELECT uuid FROM ian))::text
UNION ALL SELECT 'Ian pending OUT AFTER',(SELECT count(*)-(SELECT count(*) FROM flippable) FROM public.connections c WHERE c.status='pending' AND c.requester_uuid=(SELECT uuid FROM ian))::text
UNION ALL SELECT 'Ian pending IN (untouched)',(SELECT count(*) FROM public.connections c WHERE c.status='pending' AND c.addressee_uuid=(SELECT uuid FROM ian))::text;
