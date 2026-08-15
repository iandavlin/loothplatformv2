#!/bin/bash
# Backlog 30 — LIVE deletion of the four videos Ian ruled on ("Delete all four").
#
# PREPARED, NOT RUN. Handed to keeper for Ian per the live-push practice.
# Nothing in this script deletes anything until it is invoked with --apply, and
# every guard must pass first. Default is a dry run.
#
# Run ON LIVE, as the user that owns the WordPress install.
#
#   bash live-delete-four-videos.sh            # dry run: checks only
#   bash live-delete-four-videos.sh --apply    # performs the deletion
#
# WHY GUARDS AND NOT JUST wp post delete: the ids below were resolved against
# LIVE on 2026-08-15, but live is a moving target — a member can attach a file to
# a post between the audit and the run, and an id can be reused after a purge.
# Each guard re-proves, at run time, the thing the audit concluded.
#
# THE GUARD SQL HAS BEEN RUN AGAINST LIVE, READ-ONLY, ON 2026-08-15: all four
# passed (path matches, still parentless, zero references across eight checks).
# What could NOT be validated from here is the wp-cli half — live-ro cannot read
# /var/www/dev/wp-config.php (0660 looth-dev:loothdevs), so `wp` fails for that
# user. Whoever runs this has the access; the dry run exercises it end to end.

set -u
APPLY=0; [ "${1:-}" = "--apply" ] && APPLY=1
# The ONLY wp-config on live is /var/www/dev/wp-config.php — yes, "dev", on the
# live box. Live's nginx logs are named dev.loothgroup.access.log too, and its
# uploads mount is /mnt/loothgroup-uploads-dev while the bucket is loothgroup2-0.
# The word "dev" means nothing here. CONFIRM before running:
#     wp --path=$WP_PATH option get siteurl     # must print https://loothgroup.com
WP_PATH="${WP_PATH:-/var/www/dev}"
BUCKET="${BUCKET:-r2up:loothgroup2-0}"             # live uploads bucket
WP="wp --path=$WP_PATH"

# id : expected path : expected md5 (captured from dev2's identical copies)
ITEMS=(
  "6110:2023/09/nut-making.mp4:be8b7db9fecbb8e038ff8a62dff66089"
  "57931:2025/08/3D-Clamp-Feet.mp4:bd43a242d16b9d2e8208bc31582bcf49"
  "57953:2025/08/3D-Clamp-Feet-1.mp4:bd43a242d16b9d2e8208bc31582bcf49"
  "6145:2023/09/Loothsaber-Chisel.mp4:a37c3ce9b443a91a24d8f6370ac39415"
)

fail=0
echo "=== GUARDS (all four must pass; nothing is deleted if any fails) ==="
for it in "${ITEMS[@]}"; do
  id="${it%%:*}"; rest="${it#*:}"; path="${rest%%:*}"
  # 1. the id is still an attachment, and still THIS file
  got=$($WP db query "SELECT meta_value FROM wp_postmeta WHERE post_id=$id AND meta_key='_wp_attached_file';" --skip-column-names 2>/dev/null)
  [ "$got" = "$path" ] || { echo "  FAIL $id: path is '$got', expected '$path'"; fail=1; continue; }
  # 2. still parentless
  par=$($WP db query "SELECT post_parent FROM wp_posts WHERE ID=$id;" --skip-column-names 2>/dev/null)
  [ "$par" = "0" ] || { echo "  FAIL $id: now has post_parent=$par — someone attached it"; fail=1; continue; }
  # 3. still unreferenced, the same nine ways the audit checked
  stem=$(basename "$path"); stem="${stem%.*}"
  n=0
  for q in \
    "SELECT COUNT(*) FROM wp_posts WHERE post_type<>'attachment' AND (post_content LIKE '%$stem%' OR post_excerpt LIKE '%$stem%')" \
    "SELECT COUNT(*) FROM wp_postmeta pm JOIN wp_posts p ON p.ID=pm.post_id WHERE p.post_type<>'attachment' AND pm.meta_value LIKE '%$stem%'" \
    "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%$stem%'" \
    "SELECT COUNT(*) FROM wp_bp_activity WHERE content LIKE '%$stem%'" \
    "SELECT COUNT(*) FROM wp_bp_media WHERE attachment_id=$id" \
    "SELECT COUNT(*) FROM wp_bp_document WHERE attachment_id=$id" \
    "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_thumbnail_id' AND meta_value=$id"; do
    c=$($WP db query "$q;" --skip-column-names 2>/dev/null); n=$((n + ${c:-0}))
  done
  pg=$(psql -h 127.0.0.1 -U looth_ro -d looth -tAc "SELECT count(*) FROM discovery.article_blobs WHERE blob::text LIKE '%$stem%';" 2>/dev/null)
  n=$((n + ${pg:-0}))
  [ "$n" = "0" ] || { echo "  FAIL $id: $n reference(s) found NOW — do not delete, re-audit"; fail=1; continue; }
  echo "  ok   $id  $path  (parentless, 0 references across 8 checks)"
done

[ "$fail" = "0" ] || { echo; echo "GUARDS FAILED — nothing deleted."; exit 1; }
echo "  all guards passed."

if [ "$APPLY" != "1" ]; then
  echo; echo "DRY RUN. Re-run with --apply to delete. Expected reclaim: 522.3 MB (547,620,221 bytes)."
  exit 0
fi

echo; echo "=== DELETING ==="
for it in "${ITEMS[@]}"; do
  id="${it%%:*}"; rest="${it#*:}"; path="${rest%%:*}"
  rclone deletefile "$BUCKET/$path" && echo "  object gone: $path"
  $WP post delete "$id" --force && echo "  row gone:    $id"
done
echo; echo "=== VERIFY ==="
for it in "${ITEMS[@]}"; do
  id="${it%%:*}"; rest="${it#*:}"; path="${rest%%:*}"
  o=$(rclone lsl "$BUCKET/$path" 2>/dev/null | wc -l)
  r=$($WP db query "SELECT COUNT(*) FROM wp_posts WHERE ID=$id;" --skip-column-names 2>/dev/null)
  echo "  $id objects_left=$o rows_left=$r $([ "$o" = "0" ] && [ "$r" = "0" ] && echo OK || echo '*** REMNANT ***')"
done
