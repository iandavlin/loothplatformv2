#!/usr/bin/env bash
# extract-seen-vs-resolved.sh — LIVE extract behind the "suppress on SEEN or on
# RESOLVED?" frame. READ-ONLY, one SELECT over `live-ro`. No serve window.
#
# THE QUESTION (keeper, 2026-07-28): for an ACTIONABLE item, read-state and done-state
# are not the same thing. A member can read a connection request and not answer it.
# Suppressing on `is_read` alone makes the one thing that genuinely needs them
# disappear from the only reminder they get — and since "empty means send nothing",
# it now removes their whole email rather than one row.
#
# BOTH PANELS USE THE SAME MEMBER, THE SAME FIXED 7-DAY WINDOW, AND THE SAME TO-DO
# TYPE FILTER. The only variable is which signal counts as "dealt with":
#
#   SEEN      n.is_read = false            — what ships today
#   RESOLVED  c.status  = 'pending'        — the edge itself still waits on them
#
# Note the RESOLVED rule is not new machinery: `connections.status` is ALREADY read
# live by the shipped query. The change is dropping the is_read half for this type,
# not adding a source.
set -uo pipefail

WP="${1:-39}"
OUT_DIR=/tmp/lg-wdr/out
OUT="$OUT_DIR/seen-vs-resolved.json"
mkdir -p "$OUT_DIR"

read -r -d '' SQL <<SQL
SELECT json_build_object(
  'extracted_at', to_char(now() at time zone 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC',
  'wp_user_id',   b.wp_user_id,
  'display_name', u.display_name,
  'seen',     COALESCE((SELECT json_agg(row_to_json(x)) FROM (
        SELECT n.type, n.target_kind, n.target_id, n.anchor_id, n.target_url,
               n.actor_count, a.display_name AS actor_name, a.slug AS actor_slug, n.created_at
          FROM notifications n
          LEFT JOIN users a       ON a.uuid = n.actor_uuid
          LEFT JOIN connections c ON c.id   = n.connection_id
         WHERE n.user_uuid = u.uuid
           AND n.created_at >= now() - interval '7 days'
           AND n.type IN ('connection_request','forum.mention','forum.reply_to_topic','forum.reply_to_reply')
           AND n.is_read = false
           AND (n.connection_id IS NULL OR c.status = 'pending')
      ) x), '[]'::json),
  'resolved', COALESCE((SELECT json_agg(row_to_json(y)) FROM (
        SELECT n.type, n.target_kind, n.target_id, n.anchor_id, n.target_url,
               n.actor_count, a.display_name AS actor_name, a.slug AS actor_slug, n.created_at
          FROM notifications n
          LEFT JOIN users a       ON a.uuid = n.actor_uuid
          LEFT JOIN connections c ON c.id   = n.connection_id
         WHERE n.user_uuid = u.uuid
           AND n.created_at >= now() - interval '7 days'
           AND n.type IN ('connection_request','forum.mention','forum.reply_to_topic','forum.reply_to_reply')
           -- the ONLY difference: an unanswered edge survives being looked at
           AND (CASE WHEN n.type = 'connection_request' THEN c.status = 'pending'
                     ELSE n.is_read = false END)
      ) y), '[]'::json)
)
FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
WHERE b.wp_user_id = ${WP};
SQL

ssh -o BatchMode=yes live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -A -t -c \"$(printf '%s' "$SQL" | tr '\n' ' ')\"" > "$OUT" 2>"$OUT_DIR/seen-vs-resolved.err"

if [ ! -s "$OUT" ]; then
  echo "EMPTY EXTRACT for wp:${WP}. Suspect the id before concluding zero." >&2
  cat "$OUT_DIR/seen-vs-resolved.err" >&2
  exit 1
fi

python3 - "$OUT" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
print(f"wp:{d['wp_user_id']}  {d['display_name']}   ({d['extracted_at']}, LIVE)")
print(f"  suppress on SEEN     -> {len(d['seen'])} rows  " +
      ("(NO EMAIL AT ALL)" if not d['seen'] else ""))
print(f"  suppress on RESOLVED -> {len(d['resolved'])} rows")
for r in d['resolved']:
    print(f"      {r['type']:<20} from {r['actor_name']}  {r['created_at'][:10]}")
PY
echo "wrote $OUT"
