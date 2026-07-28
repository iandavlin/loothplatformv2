-- ============================================================
-- 12 of 12 — ROLLBACK.  Undoes file 11 exactly, by primary key.
--
-- Restores each row's PRIOR STATUS from the tag table - it does not assume the
-- prior status was 'pending', it replays what was actually recorded. It can only
-- touch rows file 11 changed, and only those still sitting at 'accepted' (so a
-- member who has since acted on the connection is not overridden).
--
-- STATUS is restored exactly. updated_at is NOT and cannot be: the
-- connections_touch trigger stamps now() on every UPDATE. Everything else
-- round-trips.
--
-- This rolls back the re-status fix ONLY. The restore sets have their own tag
-- tables and their own rollbacks (files 3, 6, 9).
-- ============================================================
\set ON_ERROR_STOP on
BEGIN;

SELECT 'rows about to be reverted' AS what, count(*)::text AS n FROM public.connections_restatus_20260728;
SELECT prior_status, count(*) AS will_revert_to FROM public.connections_restatus_20260728 GROUP BY prior_status ORDER BY prior_status;

UPDATE public.connections c
   SET status = t.prior_status
  FROM public.connections_restatus_20260728 t
 WHERE c.id = t.connection_id
   AND c.status = 'accepted';

DROP TABLE public.connections_restatus_20260728;

SELECT status, count(*) AS ian_after_rollback
  FROM public.connections c
 WHERE c.requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid OR c.addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f'::uuid
 GROUP BY status ORDER BY status;

COMMIT;
