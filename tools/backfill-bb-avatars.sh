#!/usr/bin/env bash
# backfill-bb-avatars.sh
# Copy each member's BuddyPress avatar into profile-app's own media store and
# repoint profiles.users.avatar_url at it — replacing the Gravatar-placeholder
# default. profile-app OWNS the image afterward (cutover-clean).
#
#   BB file  /var/www/dev/wp-content/uploads/avatars/<wp_user_id>/<hash>-bpfull.jpg
#     -> wp_user_bridge: wp_user_id -> user_id
#       -> users: id -> uuid
#         -> /srv/profile-app-media/avatars/<uuid>/1.jpg
#           -> avatar_url = /profile-media/avatars/<uuid>/1.jpg?v=1
#
# SAFE BY DESIGN:
#   * dry-run by default (prints the plan, copies/changes nothing)
#   * only touches profiles whose avatar_url is a gravatar URL (the placeholder
#     default) — NEVER clobbers a real /profile-media native upload
#   * never deletes the source BB files (copy only)
#   * writes a rollback .sql (restores prior avatar_url + avatar_version) before
#     applying anything
#
# Usage:
#   ./backfill-bb-avatars.sh                 # dry-run, all eligible
#   ./backfill-bb-avatars.sh --limit 5       # dry-run, first 5
#   ./backfill-bb-avatars.sh --limit 5 --apply   # really do 5
#   ./backfill-bb-avatars.sh --apply         # really do all eligible
set -euo pipefail

BB_ROOT=/var/www/dev/wp-content/uploads/avatars
MEDIA_ROOT=/srv/profile-app-media/avatars
DB=profile_app
TOOLS=/home/ubuntu/projects/tools
ROLLBACK="$TOOLS/avatar-backfill-rollback.sql"
APPLY=0; LIMIT=0
while [ $# -gt 0 ]; do case "$1" in
  --apply) APPLY=1;; --limit) LIMIT="$2"; shift;; *) echo "unknown arg $1"; exit 2;; esac; shift; done

PSQL() { sudo -u postgres psql "$DB" -tAqc "$1"; }

echo "== scanning $BB_ROOT for real avatars =="
# user ids (dir names) that hold a real *-bpfull.jpg, excluding the 0 placeholder dir
mapfile -t BBIDS < <(sudo bash -c '
  for d in '"$BB_ROOT"'/*/; do id=$(basename "$d"); [ "$id" = 0 ] && continue;
    ls "$d"/*-bpfull.jpg >/dev/null 2>&1 && echo "$id"; done' | sort -n)
echo "   ${#BBIDS[@]} BB avatars on disk"

# load them into a temp table and emit the eligible worklist in ONE session
printf '%s\n' "${BBIDS[@]}" > /tmp/_bbids.txt
WORK=/tmp/_avatar_worklist.tsv
sudo -u postgres psql "$DB" -tAqF$'\t' >"$WORK" <<SQL
create temp table _bb(wp_user_id bigint);
\copy _bb from '/tmp/_bbids.txt'
select b.wp_user_id, u.id, u.uuid, u.avatar_version, u.avatar_url
from _bb
join wp_user_bridge b using (wp_user_id)
join users u on u.id = b.user_id
where u.avatar_url like '%gravatar%'          -- placeholder default only
order by u.id
$( [ "$LIMIT" -gt 0 ] && echo "limit $LIMIT" );
SQL

N=$(wc -l < "$WORK"); echo "   $N profiles eligible (on gravatar placeholder, real BB avatar exists)"
[ "$N" -eq 0 ] && { echo "nothing to do"; exit 0; }

echo "== building rollback at $ROLLBACK =="
: > "$ROLLBACK"
echo "-- rollback for bb-avatar backfill ($(wc -l <"$WORK") rows)" >> "$ROLLBACK"
echo "BEGIN;" >> "$ROLLBACK"

APPLYSQL=/tmp/_avatar_apply.sql; : > "$APPLYSQL"; echo "BEGIN;" >> "$APPLYSQL"
done_ct=0; copied_ct=0
while IFS=$'\t' read -r wpid uid uuid ver cur; do
  src=$(sudo bash -c "ls -t $BB_ROOT/$wpid/*-bpfull.jpg 2>/dev/null | head -1")
  [ -z "$src" ] && { echo "  ! wp$wpid: no source file, skip"; continue; }
  ext="${src##*.}"; dst_dir="$MEDIA_ROOT/$uuid"; dst="$dst_dir/1.$ext"
  newurl="/profile-media/avatars/$uuid/1.$ext?v=1"
  # rollback line (restore exactly what was there); double any embedded quote
  cur_esc=${cur//\'/\'\'}
  printf "UPDATE users SET avatar_url='%s', avatar_version=%s WHERE id=%s;\n" \
    "$cur_esc" "${ver:-NULL}" "$uid" >> "$ROLLBACK"
  printf "UPDATE users SET avatar_url='%s', avatar_version=1 WHERE id=%s;\n" "$newurl" "$uid" >> "$APPLYSQL"
  if [ "$APPLY" -eq 1 ]; then
    sudo -u profile-app mkdir -p "$dst_dir"
    sudo cp "$src" "$dst"; sudo chown profile-app:profile-app "$dst"; copied_ct=$((copied_ct+1))
  else
    echo "  wp$wpid -> $uuid  ($(basename "$src") -> 1.$ext)"
  fi
  done_ct=$((done_ct+1))
done < "$WORK"
# fix rollback quoting (cur already contains the literal URL; wrap simply)
echo "COMMIT;" >> "$ROLLBACK"; echo "COMMIT;" >> "$APPLYSQL"

if [ "$APPLY" -eq 1 ]; then
  echo "== applying $done_ct avatar_url updates ($copied_ct files copied) =="
  sudo -u postgres psql "$DB" -q -f "$APPLYSQL"
  echo "DONE. Rollback: sudo -u postgres psql $DB -f $ROLLBACK"
else
  echo "== DRY-RUN: $done_ct profiles would be updated; rerun with --apply =="
  echo "   apply SQL preview: $APPLYSQL ; rollback would be: $ROLLBACK"
fi
