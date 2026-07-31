#!/usr/bin/env bash
# fetch-live-rtr.sh — capture, READ-ONLY from LIVE, the exact recap material every
# member holding an unread reply-to-reply would be sent.
#
#   bash lg-weekly-digest/dev/fetch-live-rtr.sh
#   → lg-weekly-digest/dev/live-rtr/payloads.json
#
# ── WHY THE SQL IS COPIED HERE INSTEAD OF CALLING THE ENDPOINT ───────────────
#
# The honest way to ask "what would live send?" is to call live's own
# /profile-api/v0/internal/recap. That is NOT available to this lane: the route is
# loopback-only and authenticates with /etc/lg-internal-secret, which is
# root:www-data 0640 and unreadable as `looth-ro` (checked, not assumed).
#
# So the substitute is to run the endpoint's OWN QUERIES against live's Postgres
# read-only. That is only legitimate because the code is provably the same code:
#
#   /srv/profile-app/src/Recap.php            md5 ca315aad10b799bd989efb97884fdaf4  (LIVE)
#   profile-app/src/Recap.php                 md5 ca315aad10b799bd989efb97884fdaf4  (this branch)
#   ~/loothplatformv2-clean/…/Recap.php       md5 ca315aad10b799bd989efb97884fdaf4  (serving checkout)
#
# The three queries below are lifted character-for-character from that file —
# OUTSTANDING (Recap.php:120-123), the bell-row query (:201-210), the DM query
# (:239-257) and the stale register (:299-306). The hash is RE-CHECKED at run time
# and this script refuses to produce a payload if live has drifted, because the
# moment it drifts this file is fiction rather than evidence.
#
# ── TITLES COME FROM LIVE TOO, AND THAT IS THE WHOLE POINT ───────────────────
#
# Recap.php deliberately does not resolve titles (":TITLES ARE NOT RESOLVED HERE") —
# WP does it, via LG_WD_Recap_Source::hydrate_titles() → get_the_title(). Rendering a
# LIVE payload on dev2 therefore resolves LIVE post ids against DEV2's database, and
# they are not the same forum: topic 72409 is `publish` on live and ABSENT on dev2, so
# dev2 hydration silently downgrades a named topic to "in a discussion". A frame drawn
# that way understates what the member actually receives.
#
# Measured, so the substitution is not a guess: for the two ids present on BOTH boxes,
# dev2's get_the_title() returns live's raw post_title byte-for-byte (46614, 68119) —
# get_the_title is the identity function on these rows, applying no entity decoding.
# So live's raw post_title IS what live's get_the_title would hand the renderer, and
# carrying it across is a faithful substitution rather than a cosmetic patch.
#
# WRITES NOTHING TO LIVE. Two SELECTs and a hash check.
set -euo pipefail

LANE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$LANE/lg-weekly-digest/dev/live-rtr"
mkdir -p "$OUT"

WANT_MD5=ca315aad10b799bd989efb97884fdaf4

echo "── checking live's Recap.php is still the code this SQL was lifted from ──"
LIVE_MD5="$(ssh live-ro "md5sum /srv/profile-app/src/Recap.php" | awk '{print $1}')"
BRANCH_MD5="$(md5sum "$LANE/profile-app/src/Recap.php" | awk '{print $1}')"
echo "  live   $LIVE_MD5"
echo "  branch $BRANCH_MD5"
if [ "$LIVE_MD5" != "$WANT_MD5" ] || [ "$BRANCH_MD5" != "$WANT_MD5" ]; then
	echo "REFUSING: Recap.php has drifted from $WANT_MD5. Re-lift the queries below"
	echo "from the current file before trusting anything this script prints."
	exit 1
fi

# ── 1. the payloads, from live's Postgres ────────────────────────────────────
# The window (7) is LG_WD_Recap_Source::WINDOW_DAYS, the digest's only writer.
echo "── fetching recap payloads for every holder of an unread reply-to-reply ──"
ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -q -t -A -f -" > "$OUT/payloads.raw.json" <<'SQL'
WITH targets AS (
    -- Everyone who currently holds an unread, in-window reply-to-reply. Derived,
    -- never hard-coded: the cast must follow live, not a list frozen at authoring.
    SELECT DISTINCT b.wp_user_id, u.uuid, u.display_name
      FROM notifications n
      JOIN users u ON u.uuid = n.user_uuid
      JOIN wp_user_bridge b ON b.user_id = u.id
     WHERE n.type = 'forum.reply_to_reply'
       AND n.is_read = false
       AND n.created_at >= now() - make_interval(days => 7)
),
notes AS (
    SELECT n.user_uuid, n.type, n.target_kind, n.target_id, n.anchor_id,
           n.target_url, n.actor_count, n.created_at,
           a.display_name AS actor_name, a.slug AS actor_slug
      FROM notifications n
      LEFT JOIN users a ON a.uuid = n.actor_uuid
      LEFT JOIN connections c ON c.id = n.connection_id
     WHERE n.user_uuid IN (SELECT uuid FROM targets)
       AND n.created_at >= now() - make_interval(days => 7)
       AND (
              (n.type = 'connection_request' AND c.status = 'pending')
           OR (n.type = 'connection_accept'  AND c.status = 'accepted' AND n.is_read = false)
           OR (n.connection_id IS NULL AND n.is_read = false)
       )
),
dms AS (
    SELECT r.user_uuid, t.uuid AS thread_uuid, r.unread_count, t.last_message_at,
           s.names, s.slugs
      FROM message_recipients r
      JOIN message_threads t ON t.id = r.thread_id
      LEFT JOIN LATERAL (
            SELECT array_agg(DISTINCT su.display_name) AS names,
                   array_agg(DISTINCT su.slug)         AS slugs
              FROM messages m
              JOIN users su ON su.uuid = m.sender_uuid
             WHERE m.thread_id = t.id
               AND m.sender_uuid <> r.user_uuid
               AND m.deleted_at IS NULL
               AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
      ) s ON true
     WHERE r.user_uuid IN (SELECT uuid FROM targets)
       AND r.unread_count > 0
       AND r.is_deleted = false
       AND t.last_message_at >= now() - make_interval(days => 7)
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
           OR (n.connection_id IS NULL AND n.is_read = false)
       )
     GROUP BY n.user_uuid, n.type
)
SELECT json_agg(row_to_json(x))::text FROM (
    SELECT t.wp_user_id,
           t.display_name,
           t.uuid,
           -- MAX_ROWS (Recap.php:126) is 50 and is applied caller-side there; no
           -- member here is near it, and the count is reported so that stays visible.
           COALESCE((SELECT json_agg(row_to_json(n) ORDER BY n.created_at DESC)
                       FROM notes n WHERE n.user_uuid = t.uuid), '[]'::json) AS notifications,
           COALESCE((SELECT json_agg(row_to_json(d) ORDER BY d.unread_count DESC)
                       FROM dms d WHERE d.user_uuid = t.uuid), '[]'::json) AS dms,
           COALESCE((SELECT json_object_agg(s.type, s.n)
                       FROM stale s WHERE s.user_uuid = t.uuid), '{}'::json) AS stale
      FROM targets t
     ORDER BY t.wp_user_id
) x;
SQL

# ── 2. the titles, from live's MySQL ─────────────────────────────────────────
IDS="$(python3 -c '
import json,sys
d=json.load(open(sys.argv[1])) or []
ids={n["target_id"] for m in d for n in m["notifications"] if n.get("target_id")}
print(",".join(str(i) for i in sorted(ids)) or "0")
' "$OUT/payloads.raw.json")"
echo "── fetching live titles for target ids: $IDS ──"
ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
  \"SELECT ID, post_status, post_title FROM wp_posts WHERE ID IN ($IDS);\"" > "$OUT/titles.tsv"

# ── 3. merge into the payload shape LG_WD_Recap::render() consumes ───────────
python3 - "$OUT/payloads.raw.json" "$OUT/titles.tsv" "$OUT/payloads.json" <<'PY'
import json, sys
raw, tsv, out = sys.argv[1], sys.argv[2], sys.argv[3]

titles = {}
for line in open(tsv, encoding='utf-8'):
    line = line.rstrip('\n')
    if not line: continue
    pid, status, title = line.split('\t', 2)
    # hydrate_titles() only names a post that still resolves; anything else is ''
    # and the renderer falls back to untitled wording.
    titles[int(pid)] = title if status not in ('trash', 'auto-draft') else ''

members = json.load(open(raw, encoding='utf-8')) or []
for m in members:
    for n in m['notifications']:
        tid = n.get('target_id')
        n['title'] = titles.get(int(tid), '') if tid else ''

json.dump(members, open(out, 'w', encoding='utf-8'), indent=2, ensure_ascii=False)
print(f"wrote {out}: {len(members)} member(s)")
for m in members:
    rtr = sum(1 for n in m['notifications'] if n['type'] == 'forum.reply_to_reply')
    print(f"  wp {m['wp_user_id']:<6} {m['display_name'][:38]:<38} "
          f"rows={len(m['notifications'])} reply_to_reply={rtr} dms={len(m['dms'])}")
PY

echo
echo "payloads: $OUT/payloads.json"
