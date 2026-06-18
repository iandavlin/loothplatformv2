#!/usr/bin/env bash
# tools/content-cleanup/dedupe-duplicate-posts.sh
#
# Duplicate-post dedupe — CUT-PLAYBOOK script (content-cleanup lane, 2026-06-11).
# Proven on dev 2026-06-11; re-run ON LIVE at cutover after verifying the audit
# section returns the same pairs (IDs assumed identical to dev's DB copy).
#
# Background: conversion re-runs CREATED duplicate WP posts (same slug,
# WP-uniquified to "-2") instead of reusing, and the slug-keyed blob lookup in
# archive-poc/standalone/render.php had no tiebreaker (fixed: ORDER BY
# materialized_at DESC, post_id DESC). This script removes the duplicate posts.
#
# NOTE: these CPTs do NOT support trash — on dev the dupes were parked as
# DRAFTS (reversible), not deleted. Permanent deletion is Ian's call at cut
# (see "Ian decision" below).
#
# Usage: review every section before running. Run as a user with wp-cli access
# to the live install. WP=path to WordPress root.
set -euo pipefail
WP_PATH="${WP_PATH:?set WP_PATH to the WordPress root}"
wp() { command sudo -u www-data wp --path="$WP_PATH" "$@"; }

echo "== AUDIT: same-slug-modulo-suffix pairs in conversion CPTs =="
wp db query "
SELECT a.post_type, a.ID base_id, a.post_status base_status, a.post_name base_slug,
       b.ID dup_id, b.post_status dup_status, b.post_name dup_slug
FROM wp_posts a
JOIN wp_posts b
  ON b.post_type = a.post_type
 AND b.post_name REGEXP CONCAT('^', a.post_name, '-[0-9]+$')
WHERE a.post_type IN ('post-type-videos','post-imgcap','post-regular','sponsor-post','shorty','loothcuts','loothprint','document','public-post')
  AND a.post_status NOT IN ('trash','auto-draft','inherit')
  AND b.post_status NOT IN ('trash','auto-draft','inherit')
  AND a.post_name <> ''
ORDER BY a.post_type, a.post_name;"

echo "== AUDIT: duplicate titles in conversion CPTs =="
wp db query "
SELECT p.post_type, p.post_title, COUNT(*) c,
       GROUP_CONCAT(p.ID ORDER BY p.ID) ids,
       GROUP_CONCAT(p.post_status ORDER BY p.ID) statuses
FROM wp_posts p
WHERE p.post_type IN ('post-type-videos','post-imgcap','post-regular','sponsor-post','loothcuts','loothprint','document','public-post')
  AND p.post_status NOT IN ('trash','auto-draft','inherit')
  AND p.post_title <> ''
GROUP BY p.post_type, p.post_title HAVING c > 1;"

cat <<'EOF'
== Confirmed dupes (dev audit 2026-06-11) and actions ==

NOT dupes — do not touch (same titles, DIFFERENT videos):
  post-type-videos 17035 (yt B2RTOPMORgE) vs 17008 (yt _Lkbtf9nctc, "Fret-O-Rama 2")
  post-type-videos 33314 (yt 7dSt0j-hQjY) vs 45971 (yt AzckxPLFpIg)
  shorty "Looth Lite" x22 — distinct shorties with a lazy shared title.

Dupes actioned on dev (drafted/parked, NOT deleted):
  67625 post-imgcap  legacy source of 67638; slug parked as ...-legacy-67625,
                     survivor 67638 took the clean slug
                     erlewine-archive-plugging-a-torn-outlet-jack-hole.
  68956 sponsor-post byte-identical copy of 68941 (slug was "68956-2") -> draft.
  50134 sponsor-post byte-identical "copy of" dupe of 50086 -> draft.
  3666  loothprint   legacy original of 13651; its write-up text was PORTED into
                     13651's layout (wysiwyg blocks, _meta.text_ported_from=3666)
                     -> draft. 3666's vrm360 STL-viewer shortcode has no v2 block
                     equivalent and was dropped (flagged to Ian).
  49194 post-imgcap  pre-conversion source draft of 49197 — already draft, left.
  61044 post-type-videos pre-conversion source draft of 61067 — already draft, left.

EOF

echo "== ACTION (replicate dev state on live) =="
wp post update 67625 --post_name='erlewine-archive-plugging-a-torn-outlet-jack-hole-legacy-67625'
wp post update 67638 --post_name='erlewine-archive-plugging-a-torn-outlet-jack-hole'
wp post update 68956 50134 3666 --post_status=draft

echo "== SYNC discovery stores (save-hooks do NOT fire reliably from wp-cli) =="
# Adjust host/loopback for live. Each call upserts-or-deletes blob + index row.
for id in 67625 67638 68956 50134 3666 13651; do
  curl -sk -X POST 'https://127.0.0.1/archive-api/v0/_sync' \
    -H 'Host: dev.loothgroup.com' -H 'Content-Type: application/json' \
    -d "{\"post_id\":$id,\"action\":\"upsert\"}"; echo
done
# Blob store (run from archive-poc checkout):
#   for id in 67625 67638 68956 50134 13651; do
#     sudo -u <write-role> env LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
#       php bin/materialize-all.php --post=$id; done
# 13651's layout text-port lives in _lg_layout_v2 meta — DB content, arrives on
# live via the cut-day DB migration, but its blob still needs the re-bake above.

cat <<'EOF'
== Ian decision (cut day) ==
Permanently delete the parked dupes? (CPTs lack trash; delete is forever.)
  wp post delete 68956 50134 3666 49194 61044 --force
  67625 holds the original 1.6MB legacy source of 67638 — keep as parked draft
  unless Ian says otherwise.
EOF
