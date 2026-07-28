-- ============================================================
-- 15 of 15 -- ROLLBACK for the accepted-only POPULATION restore (files 13/14).
--
-- Deletes by PRIMARY KEY, read out of this set's own tag table. It cannot touch
-- a row this restore did not create: no date window, no pair matching, no
-- status matching. If a member has since deleted one of these connections, the
-- tag row went with it (ON DELETE CASCADE), so this stays correct.
--
-- It does NOT touch:
--   * connections_restore_20260727_ian_acc  -- Ian's 83, undo with file 9
--   * connections_restatus_20260728         -- the 81 wrong-status, undo with file 12
-- Undoing everything means running the rollbacks separately, by design.
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

SELECT 'rows tagged by this restore, about to be deleted' AS what,
       count(*)::text AS n FROM public.connections_restore_20260728_all_acc;

DELETE FROM public.connections c
 USING public.connections_restore_20260728_all_acc t
 WHERE c.id = t.connection_id;

DROP TABLE public.connections_restore_20260728_all_acc;

SELECT 'rollback complete - tag table dropped' AS what, '' AS n;

COMMIT;
