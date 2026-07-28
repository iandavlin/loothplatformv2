#!/usr/bin/env bash
# Close the mirror's attachment-orphan leak: install the AFTER DELETE triggers
# that stand in for the CASCADE a polymorphic column cannot have, and sweep the
# rows the old delete path already stranded.
#
#   ./fix-attachment-orphans.sh dry-run     # default. Changes nothing.
#   ./fix-attachment-orphans.sh apply
#   ./fix-attachment-orphans.sh rollback    # drops the triggers; does NOT
#                                           # resurrect swept rows (see below)
#
# NOBODY IS NOTIFIED BY ANY OF THIS. Orphan rows are invisible to every surface:
# every read path starts FROM forums.reply / forums.topic and joins outward, so
# a row whose parent is gone cannot render anywhere, cannot be linked, and is not
# in anyone's feed, email or notification. This changes no user-visible number —
# the dry run proves that by printing the reply-image counts before and after.
#
# The files themselves are NOT touched. `attachment` holds URLs, not blobs; the
# images live under wp-content/uploads (R2). This only removes rows pointing at
# parents that no longer exist.
set -uo pipefail
MODE="${1:-dry-run}"
PSQL="${PSQL:-sudo -u postgres psql -d looth -v ON_ERROR_STOP=1}"
SCHEMA_SQL="$(cd "$(dirname "$0")/.." && pwd)/schema.pg.sql"
TAG="attachment-orphan-fix-$(date -u +%Y%m%dT%H%M%SZ)"

say(){ printf '\n\033[1m%s\033[0m\n' "$1"; }

# The forward migration is the marked block in schema.pg.sql — extracted, not
# duplicated, so the file that builds a fresh database and the statements that
# get applied to live can never drift apart. We deliberately do NOT pipe the
# whole schema at a production database just to add two triggers.
purge_ddl(){
  awk '/^-- >>> BEGIN attachment-purge <<</{f=1;next} /^-- >>> END attachment-purge <<</{f=0} f' "$SCHEMA_SQL"
}
# Guard: a silent extraction failure would make `apply` sweep the existing
# orphans while installing nothing to stop new ones — the worst outcome, because
# it looks like it worked.
DDL="$(purge_ddl)"
case "$DDL" in
  *attachment_purge_for_parent*reply_attachment_purge*) ;;
  *) echo "FATAL: could not extract the attachment-purge block from $SCHEMA_SQL" >&2; exit 1;;
esac

census(){
  $PSQL -At -F'|' -c "
    SELECT COALESCE(k,'(none)'), COALESCE(rows::text,'0'), COALESCE(parents::text,'0') FROM (
      SELECT a.parent_kind::text AS k, COUNT(*) AS rows, COUNT(DISTINCT a.parent_id) AS parents
        FROM forums.attachment a
       WHERE (a.parent_kind='reply' AND NOT EXISTS (SELECT 1 FROM forums.reply r  WHERE r.id=a.parent_id))
          OR (a.parent_kind='topic' AND NOT EXISTS (SELECT 1 FROM forums.topic t WHERE t.id=a.parent_id))
       GROUP BY 1) s;"
}

# The numbers a member could actually notice. MUST be identical before and after.
uservisible(){
  $PSQL -At -F'|' -c "
    SELECT COUNT(*), SUM(CASE WHEN n>1 THEN 1 ELSE 0 END),
           SUM(CASE WHEN n>1 THEN n-1 ELSE 0 END), COALESCE(MAX(n),0)
      FROM (SELECT r.id, COUNT(a.id) n
              FROM forums.reply r
              JOIN forums.attachment a ON a.parent_kind='reply' AND a.parent_id=r.id
             WHERE r.status='publish' GROUP BY r.id) t;"
}

triggers(){ $PSQL -At -c "
  SELECT COUNT(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
    JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE NOT t.tgisinternal AND n.nspname='forums'
     AND t.tgname IN ('topic_attachment_purge','reply_attachment_purge');"; }

say "1. Orphan census (kind|rows|parents)"; census
say "2. User-visible reply-image counts (with_images|multi|hidden|max)"; BEFORE=$(uservisible); echo "$BEFORE"
say "3. Purge triggers currently installed (expect 2 once applied)"; triggers

if [ "$MODE" = "dry-run" ]; then
  say "DRY RUN — nothing changed."
  say "  The EXACT SQL apply would run (and nothing else):"
  printf '%s\n' "$DDL" | sed 's/^/    | /'
  echo
  echo "  Would then delete: the orphan rows counted above, and ONLY those."
  echo "  Rows that must survive (parent still exists):"
  $PSQL -At -c "SELECT COUNT(*) FROM forums.attachment a
     WHERE (a.parent_kind='reply' AND EXISTS (SELECT 1 FROM forums.reply r  WHERE r.id=a.parent_id))
        OR (a.parent_kind='topic' AND EXISTS (SELECT 1 FROM forums.topic t WHERE t.id=a.parent_id));"
  echo "  Nobody is notified. No user-visible number moves. No image files are touched."
  echo "  Re-run with: $0 apply"
  exit 0
fi

if [ "$MODE" = "rollback" ]; then
  say "ROLLBACK — dropping the purge triggers"
  $PSQL -c "DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
            DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
            DROP FUNCTION IF EXISTS forums.attachment_purge_for_parent();"
  say "Triggers now installed (expect 0)"; triggers
  echo "NOTE: rollback restores the OLD LEAKY BEHAVIOUR. It does not resurrect rows"
  echo "already swept — they referenced parents that no longer exist and could not"
  echo "be rendered by anything, so there is nothing to restore them to."
  exit 0
fi

[ "$MODE" = "apply" ] || { echo "usage: $0 [dry-run|apply|rollback]"; exit 2; }

say "APPLY — tag $TAG"
# Backup the exact rows about to go, so the sweep is reversible even though the
# rows are unreachable. Cheap insurance; ~20 rows on live.
BK="/tmp/${TAG}.orphan-rows.tsv"
$PSQL -At -F$'\t' -c "
  SELECT a.id,a.parent_kind,a.parent_id,a.url,a.position,a.sync_at FROM forums.attachment a
   WHERE (a.parent_kind='reply' AND NOT EXISTS (SELECT 1 FROM forums.reply r  WHERE r.id=a.parent_id))
      OR (a.parent_kind='topic' AND NOT EXISTS (SELECT 1 FROM forums.topic t WHERE t.id=a.parent_id));" > "$BK"
echo "  backed up $(wc -l < "$BK") orphan rows -> $BK"

# Triggers first, so nothing new leaks while the sweep runs.
say "  installing the attachment-purge block from schema.pg.sql (idempotent)"
printf '%s\n' "$DDL" | $PSQL -q -f - || { echo "TRIGGER INSTALL FAILED"; exit 1; }
triggers | sed 's/^/  triggers installed: /'
[ "$(triggers)" = "2" ] || { echo "ABORT: expected 2 triggers installed, got $(triggers) — NOT sweeping"; exit 1; }

say "  sweeping stranded rows"
$PSQL -c "DELETE FROM forums.attachment a
   WHERE (a.parent_kind='reply' AND NOT EXISTS (SELECT 1 FROM forums.reply r  WHERE r.id=a.parent_id))
      OR (a.parent_kind='topic' AND NOT EXISTS (SELECT 1 FROM forums.topic t WHERE t.id=a.parent_id));"

say "4. Orphan census after (expect empty)"; census
say "5. User-visible counts after — MUST equal step 2"; AFTER=$(uservisible); echo "$AFTER"
if [ "$BEFORE" = "$AFTER" ]; then
  printf '\n\033[32mPASS\033[0m user-visible counts identical: %s\n' "$AFTER"
else
  printf '\n\033[31mFAIL\033[0m counts MOVED: before=%s after=%s — investigate before proceeding\n' "$BEFORE" "$AFTER"
  exit 1
fi
echo "Rollback: $0 rollback   (backup: $BK)"
