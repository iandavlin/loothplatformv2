-- ============================================================
-- 10 of 12 — DRY RUN.  READ ONLY.  Writes nothing, changes nothing.
--
-- THE RE-REQUEST FIX. A SEPARATE DEFECT from the restore sets (1/2/3, 4/5/6,
-- 7/8/9). Those restore legacy friendships that have NO row at all. These 81
-- pairs DO have a row -- with the WRONG STATUS -- because the member re-sent a
-- connection request that then sat pending instead of resolving to the accepted
-- relationship that already existed in BuddyBoss. An INSERT can never fix these;
-- they need an UPDATE. Found by Ian from the UI, 2026-07-28.
--
-- Rows are addressed by (requester_uuid, addressee_uuid) PAIR, never by id, and
-- every statement also requires status='pending'. So this cannot touch a row that
-- has since been accepted properly, declined (deleted), or changed by anyone.
-- ============================================================
\set ON_ERROR_STOP on

DO $$ DECLARE n int; BEGIN
  IF to_regclass('public.connections_restatus_20260728') IS NULL THEN
    RAISE NOTICE 're-status tag table: DOES NOT EXIST YET (this fix has not been applied)';
  ELSE
    EXECUTE 'SELECT count(*) FROM public.connections_restatus_20260728' INTO n;
    RAISE NOTICE 're-status tag table: EXISTS, % rows tagged (this fix IS applied)', n;
  END IF;
END $$;

WITH payload(requester_uuid, addressee_uuid) AS (VALUES
  ('2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'9100eb89-f02b-5ead-b9e8-4ccaa3de0b3a'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'7915a20a-38d9-5d10-a886-368beae28f4f'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'5eb0982d-9ceb-5a65-9b27-33a5fba3f525'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'5552764f-fe74-542a-bd55-cb6e858923d7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'56d7846c-bda4-5fd5-82fc-70e0bb72c0fd'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'6a52d280-741f-51a4-ae0a-0346ed2cd127'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'4c5deca3-9790-5762-a3d1-84e9e71503bc'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'07a5e7ea-4325-59f4-bb3c-350e6271bbbb'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'798ddb37-0c3b-5093-97c2-9dcb069b07c7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'3ebab183-ff0a-587c-a891-85b8d4340ae6'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'46ea038d-8202-5e91-a338-9a868809e446'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'0f1dd09e-18ae-58d7-856f-44a65c11a631'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'5658cc50-9f70-56f5-a086-1dbb955aec70'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'7bd63017-1845-5d7b-ae07-7a58a2aede08'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'8f77b20c-dce7-551b-a3a1-912ec6b09cd6'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'438db892-f2f5-5d42-b2b0-1aa01b168183'::uuid),
  ('9d5aa168-973b-59ba-b6b1-8a55f4856a75'::uuid,'3aa726b4-2e73-59e9-9741-0894df80837a'::uuid),
  ('9d5aa168-973b-59ba-b6b1-8a55f4856a75'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8b3dcdda-6d73-5a5d-b2c9-a74f49d5e98b'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3d8ddd3c-dc05-59ae-8a19-06c5f09328bc'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'4800f7cc-e1d8-586e-bbe4-31dbef75d78f'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'d72a8938-a318-5e54-810c-cbc2254dfb71'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e8156818-5ff5-5217-8084-0973bb9f1360'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'caf47785-932a-5cfd-b1a7-c5a35d8a9fe8'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'86db4fef-189c-599d-b80b-7ada26d9907c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'ac93c62f-da4a-5e5c-8c5f-bb49b22bda34'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'35b48003-f0bb-56b9-b216-8bc2178a8f9c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'fb341eef-be46-5383-9a46-38896f1bfd84'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'afeffd27-9d3d-573b-b85a-f86010bf2e9c'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5247ac7f-b46c-5937-8e55-f91967efec43'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'afeffd27-9d3d-573b-b85a-f86010bf2e9c'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'8b3dcdda-6d73-5a5d-b2c9-a74f49d5e98b'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'3c8ebba4-a595-5809-bd90-12c149570963'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'133b3ac1-9858-5c9a-b2d9-5e03eeb483e0'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'99a3407a-e81e-5074-a95d-7183c53444dc'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'8a7f6219-ef6c-5f67-8583-933ae85e5a94'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'c1f0a8e1-6524-5764-994b-5785131fe234'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'e7606cd8-cc38-5ec4-acb3-360fda5d4058'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'467851bb-fe6d-5663-ba9d-6bf75de50d7d'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'b1ecdde3-3ae3-5d29-adbd-f6930ec1e4b3'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'e407384e-ba10-5585-8f33-7314fc80ca07'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c3567b65-9e49-5caa-8d17-5d87dab13c32'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'00529b9c-0c53-559e-b4e2-f72a196d9a2a'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3dc8607d-fc22-5e54-aecb-3e0bbe87e385'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'108002e3-5b9e-5377-aa00-99908e47e5c2'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1a17bd5f-e197-5b99-84dd-202728f7359e'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'cb3ef8cd-31ee-59c4-9e91-f7125fb0d191'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bb581485-df8f-56a8-87d8-35539026d664'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'46f85582-05a7-5bdd-bcb7-48842ddb5a05'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'bb581485-df8f-56a8-87d8-35539026d664'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'46f85582-05a7-5bdd-bcb7-48842ddb5a05'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'cb3ef8cd-31ee-59c4-9e91-f7125fb0d191'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'00529b9c-0c53-559e-b4e2-f72a196d9a2a'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'c3567b65-9e49-5caa-8d17-5d87dab13c32'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8b657748-2754-5393-a1a8-9488d48e894f'::uuid),
  ('f287ac86-0557-545a-9481-4f4ad1fcabb8'::uuid,'4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid),
  ('9d5aa168-973b-59ba-b6b1-8a55f4856a75'::uuid,'4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'15c79cc5-1609-593a-987d-17452477f743'::uuid),
  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid,'d72a8938-a318-5e54-810c-cbc2254dfb71'::uuid)
),
targets AS (
  SELECT c.id, c.requester_uuid, c.addressee_uuid, c.status
  FROM public.connections c
  JOIN payload p ON p.requester_uuid = c.requester_uuid
                AND p.addressee_uuid = c.addressee_uuid
),
flippable AS (SELECT * FROM targets WHERE status = 'pending'),
ian AS (SELECT u.uuid FROM public.users u JOIN public.wp_user_bridge b ON b.user_id=u.id WHERE b.wp_user_id=1)
SELECT 'pairs in this fix'                     AS what, (SELECT count(*) FROM payload)::text AS n
UNION ALL SELECT 'bridge says Ian uuid is',      (SELECT uuid FROM ian)::text
UNION ALL SELECT 'UUID MATCH (must say true)',   ((SELECT uuid FROM ian) = 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid)::text
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
UNION ALL SELECT '  badges cleared in total',    (SELECT count(*) FROM flippable)::text
UNION ALL SELECT '  (this fix REMOVES pending requests, it creates none. A raw'   , ''
UNION ALL SELECT '   SQL UPDATE writes no notification row - only'                , ''
UNION ALL SELECT '   Notifications::push() in PHP does that - and requests_pending', ''
UNION ALL SELECT '   counts only status=pending, so every affected inbox goes'    , ''
UNION ALL SELECT '   DOWN. Nobody is pinged; 71 people stop seeing a stale ask.)' , ''
UNION ALL SELECT '--- Ian ---', ''
UNION ALL SELECT 'Ian rows in this fix',         (SELECT count(*) FROM flippable f WHERE f.requester_uuid=(SELECT uuid FROM ian) OR f.addressee_uuid=(SELECT uuid FROM ian))::text
UNION ALL SELECT 'Ian accepted now',   (SELECT count(*) FROM public.connections c WHERE c.status='accepted' AND (c.requester_uuid=(SELECT uuid FROM ian) OR c.addressee_uuid=(SELECT uuid FROM ian)))::text
UNION ALL SELECT 'Ian pending OUT now',(SELECT count(*) FROM public.connections c WHERE c.status='pending'  AND c.requester_uuid=(SELECT uuid FROM ian))::text;
