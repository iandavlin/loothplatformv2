-- ============================================================================
-- ROLLBACK for 2026-07-28-attachment-purge-live.sql
--
-- RUN AS:  sudo -u bb-mirror psql -d looth -v ON_ERROR_STOP=1 -f <this file>
--          (see the forward migration for why the role must be bb-mirror)
--
-- Safe at any time. Safe to run twice — a second run only prints "skipping".
-- No data dependency: it removes behaviour, not rows.
--
-- EXERCISED, NOT ASSERTED. On a replica of live's exact state: after running
-- this, deleting a six-image reply stranded all 6 attachment rows again. The
-- leak genuinely returns, which is what makes this a real rollback.
--
-- WHAT IT DOES NOT DO: resurrect the rows the forward migration swept. Those
-- rows referenced parents that no longer exist — nothing could render, link or
-- send them. The forward run's step 2 dumps them to
-- /tmp/orphan-rows-backup.tsv first if they are ever wanted for forensics.
--
-- Running this restores the OLD LEAKY BEHAVIOUR: every subsequent topic/reply
-- delete will again strand its attachment rows, up to six per reply since
-- galleries shipped at 6ef25e3.
-- ============================================================================

DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
DROP FUNCTION IF EXISTS forums.attachment_purge_for_parent();
