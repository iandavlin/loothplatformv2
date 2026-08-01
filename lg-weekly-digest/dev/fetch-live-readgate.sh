#!/usr/bin/env bash
# fetch-live-readgate.sh — capture, READ-ONLY from LIVE, the digest each CANCELLED
# member would receive if the read gate stopped deciding INCLUSION for hub rows.
#
#   bash lg-weekly-digest/dev/fetch-live-readgate.sh
#   → lg-weekly-digest/dev/live-rtr/readgate-payloads.json
#
# Companion to measure-read-gate.sh, which found the defect; this captures the
# PICTURE of it. The cast is DERIVED (never hard-coded): every bridged member who
# holds >=1 in-window admitted forum row, has ALL of them read, and has no pending
# connection_request and no unread in-window DM. Those members receive NO EMAIL today
# under "empty means send no email".
#
# The ONLY difference from fetch-live-rtr.sh's SQL is the third OUTSTANDING arm:
#
#   live today   OR (n.connection_id IS NULL AND n.is_read = false)
#   here         OR (n.connection_id IS NULL)                        <- read gate lifted
#
# The other two arms are copied character-for-character and left ALONE, because the
# proposal is narrow: connection rows keep being decided by their edge exactly as they
# are now. Rendering this payload through the shipping renderer therefore shows the
# real consequence of the one-line change and nothing else.
#
# Same hash guard as fetch-live-rtr.sh: the SQL is lifted from Recap.php and is
# fiction the moment live drifts from it.
#
# WRITES NOTHING TO LIVE. SELECTs only.
set -euo pipefail

LANE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$LANE/lg-weekly-digest/dev/live-rtr"
mkdir -p "$OUT"

WANT_MD5=ca315aad10b799bd989efb97884fdaf4

echo "── checking live's Recap.php is still the code this SQL was lifted from ──"
LIVE_MD5="$(ssh live-ro "md5sum /srv/profile-app/src/Recap.php" | awk '{print $1}')"
BRANCH_MD5="$(md5sum "$LANE/profile-app/src/Recap.php" | awk '{print $1}')"
if [ "$LIVE_MD5" != "$WANT_MD5" ] || [ "$BRANCH_MD5" != "$WANT_MD5" ]; then
	echo "REFUSING: Recap.php drifted from $WANT_MD5 (live=$LIVE_MD5 branch=$BRANCH_MD5)."
	exit 1
fi
echo "  both $WANT_MD5"

echo "── fetching the digest each read-gate-cancelled member would receive ──"
ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -q -t -A -f -" > "$OUT/readgate-payloads.raw.json" <<'SQL'
WITH bridged AS (
    SELECT u.uuid, b.wp_user_id, u.display_name
      FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
),
fr AS (
    SELECT n.user_uuid,
           count(*) FILTER (WHERE n.is_read = false) AS n_unread,
           count(*) FILTER (WHERE n.is_read = true)  AS n_read
      FROM notifications n
     WHERE n.connection_id IS NULL
       AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
       AND n.created_at >= now() - make_interval(days => 7)
     GROUP BY n.user_uuid
),
targets AS (   -- the CANCELLED four, derived exactly as measure-read-gate.sh §3 does
    SELECT b.wp_user_id, b.uuid, b.display_name
      FROM fr JOIN bridged b ON b.uuid = fr.user_uuid
     WHERE fr.n_read > 0
       AND fr.n_unread = 0
       AND NOT EXISTS (SELECT 1 FROM notifications n2
                        JOIN connections c ON c.id = n2.connection_id
                       WHERE n2.user_uuid = b.uuid
                         AND n2.type = 'connection_request' AND c.status = 'pending'
                         AND n2.created_at >= now() - make_interval(days => 7))
       AND NOT EXISTS (SELECT 1 FROM message_recipients r
                        JOIN message_threads t ON t.id = r.thread_id
                       WHERE r.user_uuid = b.uuid AND r.unread_count > 0 AND r.is_deleted = false
                         AND t.last_message_at >= now() - make_interval(days => 7))
),
notes AS (
    SELECT n.user_uuid, n.type, n.target_kind, n.target_id, n.anchor_id,
           n.target_url, n.actor_count, n.created_at, n.is_read,
           a.display_name AS actor_name, a.slug AS actor_slug
      FROM notifications n
      LEFT JOIN users a ON a.uuid = n.actor_uuid
      LEFT JOIN connections c ON c.id = n.connection_id
     WHERE n.user_uuid IN (SELECT uuid FROM targets)
       AND n.created_at >= now() - make_interval(days => 7)
       AND (
              (n.type = 'connection_request' AND c.status = 'pending')
           OR (n.type = 'connection_accept'  AND c.status = 'accepted' AND n.is_read = false)
           OR (n.connection_id IS NULL)          -- READ GATE LIFTED (the proposal)
       )
),
stale AS (
    SELECT n.user_uuid, n.type, count(*) AS n
      FROM notifications n
      LEFT JOIN connections c ON c.id = n.connection_id
     WHERE n.user_uuid IN (SELECT uuid FROM targets)
       AND n.created_at < now() - make_interval(days => 7)
       AND (
              (n.type = 'connection_request' AND c.status = 'pending')
           OR (n.type = 'connection_accept'  AND c.status = 'accepted' AND n.is_read = false)
           OR (n.connection_id IS NULL)
       )
     GROUP BY n.user_uuid, n.type
)
SELECT json_agg(row_to_json(x))::text FROM (
    SELECT t.wp_user_id, t.display_name, t.uuid,
           COALESCE((SELECT json_agg(row_to_json(n) ORDER BY n.created_at DESC)
                       FROM notes n WHERE n.user_uuid = t.uuid), '[]'::json) AS notifications,
           '[]'::json AS dms,        -- targets are selected on having NO unread DM
           COALESCE((SELECT json_object_agg(s.type, s.n)
                       FROM stale s WHERE s.user_uuid = t.uuid), '{}'::json) AS stale
      FROM targets t
     ORDER BY t.wp_user_id
) x;
SQL

IDS="$(python3 -c '
import json,sys
d=json.load(open(sys.argv[1])) or []
ids={n["target_id"] for m in d for n in m["notifications"] if n.get("target_id")}
print(",".join(str(i) for i in sorted(ids)) or "0")
' "$OUT/readgate-payloads.raw.json")"
echo "── fetching live titles for target ids: $IDS ──"
ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
  \"SELECT ID, post_status, post_title FROM wp_posts WHERE ID IN ($IDS);\"" > "$OUT/readgate-titles.tsv"

python3 - "$OUT/readgate-payloads.raw.json" "$OUT/readgate-titles.tsv" "$OUT/readgate-payloads.json" <<'PY'
import json, sys
raw, tsv, out = sys.argv[1], sys.argv[2], sys.argv[3]
titles = {}
for line in open(tsv, encoding='utf-8'):
    line = line.rstrip('\n')
    if not line: continue
    pid, status, title = line.split('\t', 2)
    titles[int(pid)] = title if status not in ('trash', 'auto-draft') else ''
members = json.load(open(raw, encoding='utf-8')) or []
for m in members:
    for n in m['notifications']:
        tid = n.get('target_id')
        n['title'] = titles.get(int(tid), '') if tid else ''
json.dump(members, open(out, 'w', encoding='utf-8'), indent=2, ensure_ascii=False)
print(f"wrote {out}: {len(members)} member(s)")
for m in members:
    rd = sum(1 for n in m['notifications'] if n.get('is_read'))
    print(f"  wp {m['wp_user_id']:<6} {m['display_name'][:38]:<38} "
          f"rows={len(m['notifications'])} (read={rd}) — receives NOTHING today")
PY
echo
echo "payloads: $OUT/readgate-payloads.json"
