-- ============================================================
-- 3 of 3 — ROLLBACK.  Undoes APPLY exactly.
--
-- Deletes by PRIMARY KEY, using the tag table written during APPLY.
-- It cannot touch a row this restore did not create: no date window,
-- no pair matching, no guessing. If a member has since deleted one of
-- the restored connections, ON DELETE CASCADE already removed its tag,
-- so this will not error and will not resurrect it.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

SELECT 'tagged rows about to be deleted' AS what, count(*)::text AS n FROM public.connections_restore_20260727;

DELETE FROM public.connections c
USING public.connections_restore_20260727 t
WHERE c.id = t.connection_id;

DROP TABLE public.connections_restore_20260727;

SELECT status, count(*) AS after_rollback FROM public.connections GROUP BY status ORDER BY status;

COMMIT;
