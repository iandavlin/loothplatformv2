-- ============================================================
-- 17 of 18 -- APPLY.  Changes at most 23 rows.
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
--
-- Aborts unless: the payload is exactly 23 pairs (truncated paste); WP user 1
-- resolves to the expected uuid (wrong database); and EVERY pair touches Ian
-- (guards this file being edited into something that changes other people).
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

CREATE TABLE IF NOT EXISTS public.connections_restatus_20260729_ian_only (
  connection_id bigint PRIMARY KEY REFERENCES public.connections(id) ON DELETE CASCADE,
  prior_status  text NOT NULL,
  tagged_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TEMP TABLE payload(requester_uuid uuid, addressee_uuid uuid) ON COMMIT DROP;
INSERT INTO payload VALUES
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
;

DO $$ DECLARE n int; u uuid; BEGIN
  SELECT count(*) INTO n FROM payload;
  IF n <> 23 THEN RAISE EXCEPTION 'payload is % pairs, expected 23 - file is truncated, ABORTING', n; END IF;

  SELECT us.uuid INTO u FROM public.users us
    JOIN public.wp_user_bridge b ON b.user_id = us.id WHERE b.wp_user_id = 1;
  IF u IS NULL THEN RAISE EXCEPTION 'no wp_user_bridge row for wp_user_id=1 - wrong database, ABORTING'; END IF;
  IF u <> 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid THEN
    RAISE EXCEPTION 'bridge says WP user 1 is %, payload was built for f20ad778-1e5e-5508-853b-ad928c499f2f - wrong database, ABORTING', u;
  END IF;

  SELECT count(*) INTO n FROM payload
   WHERE requester_uuid <> 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid AND addressee_uuid <> 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid;
  IF n > 0 THEN RAISE EXCEPTION '% pairs in an IAN-ONLY file do not touch Ian - ABORTING', n; END IF;
END $$;

-- Tag BEFORE the update, recording the status we are about to overwrite.
INSERT INTO public.connections_restatus_20260729_ian_only (connection_id, prior_status)
SELECT c.id, c.status
FROM public.connections c
JOIN payload p ON p.requester_uuid = c.requester_uuid AND p.addressee_uuid = c.addressee_uuid
WHERE c.status = 'pending'
ON CONFLICT (connection_id) DO NOTHING;

UPDATE public.connections c
   SET status = 'accepted'
  FROM payload p
 WHERE p.requester_uuid = c.requester_uuid
   AND p.addressee_uuid = c.addressee_uuid
   AND c.status = 'pending';

SELECT 'rows flipped and tagged (cumulative)' AS what, count(*)::text AS n
  FROM public.connections_restatus_20260729_ian_only;

SELECT status, count(*) AS ian_total_after
  FROM public.connections c
 WHERE c.requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid OR c.addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid
 GROUP BY status ORDER BY status;

COMMIT;
