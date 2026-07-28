-- ============================================================
-- CANARY 8 of 9 — APPLY, IAN ONLY, ACCEPTED ONLY.  Writes 83 rows at most.
--
-- THE SILENT SET. Restores only Ian's 83 ACCEPTED connections. Touches no
-- pending row, so NOBODY is notified and no badge changes anywhere.
-- Ian's ruling 2026-07-28: judge these before deciding anything that puts an
-- incoming request in front of another member.
--
-- Its tag table is its OWN (connections_restore_20260727_ian_acc).
-- It deliberately does NOT reuse the 4/5/6 table, so file 6 keeps meaning
-- exactly what it says and file 9 undoes exactly this set.
--
-- Safety, same as 4/5/6:
--  - aborts unless the bridge-derived uuid for WP user 1 matches the uuid this
--    payload was generated for (guards a wrong-box paste);
--  - aborts if the payload is not exactly 83 rows (guards a truncated paste);
--  - aborts if any row is not 'accepted' (guards this file being edited into
--    something that notifies people);
--  - idempotent via the real UNIQUE (requester_uuid, addressee_uuid) PLUS a
--    NOT EXISTS reverse guard, because that constraint is DIRECTIONAL. The
--    reverse guard is what stops an opposite-direction re-request becoming a
--    duplicate relationship row.
--
-- Original created_at preserved - these are real 2023-2026 relationships.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

CREATE TABLE IF NOT EXISTS public.connections_restore_20260727_ian_acc (
  connection_id bigint PRIMARY KEY REFERENCES public.connections(id) ON DELETE CASCADE,
  tagged_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TEMP TABLE payload(requester_uuid uuid, addressee_uuid uuid, status text, created_at timestamptz) ON COMMIT DROP;
INSERT INTO payload VALUES
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'accepted',TIMESTAMPTZ '2023-06-19 15:02:17+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2023-07-03 18:34:35+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e17dc218-73d0-5cbd-b0f7-efdaa6cf554d'::uuid,'accepted',TIMESTAMPTZ '2023-07-19 03:06:54+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2023-07-25 02:30:46+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2023-08-08 23:04:09+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2023-08-10 10:31:45+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'accepted',TIMESTAMPTZ '2023-08-28 13:11:28+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'ad9c1800-c882-58f7-b309-478cd89eef6d'::uuid,'accepted',TIMESTAMPTZ '2023-08-29 14:19:35+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2023-08-31 23:40:21+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'4937ec39-d3b7-5319-ad67-bbb0356455d4'::uuid,'accepted',TIMESTAMPTZ '2023-09-08 10:46:42+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'2a00c8df-7a98-55a2-8061-303014a9ab89'::uuid,'accepted',TIMESTAMPTZ '2023-09-11 18:42:18+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'af1951a5-78f5-592f-ba20-b7c515a6d497'::uuid,'accepted',TIMESTAMPTZ '2023-09-20 17:18:04+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bbed65f9-964f-555a-8116-5039399e83f8'::uuid,'accepted',TIMESTAMPTZ '2023-09-24 09:44:52+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2023-10-05 21:36:52+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'9a7cf36b-c234-5a68-a344-53cc22fb0226'::uuid,'accepted',TIMESTAMPTZ '2023-10-05 22:11:19+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'d97c936f-6de2-5cef-a6b7-4c4688c3ccc0'::uuid,'accepted',TIMESTAMPTZ '2023-10-08 11:04:02+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3ebab183-ff0a-587c-a891-85b8d4340ae6'::uuid,'accepted',TIMESTAMPTZ '2023-10-08 19:34:15+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2023-10-09 14:18:14+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'740d3a53-9d89-5da0-aebb-661348dcbcc6'::uuid,'accepted',TIMESTAMPTZ '2023-10-11 19:44:28+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'22f34963-366f-57b8-ac6f-5c336af4dcc2'::uuid,'accepted',TIMESTAMPTZ '2023-10-13 09:32:59+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'30e1ff7f-c42c-57e5-bc32-d1e30349cb99'::uuid,'accepted',TIMESTAMPTZ '2023-10-27 01:51:51+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'dbc869dd-d453-5e92-8bf4-10bb63084543'::uuid,'accepted',TIMESTAMPTZ '2023-11-03 13:50:35+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid,'accepted',TIMESTAMPTZ '2023-11-08 18:36:34+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'27aed6e2-6658-5f7d-a748-5861d44aeaf6'::uuid,'accepted',TIMESTAMPTZ '2023-12-03 07:45:58+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5eb0982d-9ceb-5a65-9b27-33a5fba3f525'::uuid,'accepted',TIMESTAMPTZ '2023-12-15 13:32:57+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'241c12b3-e752-5dd1-a6e8-69c1b3cdf54e'::uuid,'accepted',TIMESTAMPTZ '2023-12-22 16:42:32+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5552764f-fe74-542a-bd55-cb6e858923d7'::uuid,'accepted',TIMESTAMPTZ '2024-01-03 21:18:19+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2024-01-05 00:22:30+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1770c461-e93c-56a8-b91e-6b49c1169dbf'::uuid,'accepted',TIMESTAMPTZ '2024-01-13 22:05:35+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'accepted',TIMESTAMPTZ '2024-02-07 08:47:10+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'954ec8ae-eb40-5ded-8f0b-6e597065223e'::uuid,'accepted',TIMESTAMPTZ '2024-02-28 21:38:02+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'accepted',TIMESTAMPTZ '2024-03-04 21:41:31+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'df87ffa1-daf2-5324-9bee-dcdd2a63c625'::uuid,'accepted',TIMESTAMPTZ '2024-03-08 19:10:54+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2024-03-15 11:07:58+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'31eefd65-9883-5fcc-a0a3-155e4841a90e'::uuid,'accepted',TIMESTAMPTZ '2024-04-01 17:08:00+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'cd4bfcf6-ad90-5d1e-a619-21ca04d1df62'::uuid,'accepted',TIMESTAMPTZ '2024-04-02 18:03:23+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid,'accepted',TIMESTAMPTZ '2024-04-03 02:25:49+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid,'accepted',TIMESTAMPTZ '2024-05-07 02:12:54+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'cd3fd2a2-2c64-5da1-a36f-391be928e24a'::uuid,'accepted',TIMESTAMPTZ '2024-05-07 15:03:24+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'46ea038d-8202-5e91-a338-9a868809e446'::uuid,'accepted',TIMESTAMPTZ '2024-06-15 14:39:45+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2024-06-19 21:56:08+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-07-08 22:40:49+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'0f1dd09e-18ae-58d7-856f-44a65c11a631'::uuid,'accepted',TIMESTAMPTZ '2024-07-09 17:39:03+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-07-18 02:05:15+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'4c5deca3-9790-5762-a3d1-84e9e71503bc'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 16:08:00+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 17:12:24+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5350f400-052f-5f01-80ff-9d5e9a57c8fe'::uuid,'accepted',TIMESTAMPTZ '2024-09-19 00:55:57+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid,'accepted',TIMESTAMPTZ '2024-09-24 01:49:58+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'eaa7c58c-da2c-53a4-b92d-905715dbb742'::uuid,'accepted',TIMESTAMPTZ '2024-09-24 10:21:50+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5658cc50-9f70-56f5-a086-1dbb955aec70'::uuid,'accepted',TIMESTAMPTZ '2024-09-25 01:39:02+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bc84d43a-9d4b-5a85-b63a-5ff3c14a7340'::uuid,'accepted',TIMESTAMPTZ '2024-12-02 03:19:43+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:45:04+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'7bd63017-1845-5d7b-ae07-7a58a2aede08'::uuid,'accepted',TIMESTAMPTZ '2025-01-19 03:12:55+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'a74d4f93-59d0-5312-8359-5ae5a74bb08c'::uuid,'accepted',TIMESTAMPTZ '2025-02-17 21:05:54+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'9f40d7a3-cde1-53a1-9b4f-318fe54d5f9a'::uuid,'accepted',TIMESTAMPTZ '2025-02-22 23:35:47+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'438db892-f2f5-5d42-b2b0-1aa01b168183'::uuid,'accepted',TIMESTAMPTZ '2025-08-18 21:48:07+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c87c94d3-aaad-54e2-8c8b-e6b8799a23c2'::uuid,'accepted',TIMESTAMPTZ '2025-09-03 23:46:53+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'d3bd4f18-0fdc-5eb9-b364-785cf19b5816'::uuid,'accepted',TIMESTAMPTZ '2025-09-06 17:12:12+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'07a5e7ea-4325-59f4-bb3c-350e6271bbbb'::uuid,'accepted',TIMESTAMPTZ '2025-12-15 23:40:48+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'48029ada-2c33-57b8-abf5-9f2acae7d783'::uuid,'accepted',TIMESTAMPTZ '2026-03-21 18:39:19+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e407384e-ba10-5585-8f33-7314fc80ca07'::uuid,'accepted',TIMESTAMPTZ '2026-03-26 02:19:44+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'a4812a0c-0f5f-50d0-ace5-f4cb5ce9829d'::uuid,'accepted',TIMESTAMPTZ '2026-03-26 05:34:05+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e16b00d9-f8d0-541a-82e7-6bd62d1fd32c'::uuid,'accepted',TIMESTAMPTZ '2026-03-29 16:47:46+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e359a550-b7ba-52cd-ab26-a8c8e912ae88'::uuid,'accepted',TIMESTAMPTZ '2026-04-05 13:55:55+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'32b8620c-836e-5e65-9341-bfc3222188d5'::uuid,'accepted',TIMESTAMPTZ '2026-04-05 14:28:12+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'423c58c3-13c7-57f5-8901-e6ab55877c98'::uuid,'accepted',TIMESTAMPTZ '2026-04-06 18:41:51+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3383f083-3447-552d-94d2-4a3f01b5f6d3'::uuid,'accepted',TIMESTAMPTZ '2026-04-11 01:19:59+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'bd5924b6-cbae-5312-833e-2cd26829c600'::uuid,'accepted',TIMESTAMPTZ '2026-04-11 12:42:10+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'6245b881-9a78-5a94-a2b6-4eecc436a3f4'::uuid,'accepted',TIMESTAMPTZ '2026-04-13 21:59:27+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'e7606cd8-cc38-5ec4-acb3-360fda5d4058'::uuid,'accepted',TIMESTAMPTZ '2026-04-14 15:35:04+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'414e4dd8-9dc9-52f0-8d36-fb73b9d2193d'::uuid,'accepted',TIMESTAMPTZ '2026-04-16 09:41:27+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'618e17df-f7eb-51ea-820f-f97b7dcc9cc4'::uuid,'accepted',TIMESTAMPTZ '2026-04-17 06:02:50+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'c1f0a8e1-6524-5764-994b-5785131fe234'::uuid,'accepted',TIMESTAMPTZ '2026-04-20 14:02:59+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'5ffd5d1e-3425-5c8b-9f6e-97be58cab383'::uuid,'accepted',TIMESTAMPTZ '2026-04-21 21:50:11+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 01:58:27+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'8a7f6219-ef6c-5f67-8583-933ae85e5a94'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 18:58:19+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'133b3ac1-9858-5c9a-b2d9-5e03eeb483e0'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 22:21:03+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'3c8ebba4-a595-5809-bd90-12c149570963'::uuid,'accepted',TIMESTAMPTZ '2026-04-29 15:16:44+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'42d9832e-ea2f-5c83-8f5f-8abc4804fdf7'::uuid,'accepted',TIMESTAMPTZ '2026-05-04 13:51:44+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'36d499be-4836-5b08-b176-86a9c03a3cc4'::uuid,'accepted',TIMESTAMPTZ '2026-05-06 07:36:42+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'99a3407a-e81e-5074-a95d-7183c53444dc'::uuid,'accepted',TIMESTAMPTZ '2026-05-11 14:01:16+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'467851bb-fe6d-5663-ba9d-6bf75de50d7d'::uuid,'accepted',TIMESTAMPTZ '2026-05-12 15:36:49+00'),
  ('f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid,'b1ecdde3-3ae3-5d29-adbd-f6930ec1e4b3'::uuid,'accepted',TIMESTAMPTZ '2026-05-19 00:21:29+00')
;

DO $$ DECLARE n int; bad int; u uuid; BEGIN
  SELECT count(*) INTO n FROM payload;
  IF n <> 83 THEN RAISE EXCEPTION 'payload is % rows, expected 83 - file is truncated, ABORTING', n; END IF;

  SELECT count(*) INTO bad FROM payload WHERE status <> 'accepted';
  IF bad > 0 THEN RAISE EXCEPTION '% non-accepted rows in an ACCEPTED-ONLY file - ABORTING', bad; END IF;

  SELECT us.uuid INTO u FROM public.users us
    JOIN public.wp_user_bridge b ON b.user_id = us.id WHERE b.wp_user_id = 1;
  IF u IS NULL THEN RAISE EXCEPTION 'no wp_user_bridge row for wp_user_id=1 - wrong database, ABORTING'; END IF;
  IF u <> 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid THEN
    RAISE EXCEPTION 'bridge says WP user 1 is %, payload was built for f20ad778-1e5e-5508-853b-ad928c499f2f - wrong database, ABORTING', u;
  END IF;
END $$;

WITH ins AS (
  INSERT INTO public.connections (requester_uuid, addressee_uuid, status, created_at, updated_at)
  SELECT p.requester_uuid, p.addressee_uuid, p.status, p.created_at, p.created_at
  FROM payload p
  WHERE p.requester_uuid <> p.addressee_uuid
    AND NOT EXISTS (SELECT 1 FROM public.connections c
                    WHERE c.requester_uuid=p.addressee_uuid AND c.addressee_uuid=p.requester_uuid)
  ON CONFLICT (requester_uuid, addressee_uuid) DO NOTHING
  RETURNING id
)
INSERT INTO public.connections_restore_20260727_ian_acc (connection_id) SELECT id FROM ins;

SELECT 'accepted rows inserted and tagged (cumulative)' AS what, count(*)::text AS n
  FROM public.connections_restore_20260727_ian_acc;

SELECT status, count(*) AS ian_total_after
  FROM public.connections c
 WHERE c.requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid OR c.addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid
 GROUP BY status ORDER BY status;

COMMIT;
