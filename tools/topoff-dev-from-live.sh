#!/usr/bin/env bash
#
# topoff-dev-from-live.sh — additively top off DEV from LIVE (prod), one direction.
#
#   Pulls content/media that LIVE has but DEV is MISSING, and inserts it.
#   It NEVER updates or deletes existing dev rows, so it cannot clobber dev's
#   conversions or local work. It is the prod->dev half of the workflow only;
#   dev->live publishing goes through the v2 content pipeline, not this.
#
# Why "missing rows only": dev forked from live and both mint their own
# auto-increment IDs, so the two DBs cannot be merged by overwriting. We only
# add rows whose primary key dev does not already have -> zero collisions.
# Surrogate-keyed meta tables (postmeta/usermeta/...) are inserted WITHOUT the
# live meta_id (dev auto-assigns), keyed on the natural parent id.
#
# Usage:
#   topoff-dev-from-live.sh <preview|test|apply> [all|db|bucket]
#
#     preview  read-only. Reports exactly what WOULD be added. Writes nothing.
#     test     does the real DB inserts inside a transaction, then ROLLS BACK.
#              (bucket "test" == dry-run.) Proves it runs clean, persists nothing.
#     apply    backs up dev first, then commits the DB inserts and copies media.
#
#     scope    all (default) | db | bucket
#
set -euo pipefail

CONF=/etc/lg-topoff.conf
sudo test -r "$CONF" || { echo "FATAL: cannot read $CONF" >&2; exit 1; }
# creds are root:www-data 0640; read via sudo so both ubuntu (CLI) and www-data (button via sudo) work
# shellcheck disable=SC1090
source <(sudo cat "$CONF")

MODE="${1:-preview}"
SCOPE="${2:-all}"
case "$MODE"  in preview|test|apply) ;; *) echo "mode must be preview|test|apply" >&2; exit 2;; esac
case "$SCOPE" in all|db|bucket) ;;       *) echo "scope must be all|db|bucket" >&2; exit 2;; esac

# ---- safety guard: dev only. Never let this run against the prod box. -------
HOST="$(hostname)"
if [ "${DEV_DB}" = "wp_loothgroup" ] || echo "$HOST" | grep -qiE '45-223|prod'; then
  echo "FATAL: refusing to run — this looks like the prod target, not dev (host=$HOST, dev_db=$DEV_DB)" >&2
  exit 3
fi

# ---- connections ------------------------------------------------------------
live()     { MYSQL_PWD="$LIVE_PASS" mysql -h "$LIVE_HOST" -u "$LIVE_USER" --connect-timeout=12 -N "$LIVE_DB" "$@"; }
livedump() { MYSQL_PWD="$LIVE_PASS" mysqldump -h "$LIVE_HOST" -u "$LIVE_USER" --single-transaction \
               --no-tablespaces --skip-lock-tables --skip-triggers --no-create-info --complete-insert "$@"; }
devsql()   { sudo mysql -N "$@"; }

echo "== top-off  mode=$MODE  scope=$SCOPE  ($LIVE_DB @ $LIVE_HOST  ->  $DEV_DB @ $HOST) =="
live -e "SELECT 1" >/dev/null || { echo "FATAL: cannot reach live DB (SG/bind/creds?)" >&2; exit 4; }

# ---- compute the delta: ids LIVE has that DEV lacks -------------------------
# comm -23 sortedA sortedB  ->  lines only in A (live).
new_ids() {  # $1 col, $2 table, [$3 live WHERE], [$4 dev WHERE]
  # dev side defaults to ALL rows (WHERE 1) so we never insert a PK dev already
  # has in any form (e.g. dev holds an ID as a revision, live as a real post).
  local lw="${3:-1}" dw="${4:-1}"
  comm -23 <(live -e "SELECT $1 FROM $2 WHERE $lw" | sort) <(devsql "$DEV_DB" -e "SELECT $1 FROM $2 WHERE $dw" | sort)
}
csv() { paste -sd, - ; }   # newline list -> a,b,c   (empty stays empty)

# report_media_collisions <newline-list-of-new-wp_bp_media-ids>
# For each incoming media row, compare its attachment_id's file on live vs dev.
# If dev already holds that ID as a different file, the cover will be wrong/missing.
report_media_collisions() {
  local ids; ids=$(echo "$1" | csv); [ -n "$ids" ] || return 0
  local att; att=$(live -e "SELECT DISTINCT attachment_id FROM wp_bp_media WHERE id IN ($ids) AND attachment_id>0" | sort -u | csv)
  [ -n "$att" ] || return 0
  local hits=0 line
  while IFS=$'\t' read -r aid lf; do
    [ -n "$aid" ] || continue
    local df; df=$(devsql "$DEV_DB" -e "SELECT meta_value FROM wp_postmeta WHERE post_id=$aid AND meta_key='_wp_attached_file' LIMIT 1")
    if [ -n "$df" ] && [ "$df" != "$lf" ]; then
      [ "$hits" -eq 0 ] && echo "   ⚠ attachment ID-collisions (cover will be WRONG — live file vs dev file):"
      printf '       att %-7s live=%s  dev=%s\n' "$aid" "$lf" "$df"; hits=$((hits+1))
    fi
  done < <(live -e "SELECT p.ID, m.meta_value FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file' WHERE p.ID IN ($att)")
  [ "$hits" -gt 0 ] && echo "   ($hits collision(s) — these covers need a manual re-import under a fresh ID.)"
  return 0
}

run_db() {
  echo "-- scanning DB delta --"
  local NP NU NT NTT NC
  NP=$(new_ids ID wp_posts "post_type NOT IN (${POST_TYPE_DENY})"); local nP; nP=$(echo -n "$NP" | grep -c . || true)
  NU=$(new_ids ID wp_users);              local nU;  nU=$(echo -n "$NU"  | grep -c . || true)
  NT=$(new_ids term_id wp_terms);         local nT;  nT=$(echo -n "$NT"  | grep -c . || true)
  NTT=$(new_ids term_taxonomy_id wp_term_taxonomy); local nTT; nTT=$(echo -n "$NTT" | grep -c . || true)
  NC=$(new_ids comment_ID wp_comments);   local nC;  nC=$(echo -n "$NC"  | grep -c . || true)

  # BuddyBoss media/document linkage — NOT core WP, but forum/activity posts
  # reference these via postmeta (bp_media_ids/bp_document_ids). Without them the
  # post comes over but its images render as missing (the bb-mirror materializer
  # finds no attachment row -> no card cover). Missing-ids-only, same as above.
  local NBM NBA NBD NBF
  NBM=$(new_ids id wp_bp_media);            local nBM; nBM=$(echo -n "$NBM" | grep -c . || true)
  NBA=$(new_ids id wp_bp_media_albums);     local nBA; nBA=$(echo -n "$NBA" | grep -c . || true)
  NBD=$(new_ids id wp_bp_document);         local nBD; nBD=$(echo -n "$NBD" | grep -c . || true)
  NBF=$(new_ids id wp_bp_document_folder);  local nBF; nBF=$(echo -n "$NBF" | grep -c . || true)

  printf '   would add: %s posts, %s users, %s terms, %s term_taxonomy, %s comments\n' "$nP" "$nU" "$nT" "$nTT" "$nC"
  printf '   (+ their postmeta/usermeta/termmeta/commentmeta and new posts'\'' term_relationships)\n'
  printf '   bp media/albums/docs/folders: %s / %s / %s / %s\n' "$nBM" "$nBA" "$nBD" "$nBF"

  # Surface attachment ID-collisions that silently break covers: a live media row
  # whose attachment_id already exists on dev as a DIFFERENT file (independent
  # auto-increment) -> the new post points at the wrong/old dev image. Report so
  # it's visible (the missing-ids-only model can't remap; that's an accepted
  # trade-off, but a silent wrong image is not).
  report_media_collisions "$NBM"

  if [ "$MODE" = "preview" ]; then echo "   preview only — no writes."; return 0; fi
  if [ $((nP+nU+nT+nTT+nC+nBM+nBA+nBD+nBF)) -eq 0 ]; then echo "   nothing to add."; return 0; fi

  # ---- stage ONLY the delta rows into a scratch DB (schema mirrors dev) ----
  echo "-- staging delta into $STAGE_DB --"
  devsql -e "DROP DATABASE IF EXISTS $STAGE_DB; CREATE DATABASE $STAGE_DB;"
  local T
  for T in wp_users wp_usermeta wp_terms wp_termmeta wp_term_taxonomy \
           wp_posts wp_postmeta wp_term_relationships wp_comments wp_commentmeta \
           wp_bp_media wp_bp_media_albums wp_bp_document wp_bp_document_folder; do
    devsql -e "CREATE TABLE $STAGE_DB.$T LIKE $DEV_DB.$T;"
  done

  stage() {  # $1 table  $2 where-expr (skipped if empty list)
    local tbl="$1" where="$2"
    [ -n "$where" ] || return 0
    livedump --where="$where" "$LIVE_DB" "$tbl" | sudo mysql "$STAGE_DB"
  }
  local NP_C NU_C NT_C NTT_C NC_C NBM_C NBA_C NBD_C NBF_C
  NP_C=$(echo "$NP" | csv); NU_C=$(echo "$NU" | csv); NT_C=$(echo "$NT" | csv)
  NTT_C=$(echo "$NTT" | csv); NC_C=$(echo "$NC" | csv)
  NBM_C=$(echo "$NBM" | csv); NBA_C=$(echo "$NBA" | csv); NBD_C=$(echo "$NBD" | csv); NBF_C=$(echo "$NBF" | csv)

  [ -n "$NU_C" ]  && stage wp_users               "ID IN ($NU_C)"
  [ -n "$NU_C" ]  && stage wp_usermeta            "user_id IN ($NU_C)"
  [ -n "$NT_C" ]  && stage wp_terms               "term_id IN ($NT_C)"
  [ -n "$NT_C" ]  && stage wp_termmeta            "term_id IN ($NT_C)"
  [ -n "$NTT_C" ] && stage wp_term_taxonomy       "term_taxonomy_id IN ($NTT_C)"
  [ -n "$NP_C" ]  && stage wp_posts               "ID IN ($NP_C)"
  [ -n "$NP_C" ]  && stage wp_postmeta            "post_id IN ($NP_C)"
  [ -n "$NP_C" ]  && stage wp_term_relationships  "object_id IN ($NP_C)"
  [ -n "$NC_C" ]  && stage wp_comments            "comment_ID IN ($NC_C)"
  [ -n "$NC_C" ]  && stage wp_commentmeta         "comment_id IN ($NC_C)"
  [ -n "$NBM_C" ] && stage wp_bp_media            "id IN ($NBM_C)"
  [ -n "$NBA_C" ] && stage wp_bp_media_albums     "id IN ($NBA_C)"
  [ -n "$NBD_C" ] && stage wp_bp_document         "id IN ($NBD_C)"
  [ -n "$NBF_C" ] && stage wp_bp_document_folder  "id IN ($NBF_C)"

  # ---- apply: backup first (apply only), then insert in a transaction ------
  if [ "$MODE" = "apply" ]; then
    mkdir -p "$BACKUP_DIR"
    local bk="$BACKUP_DIR/${DEV_DB}_pre-topoff_$(date +%F_%H%M%S).sql.gz"
    echo "-- backing up dev -> $bk --"
    sudo mysqldump --single-transaction --no-tablespaces --skip-lock-tables "$DEV_DB" | gzip -c | sudo tee "$bk" >/dev/null
    echo "   backup: $(ls -lh "$bk" | awk '{print $5}')"
  fi

  local FINISH; [ "$MODE" = "apply" ] && FINISH="COMMIT;" || FINISH="ROLLBACK;"
  echo "-- inserting (transaction; will $([ "$MODE" = apply ] && echo COMMIT || echo ROLLBACK)) --"
  # No real FK constraints in WP, so insert order is free. Surrogate meta ids dropped.
  sudo mysql "$DEV_DB" <<SQL
START TRANSACTION;
INSERT INTO $DEV_DB.wp_users               SELECT * FROM $STAGE_DB.wp_users;
INSERT INTO $DEV_DB.wp_usermeta   (user_id,meta_key,meta_value)    SELECT user_id,meta_key,meta_value       FROM $STAGE_DB.wp_usermeta;
INSERT INTO $DEV_DB.wp_terms               SELECT * FROM $STAGE_DB.wp_terms;
INSERT INTO $DEV_DB.wp_termmeta   (term_id,meta_key,meta_value)    SELECT term_id,meta_key,meta_value        FROM $STAGE_DB.wp_termmeta;
INSERT INTO $DEV_DB.wp_term_taxonomy       SELECT * FROM $STAGE_DB.wp_term_taxonomy;
INSERT INTO $DEV_DB.wp_posts               SELECT * FROM $STAGE_DB.wp_posts;
INSERT INTO $DEV_DB.wp_postmeta   (post_id,meta_key,meta_value)    SELECT post_id,meta_key,meta_value        FROM $STAGE_DB.wp_postmeta;
INSERT IGNORE INTO $DEV_DB.wp_term_relationships  SELECT * FROM $STAGE_DB.wp_term_relationships;
INSERT INTO $DEV_DB.wp_comments            SELECT * FROM $STAGE_DB.wp_comments;
INSERT INTO $DEV_DB.wp_commentmeta(comment_id,meta_key,meta_value) SELECT comment_id,meta_key,meta_value     FROM $STAGE_DB.wp_commentmeta;
-- BuddyBoss media/document linkage: id PK preserved (bp_media_ids etc. key on it).
INSERT INTO $DEV_DB.wp_bp_media            SELECT * FROM $STAGE_DB.wp_bp_media;
INSERT INTO $DEV_DB.wp_bp_media_albums     SELECT * FROM $STAGE_DB.wp_bp_media_albums;
INSERT INTO $DEV_DB.wp_bp_document         SELECT * FROM $STAGE_DB.wp_bp_document;
INSERT INTO $DEV_DB.wp_bp_document_folder  SELECT * FROM $STAGE_DB.wp_bp_document_folder;
SELECT CONCAT('   in-txn dev post count = ', COUNT(*)) FROM $DEV_DB.wp_posts;
$FINISH
SQL
  devsql -e "DROP DATABASE IF EXISTS $STAGE_DB;"
  if [ "$MODE" = "test" ]; then
    echo "   TEST complete — transaction ROLLED BACK, dev unchanged."
  else
    echo "   APPLY complete — committed. (undo: restore the pre-topoff backup above)"
    # Make a db-scope top-off self-sufficient for IMAGES: pull just the files the
    # new content references (the full run_bucket sweep covers both classes too,
    # but is slow). There are TWO distinct image classes, sourced differently:
    #   1. BuddyBoss media (bb_medias/) — via wp_bp_media.attachment_id + new
    #      attachment posts. Forum/activity photo uploads.
    #   2. FluentForm uploads (fluentform/) — embedded as inline <img> with
    #      ABSOLUTE loothgroup.com URLs in post_content (the weekly-submission /
    #      council posts). These have NO bp_media row and NO attachment post, so
    #      class 1 misses them ENTIRELY; the bb-mirror materializer harvests them
    #      from inline <img>, but only if the file exists locally. So we must scan
    #      content for any /wp-content/uploads/ <img> and copy it too.
    _COPIED=0
    copy_new_media_files "$NP" "$NBM"
    copy_inline_content_files "$NP"
    refresh_uploads_mount "$_COPIED"
  fi
}

# copy_new_media_files <new-post-ids> <new-bp_media-ids>  (apply only)
# Class 1: BuddyBoss media — attachment ids = new attachment posts UNION the new
# bp_media rows' attachment_ids. Original file only; the resizer self-heals sizes.
copy_new_media_files() {
  local posts; posts=$(echo "$1" | csv)
  local media; media=$(echo "$2" | csv)
  local att=""
  [ -n "$posts" ] && att=$(live -e "SELECT ID FROM wp_posts WHERE ID IN ($posts) AND post_type='attachment'")
  [ -n "$media" ] && att="$att"$'\n'$(live -e "SELECT DISTINCT attachment_id FROM wp_bp_media WHERE id IN ($media) AND attachment_id>0")
  local attc; attc=$(echo "$att" | grep -E '^[0-9]+$' | sort -u | csv); [ -n "$attc" ] || { echo "   no new bp/attachment media files."; return 0; }
  local n=0 path
  echo "-- copying new BuddyBoss media files ($R2_SRC -> $R2_DST) --"
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    rclone copyto "$R2_SRC/$path" "$R2_DST/$path" 2>/dev/null && n=$((n+1))
  done < <(live -e "SELECT meta_value FROM wp_postmeta WHERE post_id IN ($attc) AND meta_key='_wp_attached_file'")
  echo "   copied $n bp/attachment media file(s)."
  _COPIED=$((_COPIED + n))
}

# copy_inline_content_files <new-post-ids>  (apply only)
# Class 2: inline <img> files in post_content — mainly fluentform/ uploads on the
# weekly-submission posts, which carry no bp_media/attachment row. Parse the live
# content for /wp-content/uploads/<path> image srcs and copy each. Host is
# stripped (URLs are absolute https://loothgroup.com/...), path is bucket-relative.
copy_inline_content_files() {
  local posts; posts=$(echo "$1" | csv); [ -n "$posts" ] || return 0
  local files
  files=$(live -e "SELECT post_content FROM wp_posts WHERE ID IN ($posts)" \
            | grep -oE 'wp-content/uploads/[^"'"'"' ]+\.(jpe?g|png|webp|gif)' \
            | sed 's#wp-content/uploads/##' | sort -u)
  [ -n "$files" ] || { echo "   no inline-content image files."; return 0; }
  local n=0 path
  echo "-- copying inline-content image files (fluentform/etc; $R2_SRC -> $R2_DST) --"
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    rclone copyto "$R2_SRC/$path" "$R2_DST/$path" 2>/dev/null && n=$((n+1))
  done < <(echo "$files")
  echo "   copied $n inline-content image file(s)."
  _COPIED=$((_COPIED + n))
}

# refresh_uploads_mount <count-copied>
# The uploads FUSE mount caches its dir listing for 12h, so freshly-copied objects
# are INVISIBLE to WP (file_exists fails -> bb-mirror drops the cover as a dead
# file) until the cache refreshes. Nudge it; if rc is off, tell the op.
refresh_uploads_mount() {
  [ "${1:-0}" -gt 0 ] || return 0
  if rclone rc vfs/refresh recursive=true >/dev/null 2>&1; then
    echo "   refreshed uploads mount dir-cache (rc)."
  else
    echo "   ⚠ uploads mount dir-cache is 12h — new files won't be visible to WP yet."
    echo "     run:  sudo systemctl restart r2-uploads-dev.service   (then re-materialize)"
  fi
}

run_bucket() {
  echo "-- bucket: $R2_SRC -> $R2_DST (additive, never deletes) --"
  local args=( copy "$R2_SRC" "$R2_DST" --transfers 16 --checkers 32 --exclude "$BUCKET_EXCLUDE" )
  if [ "$MODE" = "apply" ]; then
    rclone "${args[@]}" --stats-one-line -v
    echo "   bucket copy complete."
  else
    echo "   (dry-run — nothing copied)"
    rclone "${args[@]}" --dry-run 2>&1 | tail -8
  fi
}

if [ "$SCOPE" = "all" ] || [ "$SCOPE" = "db" ];     then run_db;     fi
if [ "$SCOPE" = "all" ] || [ "$SCOPE" = "bucket" ]; then run_bucket; fi
echo "== done =="
