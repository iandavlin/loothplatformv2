-- ============================================================================
-- LIVE MIGRATION — close the mirror's attachment-orphan leak
-- 2026-07-28, mirror-delete-orphans lane. Ian runs this; keeper verifies after.
-- Background: docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md
-- ============================================================================
--
-- RUN AS:  sudo -u bb-mirror psql -d looth -v ON_ERROR_STOP=1 -f <this file>
--
-- THE ROLE IS NOT INCIDENTAL:
--   * `ubuntu` is NOT a Postgres role on live — that is the "role ubuntu does
--     not exist" error. Stay the ubuntu shell user; sudo hands psql to bb-mirror.
--   * NOT `postgres`. It is superuser so it would work today, but the objects
--     would then be OWNED BY postgres, and a later re-apply of schema.pg.sql as
--     bb-mirror (how bin/init-db.php runs) fails with
--         ERROR: must be owner of function attachment_purge_for_parent
--     Reproduced deliberately, not assumed.
--   * `bb-mirror` owns the forums schema, all three tables, the enum type and
--     both existing trigger functions on live (checked read-only 2026-07-28).
--
-- ROLLBACK — deploy/2026-07-28-attachment-purge-live-ROLLBACK.sql. Read it
-- BEFORE applying this. It is exercised, not asserted: after running it, a
-- six-image reply delete strands all 6 rows again.
--
-- IDEMPOTENT. Running it twice is safe: the second run reports DELETE 0 and
-- exits 0. It is a single transaction, so a failure applies nothing.
--
-- NOBODY IS NOTIFIED AND NO USER-VISIBLE NUMBER MOVES. Every read path starts
-- FROM forums.reply / forums.topic and joins outward, so a row whose parent is
-- gone cannot render, be linked, or reach a feed, digest or email. No image
-- files are touched — this table stores URLs, not blobs; the files live in R2.
-- ============================================================================

BEGIN;

-- The CASCADE a polymorphic column cannot have. `attachment` is keyed by
-- (parent_kind, parent_id), which cannot be a foreign key, so it never got the
-- ON DELETE CASCADE that reply.topic_id, reply.forum_id and topic.forum_id all
-- have. Every name here is schema-qualified on purpose: plpgsql resolves
-- unqualified names when the function RUNS, against the CALLER's search_path,
-- and a caller without `forums` on its path (e.g. `sudo -u postgres psql`) would
-- otherwise raise `relation "attachment" does not exist` and ABORT the delete.
CREATE OR REPLACE FUNCTION forums.attachment_purge_for_parent() RETURNS trigger AS $$
BEGIN
  -- Matching on BOTH kind and id is load-bearing: topic 100 and reply 100 are
  -- different parents, and a kind-blind delete would take the wrong one's images.
  DELETE FROM forums.attachment
   WHERE parent_kind = TG_ARGV[0]::forums.attachment_parent_kind
     AND parent_id   = OLD.id;
  RETURN OLD;
END $$ LANGUAGE plpgsql;

-- AFTER DELETE ... FOR EACH ROW fires for CASCADED rows too, which is the whole
-- point: on a topic or forum delete Postgres removes the reply rows internally
-- and the application never learns which ones died, so a PHP-side cleanup
-- silently misses the entire subtree.
DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
CREATE TRIGGER topic_attachment_purge
  AFTER DELETE ON forums.topic
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('topic');

DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
CREATE TRIGGER reply_attachment_purge
  AFTER DELETE ON forums.reply
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('reply');

-- Sweep what the old delete path already stranded: 24 rows across 14 lost
-- parents as of 2026-07-28 13:20 UTC. Removes ONLY rows whose parent no longer
-- exists, so no row reachable from forums.reply / forums.topic can be affected.
DELETE FROM forums.attachment a
 WHERE (a.parent_kind='reply' AND NOT EXISTS (SELECT 1 FROM forums.reply  r WHERE r.id = a.parent_id))
    OR (a.parent_kind='topic' AND NOT EXISTS (SELECT 1 FROM forums.topic  t WHERE t.id = a.parent_id));

COMMIT;
