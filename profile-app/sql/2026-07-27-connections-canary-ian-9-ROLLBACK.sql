-- ============================================================
-- CANARY 9 of 9 — ROLLBACK, IAN ONLY, ACCEPTED ONLY.  Undoes file 8 exactly.
--
-- Deletes by PRIMARY KEY via the tag table file 8 wrote. It cannot touch a row
-- this set did not create: no date window, no pair matching, no guessing. If a
-- connection has since been deleted by either party, ON DELETE CASCADE already
-- removed its tag, so this will not error and will not resurrect it.
--
-- This rolls back the ACCEPTED-ONLY set and nothing else. The 4/5/6 canary
-- (connections_restore_20260727_ian) is undone by file 6; the full 746-row set
-- (connections_restore_20260727) is undone by file 3.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

SELECT 'accepted-canary rows about to be deleted' AS what, count(*)::text AS n FROM public.connections_restore_20260727_ian_acc;

DELETE FROM public.connections c
USING public.connections_restore_20260727_ian_acc t
WHERE c.id = t.connection_id;

DROP TABLE public.connections_restore_20260727_ian_acc;

SELECT status, count(*) AS ian_after_rollback
  FROM public.connections c
 WHERE c.requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid OR c.addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid
 GROUP BY status ORDER BY status;

COMMIT;
