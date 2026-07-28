-- ============================================================
-- 14 of 15 -- APPLY.  Writes at most 271 rows.
--
-- THE POPULATION RESTORE, ACCEPTED ONLY. Ian's ruling 2026-07-28: every member
-- missing connections gets their CONFIRMED friendships back, and the unanswered
-- requests are NOT restored. Same silent treatment he took for himself in files
-- 7/8/9, which landed on live and moved him 1251 -> 1334 with pending untouched.
--
-- Scope: 271 rows, 164 members. NOT one of them touches Ian -- his own 83
-- accepted rows were already restored by file 8, so this is the rest of the
-- population and nothing else.
--
-- Every row is status='accepted'. No pending row is created, so no incoming
-- request appears in front of anyone and NOBODY is notified. The mechanism is
-- spelled out in file 13; read it before running file 14.
--
-- SEPARATE from files 10/11/12 (the 81 wrong-status rows) ON PURPOSE. This set
-- INSERTS rows that are absent; that set UPDATES rows that exist with the wrong
-- status. The two populations are provably disjoint -- a pair with no row cannot
-- also be a pair with a pending row -- and each has its own tag table, so either
-- can be rolled back without touching the other.
--
-- Original created_at preserved. Proven convention, not assumed: of live's 7251
-- migrated accepted rows, 7232 carry created_at exactly equal to the BuddyBoss
-- date_created read as UTC. (BuddyPress writes date_created via
-- bp_core_current_time(), which is GMT.) Direction likewise preserved: 7247 of
-- 7251 run legacy-initiator -> legacy-friend, and this payload does the same.
--
-- Tag table: public.connections_restore_20260728_all_acc
-- ============================================================
--
-- Aborts unless: the payload is exactly 271 rows (truncated paste); every row is
-- 'accepted' (this file edited into something that notifies people); WP user 1
-- resolves to the expected uuid; and every payload uuid exists in users.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

CREATE TABLE IF NOT EXISTS public.connections_restore_20260728_all_acc (
  connection_id bigint PRIMARY KEY REFERENCES public.connections(id) ON DELETE CASCADE,
  tagged_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TEMP TABLE payload(requester_uuid uuid, addressee_uuid uuid, status text, created_at timestamptz) ON COMMIT DROP;
INSERT INTO payload VALUES
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'accepted',TIMESTAMPTZ '2023-06-20 11:14:25+00'),
  ('1286bc83-c9c5-56d4-b803-042e5c04a9b4'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'accepted',TIMESTAMPTZ '2023-06-21 04:48:27+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'fd83caaa-c213-5544-ae8b-b9bcdee13b21'::uuid,'accepted',TIMESTAMPTZ '2023-06-21 15:57:15+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'accepted',TIMESTAMPTZ '2023-06-30 10:51:09+00'),
  ('fd83caaa-c213-5544-ae8b-b9bcdee13b21'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2023-07-03 18:34:34+00'),
  ('2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'2ebd15c8-3b3e-5e22-af3d-ad0438f4afe2'::uuid,'accepted',TIMESTAMPTZ '2023-08-01 18:42:18+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'accepted',TIMESTAMPTZ '2023-08-11 15:22:59+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'ad9c1800-c882-58f7-b309-478cd89eef6d'::uuid,'accepted',TIMESTAMPTZ '2023-08-29 14:19:39+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'ad9c1800-c882-58f7-b309-478cd89eef6d'::uuid,'accepted',TIMESTAMPTZ '2023-08-29 22:12:13+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2023-08-31 23:40:25+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'accepted',TIMESTAMPTZ '2023-09-03 18:05:12+00'),
  ('d54a69f7-ac56-5e81-bf7c-2a51130b59d1'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2023-09-06 18:26:03+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2023-09-06 18:26:07+00'),
  ('4e0148ca-cb19-5f43-ac7c-130dc4fe9fc2'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'accepted',TIMESTAMPTZ '2023-09-21 04:57:21+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'accepted',TIMESTAMPTZ '2023-09-21 04:57:24+00'),
  ('1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'262bfc12-8dba-556c-83d2-24264eb70068'::uuid,'accepted',TIMESTAMPTZ '2023-09-22 15:53:43+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'0d5ee1bf-fcc7-57a1-92a8-129241c04b9b'::uuid,'accepted',TIMESTAMPTZ '2023-09-27 15:37:30+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'ec4fa090-3a75-5a14-b01e-89390ac4380c'::uuid,'accepted',TIMESTAMPTZ '2023-09-29 16:39:31+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'65f1a19e-5757-524d-bee0-57910537736f'::uuid,'accepted',TIMESTAMPTZ '2023-09-30 21:40:07+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2023-10-07 18:42:17+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'3aa726b4-2e73-59e9-9741-0894df80837a'::uuid,'accepted',TIMESTAMPTZ '2023-10-16 18:44:51+00'),
  ('5304799f-3336-5fc0-aa2e-627f88822694'::uuid,'3ebab183-ff0a-587c-a891-85b8d4340ae6'::uuid,'accepted',TIMESTAMPTZ '2023-10-22 21:43:10+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'3ebab183-ff0a-587c-a891-85b8d4340ae6'::uuid,'accepted',TIMESTAMPTZ '2023-10-22 21:43:12+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'7bace71d-82c5-5910-8f21-fcd738c14438'::uuid,'accepted',TIMESTAMPTZ '2023-11-02 10:30:07+00'),
  ('5304799f-3336-5fc0-aa2e-627f88822694'::uuid,'bbed65f9-964f-555a-8116-5039399e83f8'::uuid,'accepted',TIMESTAMPTZ '2023-11-02 17:09:13+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2023-11-06 19:25:18+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2023-11-06 19:25:21+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'626b0e10-031a-58e5-baf5-0214f3028f8f'::uuid,'accepted',TIMESTAMPTZ '2023-11-08 00:55:35+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'8a8dd6e0-80b7-5721-8adf-64800605e7a7'::uuid,'accepted',TIMESTAMPTZ '2023-11-11 03:12:49+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'8a8dd6e0-80b7-5721-8adf-64800605e7a7'::uuid,'accepted',TIMESTAMPTZ '2023-11-11 03:12:51+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'3d9196ad-3085-5bec-ba42-e02f4c48fa7e'::uuid,'accepted',TIMESTAMPTZ '2023-11-19 14:05:18+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'65d4d6f6-876e-5499-96aa-c13ef73ef961'::uuid,'accepted',TIMESTAMPTZ '2023-11-29 00:52:42+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2023-12-19 09:03:35+00'),
  ('4e0148ca-cb19-5f43-ac7c-130dc4fe9fc2'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2023-12-19 09:03:36+00'),
  ('ed73975f-9fa7-5f68-b924-d500dcecedb9'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2023-12-19 09:03:37+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'8f2400eb-7739-51a3-acbd-b57c99b68053'::uuid,'accepted',TIMESTAMPTZ '2023-12-20 11:35:49+00'),
  ('28950a66-4337-5c7b-87b5-cb0c03429e9b'::uuid,'241c12b3-e752-5dd1-a6e8-69c1b3cdf54e'::uuid,'accepted',TIMESTAMPTZ '2023-12-22 16:42:34+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'6c04a887-f31e-5ee6-a79b-06f57a62949c'::uuid,'accepted',TIMESTAMPTZ '2024-02-05 18:19:07+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'ad9c1800-c882-58f7-b309-478cd89eef6d'::uuid,'accepted',TIMESTAMPTZ '2024-02-16 03:11:41+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2024-02-17 00:29:12+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'9e24f7ca-bee2-5b61-b50c-4f476d83c7bc'::uuid,'accepted',TIMESTAMPTZ '2024-02-18 19:19:48+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'9100eb89-f02b-5ead-b9e8-4ccaa3de0b3a'::uuid,'accepted',TIMESTAMPTZ '2024-02-29 18:14:30+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'b98cd83a-95cb-57ec-8f9c-857709b4f125'::uuid,'accepted',TIMESTAMPTZ '2024-03-06 22:05:28+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'accepted',TIMESTAMPTZ '2024-03-07 14:27:05+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'df87ffa1-daf2-5324-9bee-dcdd2a63c625'::uuid,'accepted',TIMESTAMPTZ '2024-03-08 19:10:55+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'df87ffa1-daf2-5324-9bee-dcdd2a63c625'::uuid,'accepted',TIMESTAMPTZ '2024-03-08 19:10:57+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'0d5ee1bf-fcc7-57a1-92a8-129241c04b9b'::uuid,'accepted',TIMESTAMPTZ '2024-03-17 16:48:55+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'0297054d-178c-5fdc-a5e5-c7a4c4a7b27b'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 17:11:23+00'),
  ('d54a69f7-ac56-5e81-bf7c-2a51130b59d1'::uuid,'2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 19:01:32+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 19:01:37+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 19:01:42+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 21:30:32+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'accepted',TIMESTAMPTZ '2024-03-19 21:30:49+00'),
  ('411d6326-1d0d-53f7-8aca-cbf3d2c0d8a4'::uuid,'d97c936f-6de2-5cef-a6b7-4c4688c3ccc0'::uuid,'accepted',TIMESTAMPTZ '2024-03-26 21:49:33+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'d97c936f-6de2-5cef-a6b7-4c4688c3ccc0'::uuid,'accepted',TIMESTAMPTZ '2024-03-26 21:49:39+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2024-03-29 11:25:35+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'e7a250f4-f827-5673-b404-72c0ed29e0bd'::uuid,'accepted',TIMESTAMPTZ '2024-03-31 00:03:48+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'5552764f-fe74-542a-bd55-cb6e858923d7'::uuid,'accepted',TIMESTAMPTZ '2024-04-08 13:31:57+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'3576f8a0-1cc6-5e8a-ae4a-16ec7e9a1042'::uuid,'accepted',TIMESTAMPTZ '2024-05-02 08:58:54+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2024-05-06 00:13:48+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'cd3fd2a2-2c64-5da1-a36f-391be928e24a'::uuid,'accepted',TIMESTAMPTZ '2024-05-07 15:03:30+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2024-05-10 15:42:37+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'2a48e28f-2699-5dbf-b064-fa7c16c52e05'::uuid,'accepted',TIMESTAMPTZ '2024-05-11 15:43:59+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'57988cd4-8150-5f77-bf0a-8cbbe781d79f'::uuid,'accepted',TIMESTAMPTZ '2024-05-11 15:47:53+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'9daa2f32-12b7-5900-8bb4-fd37c45a4acd'::uuid,'accepted',TIMESTAMPTZ '2024-05-13 10:22:18+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'8f2400eb-7739-51a3-acbd-b57c99b68053'::uuid,'accepted',TIMESTAMPTZ '2024-05-13 15:43:55+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'8bec3061-b495-555d-961b-a3bf96e42592'::uuid,'accepted',TIMESTAMPTZ '2024-05-13 17:43:45+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2024-05-14 21:41:01+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2024-05-16 21:28:11+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'69e461e6-3869-5a30-83dc-3d964b6843c3'::uuid,'accepted',TIMESTAMPTZ '2024-06-03 22:31:38+00'),
  ('1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'2ad1d6fe-4b9a-5a86-b700-f8ae21a5c263'::uuid,'accepted',TIMESTAMPTZ '2024-06-07 16:39:46+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'eac3440d-a6e1-5c9c-b235-de64806adc11'::uuid,'accepted',TIMESTAMPTZ '2024-06-11 12:11:23+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'46ea038d-8202-5e91-a338-9a868809e446'::uuid,'accepted',TIMESTAMPTZ '2024-06-15 14:39:46+00'),
  ('31eefd65-9883-5fcc-a0a3-155e4841a90e'::uuid,'9100eb89-f02b-5ead-b9e8-4ccaa3de0b3a'::uuid,'accepted',TIMESTAMPTZ '2024-06-22 15:57:12+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'72ff69d9-10c8-5d81-a1c8-c1758d3038b3'::uuid,'accepted',TIMESTAMPTZ '2024-06-25 00:12:25+00'),
  ('28950a66-4337-5c7b-87b5-cb0c03429e9b'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2024-06-26 20:25:46+00'),
  ('f567e5ed-dfe7-54eb-9e3c-d8788ce5135f'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2024-06-26 20:25:47+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2024-06-26 20:25:53+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'5552764f-fe74-542a-bd55-cb6e858923d7'::uuid,'accepted',TIMESTAMPTZ '2024-06-29 20:23:33+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 18:50:36+00'),
  ('9100eb89-f02b-5ead-b9e8-4ccaa3de0b3a'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 18:50:38+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 18:50:42+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 19:14:35+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 19:22:28+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'99d519ed-846c-5295-8d47-44a74537f8f8'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 19:53:01+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2024-07-07 21:04:00+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'8f2400eb-7739-51a3-acbd-b57c99b68053'::uuid,'accepted',TIMESTAMPTZ '2024-07-08 10:42:50+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'cb9632b7-47cb-53de-851e-6edcd530e7d6'::uuid,'accepted',TIMESTAMPTZ '2024-07-08 18:43:36+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'3aa726b4-2e73-59e9-9741-0894df80837a'::uuid,'accepted',TIMESTAMPTZ '2024-07-13 15:20:08+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'bd3c1d8e-6e50-5596-babc-1e4568e49327'::uuid,'accepted',TIMESTAMPTZ '2024-07-13 19:35:45+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-07-18 02:05:17+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'52738710-cb3d-5716-b991-506917b0bfcf'::uuid,'accepted',TIMESTAMPTZ '2024-07-20 10:14:28+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'accepted',TIMESTAMPTZ '2024-07-24 19:08:15+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'6da42938-072e-5e8a-8d7f-270ee3e2a46f'::uuid,'accepted',TIMESTAMPTZ '2024-07-24 20:42:56+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'187057e7-2940-5683-a590-d1241d6c1fc5'::uuid,'accepted',TIMESTAMPTZ '2024-07-30 17:27:18+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'2ad1d6fe-4b9a-5a86-b700-f8ae21a5c263'::uuid,'accepted',TIMESTAMPTZ '2024-08-01 13:06:58+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'a8a06a20-b651-546b-86bc-f39e521fd7a9'::uuid,'accepted',TIMESTAMPTZ '2024-08-01 15:36:40+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'65d4d6f6-876e-5499-96aa-c13ef73ef961'::uuid,'accepted',TIMESTAMPTZ '2024-08-06 19:50:16+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2024-08-08 21:58:26+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2024-08-18 20:52:29+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'8a8dd6e0-80b7-5721-8adf-64800605e7a7'::uuid,'accepted',TIMESTAMPTZ '2024-08-21 19:40:13+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-08-22 15:29:53+00'),
  ('0ccd7d9c-d4c7-52aa-b170-a116631f0a42'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2024-08-23 03:11:41+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2024-08-23 03:11:44+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2024-08-23 03:11:47+00'),
  ('e2e4a4c1-3884-5746-934e-5188c7da9165'::uuid,'70849009-16c6-5fd7-a8c0-b08f74708c4a'::uuid,'accepted',TIMESTAMPTZ '2024-08-23 03:11:50+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'93d765e1-f0c8-5e8b-9fd4-5868ccf801bc'::uuid,'accepted',TIMESTAMPTZ '2024-09-01 11:52:04+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 15:32:17+00'),
  ('0bb63e28-a713-5a52-ac6f-fb8108f7f670'::uuid,'0f1dd09e-18ae-58d7-856f-44a65c11a631'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 15:44:48+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'0f1dd09e-18ae-58d7-856f-44a65c11a631'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 15:44:53+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'0f1dd09e-18ae-58d7-856f-44a65c11a631'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 15:45:01+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'4c5deca3-9790-5762-a3d1-84e9e71503bc'::uuid,'accepted',TIMESTAMPTZ '2024-09-05 16:08:02+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'accepted',TIMESTAMPTZ '2024-09-06 00:46:23+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'600371c4-22ba-5a83-be06-c31fd1b11a1c'::uuid,'accepted',TIMESTAMPTZ '2024-09-09 22:09:00+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'fd7dac02-fc95-5b92-b6a8-4724b4278137'::uuid,'accepted',TIMESTAMPTZ '2024-09-10 23:31:02+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 02:11:51+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'15c2e9c5-76ad-5b5a-a43c-9115ea8c52cd'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 02:17:19+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 18:10:52+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'3ebab183-ff0a-587c-a891-85b8d4340ae6'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 18:23:01+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'db86da78-29b9-52e1-a50a-9ebe90d2f107'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 18:35:19+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 18:45:23+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 19:04:37+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 19:04:39+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'db7529b5-2567-5d27-897a-82023665cb24'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 20:36:42+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'bb452d85-331f-5d6c-9597-1ebd00dcc8ad'::uuid,'accepted',TIMESTAMPTZ '2024-09-11 21:49:13+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'35ed23a9-5ab3-50e0-bc3a-eb2c797fc9a9'::uuid,'accepted',TIMESTAMPTZ '2024-09-12 15:29:26+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'5c942081-9638-53fe-bfdd-38249d93311f'::uuid,'accepted',TIMESTAMPTZ '2024-09-12 16:05:44+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'9c315275-d3b3-58da-931b-d95848133b10'::uuid,'accepted',TIMESTAMPTZ '2024-09-12 22:51:16+00'),
  ('31eefd65-9883-5fcc-a0a3-155e4841a90e'::uuid,'bbf3626d-f75a-59d4-9f3e-865d255b6152'::uuid,'accepted',TIMESTAMPTZ '2024-09-13 11:28:09+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'5552764f-fe74-542a-bd55-cb6e858923d7'::uuid,'accepted',TIMESTAMPTZ '2024-09-14 09:24:07+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'1d4aee3c-715c-5c35-acb1-1b1f37311c27'::uuid,'accepted',TIMESTAMPTZ '2024-09-14 13:38:13+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2024-09-16 16:52:55+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'accepted',TIMESTAMPTZ '2024-09-17 07:17:45+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'1074982c-f5a9-5285-904b-a7ca9f735e2f'::uuid,'accepted',TIMESTAMPTZ '2024-09-18 23:00:36+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'5350f400-052f-5f01-80ff-9d5e9a57c8fe'::uuid,'accepted',TIMESTAMPTZ '2024-09-19 00:55:51+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid,'accepted',TIMESTAMPTZ '2024-09-24 01:49:49+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid,'accepted',TIMESTAMPTZ '2024-09-24 01:49:52+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid,'accepted',TIMESTAMPTZ '2024-09-24 01:49:56+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'5658cc50-9f70-56f5-a086-1dbb955aec70'::uuid,'accepted',TIMESTAMPTZ '2024-09-25 21:03:54+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'f287ac86-0557-545a-9481-4f4ad1fcabb8'::uuid,'accepted',TIMESTAMPTZ '2024-09-26 02:46:12+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2024-09-29 18:43:41+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2024-09-29 18:43:42+00'),
  ('673d9302-5c07-526e-aea9-bc3ded273579'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2024-09-29 18:43:44+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2024-10-02 14:27:08+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'3aa726b4-2e73-59e9-9741-0894df80837a'::uuid,'accepted',TIMESTAMPTZ '2024-10-12 16:49:20+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2024-10-16 01:37:41+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2024-10-16 01:37:44+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2024-10-16 01:37:49+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'accepted',TIMESTAMPTZ '2024-10-21 22:20:46+00'),
  ('2cddf5ac-024a-5664-8f48-99572433a9e5'::uuid,'b3a4e441-d1fa-51ab-a6aa-a8b38959faf6'::uuid,'accepted',TIMESTAMPTZ '2024-10-22 00:33:18+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'accepted',TIMESTAMPTZ '2024-11-05 20:13:36+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid,'accepted',TIMESTAMPTZ '2024-11-10 19:56:54+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid,'accepted',TIMESTAMPTZ '2024-11-10 19:56:55+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid,'accepted',TIMESTAMPTZ '2024-11-10 19:56:56+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'31f23a76-0ca9-5d8d-9c35-3f51a57a7621'::uuid,'accepted',TIMESTAMPTZ '2024-11-10 19:56:58+00'),
  ('ddf77a3f-fd93-569e-b8b5-a939a027c3de'::uuid,'aa86ec13-cda4-5710-ae29-8d9a80e36daa'::uuid,'accepted',TIMESTAMPTZ '2024-11-11 20:08:37+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'aa86ec13-cda4-5710-ae29-8d9a80e36daa'::uuid,'accepted',TIMESTAMPTZ '2024-11-11 20:08:50+00'),
  ('cd3fd2a2-2c64-5da1-a36f-391be928e24a'::uuid,'aa86ec13-cda4-5710-ae29-8d9a80e36daa'::uuid,'accepted',TIMESTAMPTZ '2024-11-11 20:08:52+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2024-11-12 14:14:21+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'3a8ab839-f37f-5dfe-a821-6df477f9bdd5'::uuid,'accepted',TIMESTAMPTZ '2024-11-21 08:55:42+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'accepted',TIMESTAMPTZ '2024-11-26 14:25:14+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-12-04 10:00:55+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-12-04 10:00:57+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-12-04 10:01:00+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-12-04 10:01:02+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'2467936f-4354-5825-a17b-f267a93d5ba8'::uuid,'accepted',TIMESTAMPTZ '2024-12-04 10:01:33+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:44:50+00'),
  ('aa03e54b-4921-5001-abbe-1938b8664deb'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:45:01+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:45:10+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:45:17+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'accepted',TIMESTAMPTZ '2024-12-07 14:45:19+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'bc84d43a-9d4b-5a85-b63a-5ff3c14a7340'::uuid,'accepted',TIMESTAMPTZ '2024-12-09 02:56:35+00'),
  ('309bcb16-6b53-524c-9bd1-1eb76957be4d'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2024-12-18 02:23:34+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2024-12-18 02:23:34+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2024-12-18 02:23:35+00'),
  ('aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'65d4d6f6-876e-5499-96aa-c13ef73ef961'::uuid,'accepted',TIMESTAMPTZ '2024-12-18 22:57:38+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'5eb0982d-9ceb-5a65-9b27-33a5fba3f525'::uuid,'accepted',TIMESTAMPTZ '2024-12-20 16:25:09+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'5eb0982d-9ceb-5a65-9b27-33a5fba3f525'::uuid,'accepted',TIMESTAMPTZ '2024-12-20 16:25:10+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'5eb0982d-9ceb-5a65-9b27-33a5fba3f525'::uuid,'accepted',TIMESTAMPTZ '2024-12-20 16:25:13+00'),
  ('7bace71d-82c5-5910-8f21-fcd738c14438'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2024-12-23 16:35:42+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'5658cc50-9f70-56f5-a086-1dbb955aec70'::uuid,'accepted',TIMESTAMPTZ '2024-12-24 18:47:05+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'b421257c-75fb-53fd-9f35-7737e0252245'::uuid,'accepted',TIMESTAMPTZ '2025-01-05 23:17:30+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'e270c326-2bd0-5010-8318-c707fca5d3dd'::uuid,'accepted',TIMESTAMPTZ '2025-01-06 03:27:48+00'),
  ('5e113d10-4928-5a5a-aa0d-2aee5332c4cb'::uuid,'8cf70cb8-3b65-5f77-8d09-7037471d622e'::uuid,'accepted',TIMESTAMPTZ '2025-01-11 01:24:55+00'),
  ('1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'3f611e1d-1b38-5030-963b-88ccf1d4762b'::uuid,'accepted',TIMESTAMPTZ '2025-01-12 19:57:35+00'),
  ('aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'a097f115-1402-5f68-81ac-28b1eb179e04'::uuid,'accepted',TIMESTAMPTZ '2025-01-19 20:52:30+00'),
  ('70e12e6c-98e3-57b2-8975-4d3156a3d327'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2025-01-19 22:39:49+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'77a7061b-4ba9-5e91-9d02-cb4e4a82f659'::uuid,'accepted',TIMESTAMPTZ '2025-01-20 00:28:38+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2025-01-20 15:22:19+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'d598c175-352c-521c-8d71-f98dfaaf7edf'::uuid,'accepted',TIMESTAMPTZ '2025-01-20 15:22:22+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid,'accepted',TIMESTAMPTZ '2025-01-23 02:06:55+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid,'accepted',TIMESTAMPTZ '2025-01-23 02:06:57+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid,'accepted',TIMESTAMPTZ '2025-01-23 02:06:59+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'82dcb617-3891-50ef-ae09-226c95f5f3c4'::uuid,'accepted',TIMESTAMPTZ '2025-01-23 12:31:59+00'),
  ('aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'ff66d6e3-7aaa-5cbd-aa84-d0accf92a9e0'::uuid,'accepted',TIMESTAMPTZ '2025-01-24 22:21:28+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'96c6605d-223b-53d8-b401-4c99b6e59484'::uuid,'accepted',TIMESTAMPTZ '2025-01-28 19:27:31+00'),
  ('b5150aed-1a2f-5a90-b2b4-4f31c3540f2e'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2025-01-31 16:17:30+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2025-01-31 16:17:31+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'1f2f0a42-7e2c-5047-a87b-0195d6ca1781'::uuid,'accepted',TIMESTAMPTZ '2025-02-03 01:03:57+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'e8551614-0690-52dd-8033-df5b7ec04e93'::uuid,'accepted',TIMESTAMPTZ '2025-02-06 18:17:03+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'dda9852d-0b10-5750-b53d-3e122bb057a1'::uuid,'accepted',TIMESTAMPTZ '2025-02-14 17:35:10+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2025-02-14 18:56:09+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'1b42cfe1-1395-5082-998c-d69a0750010e'::uuid,'accepted',TIMESTAMPTZ '2025-02-14 18:56:10+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'c53b758e-441f-5daa-906f-6d8d289c4c6b'::uuid,'accepted',TIMESTAMPTZ '2025-02-19 21:01:20+00'),
  ('811d7375-cc15-5c9e-b7b1-f87f55c7a5e2'::uuid,'986772ad-f998-5d89-8aa2-c94e9092deea'::uuid,'accepted',TIMESTAMPTZ '2025-04-12 12:44:13+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'5658cc50-9f70-56f5-a086-1dbb955aec70'::uuid,'accepted',TIMESTAMPTZ '2025-05-14 20:36:02+00'),
  ('44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'c5614ddc-d06d-551c-bd74-68ab41269d66'::uuid,'accepted',TIMESTAMPTZ '2025-05-16 21:26:10+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'2b60932e-bf91-56b4-bcd1-f0cf0880f5ff'::uuid,'accepted',TIMESTAMPTZ '2025-06-05 12:30:56+00'),
  ('1ccc5742-6683-5ce4-a41b-48882b0e481d'::uuid,'15b93ba7-d515-5aa5-90b2-7d217e303ed8'::uuid,'accepted',TIMESTAMPTZ '2025-06-13 08:05:27+00'),
  ('aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'bbf3626d-f75a-59d4-9f3e-865d255b6152'::uuid,'accepted',TIMESTAMPTZ '2025-06-13 10:47:44+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'8f803178-3808-5a98-95f8-d125da7362b7'::uuid,'accepted',TIMESTAMPTZ '2025-06-17 12:01:48+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:25+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:29+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:30+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:33+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:35+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'aa7c41b6-7c70-5bcc-85c2-bd2be9cd07e7'::uuid,'accepted',TIMESTAMPTZ '2025-06-30 16:15:37+00'),
  ('bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'44b6abec-a458-51a8-a0e4-30b278645e16'::uuid,'accepted',TIMESTAMPTZ '2025-07-09 22:16:50+00'),
  ('1576c976-9024-5636-8b0a-3a75dcb4a0de'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2025-07-10 14:02:00+00'),
  ('d1dcd4ae-3f31-5ff8-b1fa-c15074fdefec'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2025-07-10 14:02:02+00'),
  ('c762d0fa-e320-5f92-9b76-b0c5dba479f4'::uuid,'bdf872ea-7e1e-5334-af7c-2ff76cdd8d46'::uuid,'accepted',TIMESTAMPTZ '2025-07-10 14:02:03+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid,'accepted',TIMESTAMPTZ '2025-07-16 14:57:03+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid,'accepted',TIMESTAMPTZ '2025-07-16 14:57:06+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid,'accepted',TIMESTAMPTZ '2025-07-16 14:57:08+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'f5d300c2-b19a-55a9-a845-cdb5fdbc3885'::uuid,'accepted',TIMESTAMPTZ '2025-07-16 14:57:09+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'accepted',TIMESTAMPTZ '2025-07-28 11:29:33+00'),
  ('74cd75bc-0ba3-551f-bfe1-62e1b27472af'::uuid,'44437c81-4f0e-5d15-951f-96235faa23bc'::uuid,'accepted',TIMESTAMPTZ '2025-07-28 11:29:34+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2025-08-01 22:50:01+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2025-08-01 22:50:02+00'),
  ('143a8a92-c39c-5d8b-8891-d76d612071b1'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2025-08-01 22:50:03+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2025-08-01 22:50:08+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'fed1b29d-4713-533b-8edf-965ebc13fee6'::uuid,'accepted',TIMESTAMPTZ '2025-08-01 22:50:09+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'438db892-f2f5-5d42-b2b0-1aa01b168183'::uuid,'accepted',TIMESTAMPTZ '2025-08-18 21:48:03+00'),
  ('32a9364c-e54d-503d-8591-6120190936e8'::uuid,'32511023-a82b-57b5-894f-c774ffec2ecc'::uuid,'accepted',TIMESTAMPTZ '2025-09-28 15:47:38+00'),
  ('aa77a125-efc2-5db7-8177-65c2e6abbd65'::uuid,'9100eb89-f02b-5ead-b9e8-4ccaa3de0b3a'::uuid,'accepted',TIMESTAMPTZ '2025-10-23 01:47:10+00'),
  ('4d8bc072-b862-53c8-8087-33912a0dedf9'::uuid,'c00bdccb-6430-5699-916c-2b59639e74a7'::uuid,'accepted',TIMESTAMPTZ '2025-11-03 14:55:15+00'),
  ('6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'::uuid,'07a5e7ea-4325-59f4-bb3c-350e6271bbbb'::uuid,'accepted',TIMESTAMPTZ '2025-12-15 23:40:34+00'),
  ('c96fae45-fa27-57de-8f83-0df076bfb1a1'::uuid,'07a5e7ea-4325-59f4-bb3c-350e6271bbbb'::uuid,'accepted',TIMESTAMPTZ '2025-12-15 23:40:45+00'),
  ('0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'07a5e7ea-4325-59f4-bb3c-350e6271bbbb'::uuid,'accepted',TIMESTAMPTZ '2025-12-15 23:40:52+00'),
  ('423c58c3-13c7-57f5-8901-e6ab55877c98'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2026-04-07 16:17:18+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'5c4eb4a4-0110-563d-86bd-35dcaee1f9e2'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 02:32:58+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'4d8c8807-ca62-5327-8902-426896ebdc3f'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 03:14:12+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'694a7282-5016-5bac-99e2-3659cdafc2b2'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 12:14:48+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'70e12e6c-98e3-57b2-8975-4d3156a3d327'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 17:16:13+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2026-04-25 17:35:28+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'88d360cf-3429-5c8d-aac5-d28e3ec321bb'::uuid,'accepted',TIMESTAMPTZ '2026-04-26 02:17:34+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 17:16:10+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'6245b881-9a78-5a94-a2b6-4eecc436a3f4'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 18:38:03+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'2a13edbd-0c15-535b-ac23-fdac42842032'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 19:04:38+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'af1a3ad6-eb7a-5ce8-a002-501a266dc03e'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 20:14:37+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'e407384e-ba10-5585-8f33-7314fc80ca07'::uuid,'accepted',TIMESTAMPTZ '2026-04-27 21:48:20+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'3383f083-3447-552d-94d2-4a3f01b5f6d3'::uuid,'accepted',TIMESTAMPTZ '2026-04-28 01:51:00+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'e7606cd8-cc38-5ec4-acb3-360fda5d4058'::uuid,'accepted',TIMESTAMPTZ '2026-04-28 10:13:59+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'36823350-aacf-59ba-ae91-4e803bca7316'::uuid,'accepted',TIMESTAMPTZ '2026-04-28 22:13:04+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'48029ada-2c33-57b8-abf5-9f2acae7d783'::uuid,'accepted',TIMESTAMPTZ '2026-04-29 05:03:48+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'414e4dd8-9dc9-52f0-8d36-fb73b9d2193d'::uuid,'accepted',TIMESTAMPTZ '2026-04-29 11:52:05+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'32b8620c-836e-5e65-9341-bfc3222188d5'::uuid,'accepted',TIMESTAMPTZ '2026-04-29 12:46:16+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'a017cf95-224c-57dd-a392-641af3a0a81c'::uuid,'accepted',TIMESTAMPTZ '2026-05-01 01:28:18+00'),
  ('fb341eef-be46-5383-9a46-38896f1bfd84'::uuid,'824f1c06-26a5-5c50-9769-0ea90370a2ee'::uuid,'accepted',TIMESTAMPTZ '2026-05-08 13:00:23+00'),
  ('fb341eef-be46-5383-9a46-38896f1bfd84'::uuid,'99d519ed-846c-5295-8d47-44a74537f8f8'::uuid,'accepted',TIMESTAMPTZ '2026-05-08 14:14:09+00'),
  ('fb341eef-be46-5383-9a46-38896f1bfd84'::uuid,'0b017330-f4da-5283-9771-b16cfb7b0c44'::uuid,'accepted',TIMESTAMPTZ '2026-05-08 14:56:15+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'e16b00d9-f8d0-541a-82e7-6bd62d1fd32c'::uuid,'accepted',TIMESTAMPTZ '2026-05-09 13:30:58+00'),
  ('79b93b9e-91cf-5bb9-bdc6-827e298e82a7'::uuid,'afeffd27-9d3d-573b-b85a-f86010bf2e9c'::uuid,'accepted',TIMESTAMPTZ '2026-05-11 02:04:01+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'a4812a0c-0f5f-50d0-ace5-f4cb5ce9829d'::uuid,'accepted',TIMESTAMPTZ '2026-05-11 04:37:47+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'467851bb-fe6d-5663-ba9d-6bf75de50d7d'::uuid,'accepted',TIMESTAMPTZ '2026-05-12 15:36:52+00'),
  ('3d8ddd3c-dc05-59ae-8a19-06c5f09328bc'::uuid,'e89b2784-15f1-593d-9278-7a71eb0a229b'::uuid,'accepted',TIMESTAMPTZ '2026-05-14 22:22:19+00'),
  ('803d6ead-3a10-55b1-8529-b480718147e7'::uuid,'b1ecdde3-3ae3-5d29-adbd-f6930ec1e4b3'::uuid,'accepted',TIMESTAMPTZ '2026-05-19 00:21:25+00'),
  ('fb341eef-be46-5383-9a46-38896f1bfd84'::uuid,'e66eae15-de44-523b-92e8-c6ac7f38a265'::uuid,'accepted',TIMESTAMPTZ '2026-05-23 12:51:42+00'),
  ('af1a3ad6-eb7a-5ce8-a002-501a266dc03e'::uuid,'ac29e86c-a19c-5458-999d-f1fd42102e1a'::uuid,'accepted',TIMESTAMPTZ '2026-05-27 17:55:25+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'626b0e10-031a-58e5-baf5-0214f3028f8f'::uuid,'accepted',TIMESTAMPTZ '2026-06-01 16:07:42+00'),
  ('b1acf0ea-552a-5338-8a4b-f42cf18d107b'::uuid,'c269b5ca-37ac-5e0a-a17d-caf25eafbaef'::uuid,'accepted',TIMESTAMPTZ '2026-06-02 03:33:02+00')
;

DO $$ DECLARE n int; bad int; miss int; u uuid; BEGIN
  SELECT count(*) INTO n FROM payload;
  IF n <> 271 THEN RAISE EXCEPTION 'payload is % rows, expected 271 - file is truncated, ABORTING', n; END IF;

  SELECT count(*) INTO bad FROM payload WHERE status <> 'accepted';
  IF bad > 0 THEN RAISE EXCEPTION '% non-accepted rows in an ACCEPTED-ONLY file - ABORTING', bad; END IF;

  SELECT count(*) INTO bad FROM payload WHERE requester_uuid = addressee_uuid;
  IF bad > 0 THEN RAISE EXCEPTION '% self-pairs in payload - ABORTING', bad; END IF;

  SELECT us.uuid INTO u FROM public.users us
    JOIN public.wp_user_bridge b ON b.user_id = us.id WHERE b.wp_user_id = 1;
  IF u IS NULL THEN RAISE EXCEPTION 'no wp_user_bridge row for wp_user_id=1 - wrong database, ABORTING'; END IF;
  IF u <> 'f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid THEN
    RAISE EXCEPTION 'bridge says WP user 1 is %, payload was built for f20ad778-1e5e-5508-853b-ad928c499f2f - wrong database, ABORTING', u;
  END IF;

  SELECT count(*) INTO miss FROM (
    SELECT requester_uuid AS x FROM payload UNION SELECT addressee_uuid FROM payload) s
   WHERE s.x NOT IN (SELECT uuid FROM public.users);
  IF miss > 0 THEN RAISE EXCEPTION '% payload uuids do not exist in users - wrong database, ABORTING', miss; END IF;
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
INSERT INTO public.connections_restore_20260728_all_acc (connection_id) SELECT id FROM ins;

SELECT 'rows inserted and tagged (cumulative)' AS what, count(*)::text AS n
  FROM public.connections_restore_20260728_all_acc;

SELECT 'pending rows created by this file' AS what, '0 - every payload row is accepted' AS n;

COMMIT;
