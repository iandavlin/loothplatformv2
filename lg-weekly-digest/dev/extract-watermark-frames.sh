#!/usr/bin/env bash
# extract-watermark-frames.sh — LIVE extract behind the Rule 3b (per-member window) frames.
#
# READ-ONLY. One SELECT against LIVE `profile_app` over `live-ro` (PG account
# `looth_ro`, read-only by construction). Writes nothing on live; writes one JSON
# file into /tmp on this box. No serve window needed.
#
# WHY A SEPARATE EXTRACT FROM THE RULE 1 ONE: the Rule 1 frames answer "what does
# read-suppression remove". Rule 3b answers a different question — "what does a
# FIXED 7-day window silently drop when a member's send fails" — and that needs
# rows from the 7-14 day band, which the 7-day extract by definition does not have.
#
# The payload shape is exactly what Recap::forWpIds() returns, so the same renderer
# consumes it. `is_read` and the connections.status test are applied HERE, matching
# the shipped source rules, so every row in the output is genuinely listable and the
# only variable left between the two panels is the WINDOW.
#
# Usage:  bash lg-weekly-digest/dev/extract-watermark-frames.sh [wp_user_id] [days]
set -uo pipefail

WP="${1:-9}"
DAYS="${2:-14}"
OUT_DIR=/tmp/lg-wdr/out
OUT="$OUT_DIR/watermark-frames.json"
mkdir -p "$OUT_DIR"

read -r -d '' SQL <<SQL
SELECT json_build_object(
  'extracted_at', to_char(now() at time zone 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC',
  'wp_user_id',   b.wp_user_id,
  'days',         ${DAYS},
  'display_name', u.display_name,
  'notifications', COALESCE((
      SELECT json_agg(row_to_json(x) ORDER BY x.created_at DESC) FROM (
        SELECT n.type, n.target_kind,
               n.target_id, n.anchor_id, n.target_url,
               n.actor_count, a.display_name AS actor_name, a.slug AS actor_slug,
               n.created_at,
               (n.created_at >= now() - interval '7 days') AS in_last_7d
          FROM notifications n
          LEFT JOIN users a       ON a.uuid = n.actor_uuid
          LEFT JOIN connections c ON c.id   = n.connection_id
         WHERE n.user_uuid = u.uuid
           AND n.is_read = false
           AND n.created_at >= now() - make_interval(days => ${DAYS})
           AND (n.connection_id IS NULL
                OR (n.type = 'connection_request' AND c.status = 'pending')
                OR (n.type = 'connection_accept'  AND c.status = 'accepted'))
      ) x), '[]'::json),
  'dms', COALESCE((
      SELECT json_agg(row_to_json(y)) FROM (
        SELECT t.uuid AS thread_uuid, r.unread_count AS unread, t.last_message_at
          FROM message_recipients r
          JOIN message_threads t ON t.id = r.thread_id
         WHERE r.user_uuid = u.uuid AND r.unread_count > 0 AND r.is_deleted = false
           AND t.last_message_at >= now() - make_interval(days => ${DAYS})
      ) y), '[]'::json)
)
FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
WHERE b.wp_user_id = ${WP};
SQL

# -A -t: unaligned, tuples only — the row IS the JSON document.
ssh -o BatchMode=yes live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -A -t -c \"$(printf '%s' "$SQL" | tr '\n' ' ')\"" > "$OUT" 2>/tmp/lg-wdr/out/watermark-extract.err

if [ ! -s "$OUT" ]; then
  echo "EMPTY EXTRACT for wp:${WP}. Do NOT read that as 'this member has nothing' —" >&2
  echo "an empty result from a wrong id is indistinguishable from a real zero (that" >&2
  echo "exact mistake inverted a build call on 2026-07-27). stderr:" >&2
  cat /tmp/lg-wdr/out/watermark-extract.err >&2
  exit 1
fi

python3 - "$OUT" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
n = d['notifications']
print(f"wp:{d['wp_user_id']}  {d['display_name']}")
print(f"  extracted   {d['extracted_at']} from LIVE (not dev2)")
print(f"  listable in {d['days']}d : {len(n)}")
print(f"  of those, last 7d       : {sum(1 for r in n if r['in_last_7d'])}")
print(f"  in the 7-{d['days']}d band      : {sum(1 for r in n if not r['in_last_7d'])}")
PY
echo "wrote $OUT"
