-- ============================================================
-- CANARY 6 of 6 — ROLLBACK, IAN ONLY.  Undoes file 5 exactly.
--
-- Deletes by PRIMARY KEY, using the tag table written during the canary
-- APPLY. It cannot touch a row this canary did not create: no date window,
-- no pair matching, no guessing. If Ian (or the other party) has since
-- deleted one of the restored connections, ON DELETE CASCADE already
-- removed its tag, so this will not error and will not resurrect it.
--
-- This rolls back the CANARY ONLY. If the full set (file 2) has also been
-- applied, its rows live in connections_restore_20260727 and are removed by
-- file 3, not by this one.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

SELECT 'canary rows about to be deleted' AS what, count(*)::text AS n FROM public.connections_restore_20260727_ian;

DELETE FROM public.connections c
USING public.connections_restore_20260727_ian t
WHERE c.id = t.connection_id;

DROP TABLE public.connections_restore_20260727_ian;

SELECT status, count(*) AS ian_after_rollback
  FROM public.connections c
 WHERE c.requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid OR c.addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid
 GROUP BY status ORDER BY status;

COMMIT;
