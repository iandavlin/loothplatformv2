-- ============================================================
-- POST-APPLY VERIFICATION for both connections sets.  READ ONLY.
--
-- The step-3 dry runs already prove each set landed (WILL INSERT 0 / WILL FLIP
-- 0). This checks the things a dry run CANNOT see:
--   * no reciprocal duplicate was created
--   * original created_at survived the insert
--   * the two tag tables do not overlap, so the rollbacks stay independent
--   * Ian's totals moved by exactly the predicted amount
--
-- Safe to run before, between, or after the applies -- it reports what is
-- present rather than assuming anything has run.
--
--   psql -h 127.0.0.1 -U looth_ro -d profile_app -f POST-APPLY-CHECK.sql
-- ============================================================
\set ON_ERROR_STOP on

DO $$
DECLARE
  a_tag  CONSTANT text := 'public.connections_restore_20260728_all_acc';
  b_tag  CONSTANT text := 'public.connections_restatus_20260728';
  ian    CONSTANT uuid := 'f20ad778-1e5e-5508-853b-ad928c499f2f';
  a_on boolean; b_on boolean; n int; m int; rng text;
BEGIN
  a_on := to_regclass(a_tag) IS NOT NULL;
  b_on := to_regclass(b_tag) IS NOT NULL;

  RAISE NOTICE '--- SET A (13/14/15, restore 271) ---';
  IF NOT a_on THEN
    RAISE NOTICE 'NOT APPLIED (tag table absent)';
  ELSE
    EXECUTE 'SELECT count(*) FROM '||a_tag INTO n;
    RAISE NOTICE 'rows tagged                      : %   (expect 271)', n;

    EXECUTE 'SELECT count(*) FROM public.connections c JOIN '||a_tag||
            ' t ON t.connection_id=c.id WHERE c.status <> ''accepted''' INTO n;
    RAISE NOTICE 'tagged rows NOT accepted         : %   (expect 0)', n;

    -- the restore inserts historical rows; anything stamped today means
    -- created_at was not preserved
    EXECUTE 'SELECT count(*) FROM public.connections c JOIN '||a_tag||
            ' t ON t.connection_id=c.id WHERE c.created_at > ''2026-07-01''' INTO n;
    RAISE NOTICE 'tagged rows with a NEW created_at: %   (expect 0)', n;

    EXECUTE 'SELECT min(c.created_at)::date::text || '' -> '' || max(c.created_at)::date::text
             FROM public.connections c JOIN '||a_tag||' t ON t.connection_id=c.id' INTO rng;
    RAISE NOTICE 'date range of restored rows      : %   (expect 2023-06-20 -> 2026-06-02)', rng;
  END IF;

  RAISE NOTICE '';
  RAISE NOTICE '--- SET B (10/11/12, correct 81) ---';
  IF NOT b_on THEN
    RAISE NOTICE 'NOT APPLIED (tag table absent)';
  ELSE
    EXECUTE 'SELECT count(*) FROM '||b_tag INTO n;
    RAISE NOTICE 'rows tagged                      : %   (expect 81)', n;

    EXECUTE 'SELECT count(*) FROM public.connections c JOIN '||b_tag||
            ' t ON t.connection_id=c.id WHERE c.status <> ''accepted''' INTO n;
    RAISE NOTICE 'tagged rows NOT now accepted     : %   (expect 0)', n;

    EXECUTE 'SELECT count(*) FROM '||b_tag||' WHERE prior_status <> ''pending''' INTO n;
    RAISE NOTICE 'tagged rows whose prior <> pending: %   (expect 0)', n;
  END IF;

  RAISE NOTICE '';
  RAISE NOTICE '--- CROSS-CHECKS ---';
  IF a_on AND b_on THEN
    EXECUTE 'SELECT count(*) FROM '||a_tag||' a JOIN '||b_tag||
            ' b ON a.connection_id=b.connection_id' INTO n;
    RAISE NOTICE 'tag-table OVERLAP                : %   (expect 0 - the two rollbacks must stay independent)', n;
  ELSE
    RAISE NOTICE 'tag-table overlap                : n/a (both not yet applied)';
  END IF;

  -- live carried 5 bidirectional pairs BEFORE any of this work (10 self-join
  -- matches, the shape-(c) set). Neither set adds or removes one.
  SELECT count(*) INTO n FROM public.connections a
    JOIN public.connections b
      ON a.requester_uuid=b.addressee_uuid AND a.addressee_uuid=b.requester_uuid;
  RAISE NOTICE 'reciprocal duplicate rows        : %   (expect 10 - PRE-EXISTING, 5 pairs.', n;
  RAISE NOTICE '                                       MORE than 10 means something created one.)';

  SELECT count(*) INTO n FROM public.connections
   WHERE status='accepted' AND (requester_uuid=ian OR addressee_uuid=ian);
  SELECT count(*) INTO m FROM public.connections
   WHERE status='pending' AND requester_uuid=ian;
  RAISE NOTICE '';
  RAISE NOTICE 'Ian accepted                     : %', n;
  RAISE NOTICE 'Ian pending OUT                  : %', m;
  RAISE NOTICE '  baseline before either set     : 1334 accepted / 427 pending OUT';
  RAISE NOTICE '  set A moves neither (0 rows touch him)';
  RAISE NOTICE '  set B moves accepted +23 -> 1357, pending OUT -23 -> 404';
END $$;
