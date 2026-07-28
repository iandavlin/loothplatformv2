#!/usr/bin/env bash
# extract-ruled-frames.sh — LIVE extract for the frames of the design AS RULED
# (Ian, 2026-07-28: to-do list + fixed window + two registers + empty means no email).
#
# READ-ONLY, one SELECT per member over `live-ro`. Small result sets by construction —
# a handful of members, a handful of rows each. Nothing is pulled into memory to be
# filtered afterwards; every rule below is applied in SQL.
#
# THIS REPLACES THE EXTRACTS BEHIND THE OLD PREVIS, which are now misleading rather
# than merely stale: they render reactions and connection acceptances (both excluded
# on 07-28) and a Rule 3b before/after arguing for a design Ian declined.
#
# Three payload shapes per member, so the frames can show what each ruling DID:
#   before_ruling  every type, is_read only          — what shipped on 07-27
#   named          to-do types, fresh, resolved-test — register one
#   stale          to-do types, older than window, still unresolved, counted by type
set -uo pipefail

OUT_DIR=/tmp/lg-wdr/out
OUT="$OUT_DIR/ruled-frames.json"
mkdir -p "$OUT_DIR"
IDS="${*:-94 4 197}"
LIST="$(echo "$IDS" | tr ' ' ',')"

TODO="'connection_request','forum.mention','forum.reply_to_topic','forum.reply_to_reply'"
# An item is still outstanding: a connection edge that still waits on them, or an
# unread bell row. This is the RESOLVED test, and it is why the counted register can
# exist at all — is_read cannot end a count.
RESOLVED="(CASE WHEN n.type='connection_request' THEN c.status='pending' ELSE n.is_read=false END)"
ROW="n.type, n.target_kind, n.target_id, n.anchor_id, n.target_url, n.actor_count,
     a.display_name AS actor_name, a.slug AS actor_slug, n.created_at"

read -r -d '' SQL <<SQL
SELECT json_agg(j) FROM (
SELECT json_build_object(
  'extracted_at', to_char(now() at time zone 'UTC','YYYY-MM-DD HH24:MI') || ' UTC',
  'wp_user_id', b.wp_user_id, 'display_name', u.display_name,
  'before_ruling', COALESCE((SELECT json_agg(row_to_json(x)) FROM (
      SELECT $ROW FROM notifications n
        LEFT JOIN users a ON a.uuid=n.actor_uuid
        LEFT JOIN connections c ON c.id=n.connection_id
       WHERE n.user_uuid=u.uuid AND n.is_read=false
         AND n.created_at >= now() - interval '7 days'
         AND (n.connection_id IS NULL
              OR (n.type='connection_request' AND c.status='pending')
              OR (n.type='connection_accept'  AND c.status='accepted'))
       ORDER BY n.created_at DESC) x), '[]'::json),
  'named', COALESCE((SELECT json_agg(row_to_json(y)) FROM (
      SELECT $ROW FROM notifications n
        LEFT JOIN users a ON a.uuid=n.actor_uuid
        LEFT JOIN connections c ON c.id=n.connection_id
       WHERE n.user_uuid=u.uuid AND n.type IN ($TODO)
         AND n.created_at >= now() - interval '7 days' AND $RESOLVED
       ORDER BY n.created_at DESC) y), '[]'::json),
  'stale', COALESCE((SELECT json_object_agg(t, c2) FROM (
      SELECT n.type AS t, count(*) AS c2 FROM notifications n
        LEFT JOIN connections c ON c.id=n.connection_id
       WHERE n.user_uuid=u.uuid AND n.type IN ($TODO)
         AND n.created_at < now() - interval '7 days' AND $RESOLVED
       GROUP BY n.type) z), '{}'::json)
) AS j
FROM users u JOIN wp_user_bridge b ON b.user_id=u.id
WHERE b.wp_user_id IN ($LIST)) q;
SQL

ssh -o BatchMode=yes live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -A -t -c \"$(printf '%s' "$SQL" | tr '\n' ' ')\"" > "$OUT" 2>"$OUT_DIR/ruled.err"

if [ ! -s "$OUT" ]; then
  echo "EMPTY EXTRACT for ids [$LIST]. Suspect the ids before concluding zero." >&2
  cat "$OUT_DIR/ruled.err" >&2; exit 1
fi

python3 - "$OUT" <<'PY'
import json,sys
for d in json.load(open(sys.argv[1])):
    st=sum(d['stale'].values())
    print(f"wp:{d['wp_user_id']:<5} {d['display_name'][:34]:<34} "
          f"before={len(d['before_ruling']):<3} named={len(d['named']):<3} stale={st}")
PY
echo "wrote $OUT"
