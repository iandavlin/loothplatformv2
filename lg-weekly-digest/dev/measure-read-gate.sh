#!/usr/bin/env bash
# measure-read-gate.sh — how many LIVE members does the UNREAD-ONLY rule cost a digest?
#
#   bash lg-weekly-digest/dev/measure-read-gate.sh
#   → lg-weekly-digest/dev/live-rtr/read-gate.txt
#
# ── THE QUESTION (keeper, 2026-08-01, from Ian's own live case) ──────────────
#
# Ian's only profile_app notification is a `forum.mention` with is_read = TRUE, and
# his recap rendered nothing. Does the recap count READ notifications or only unread?
#
# ANSWERED FROM SOURCE, and this script measures what the answer COSTS:
#
#   profile-app/src/Recap.php:120-123   const OUTSTANDING, used by BOTH registers
#                                       (named :209, counted :303)
#
#     (n.type = 'connection_request' AND c.status = 'pending')          <- edge decides, is_read NOT consulted
#  OR (n.type = 'connection_accept'  AND c.status = 'accepted' AND n.is_read = false)
#  OR (n.connection_id IS NULL AND n.is_read = false)                   <- EVERY hub row. UNREAD-ONLY.
#
# Every forum type the digest admits (forum.mention, forum.reply_to_topic,
# forum.reply_to_reply — class-lg-wd-recap.php:103-105) is a hub row: it carries no
# connection_id. So all three fall in that third arm and are UNREAD-ONLY. Ian's read
# mention is excluded BY THE RULE, exactly as written. It is not a bridge defect and
# not a renderer defect.
#
# ── WHY THAT IS NOT A SAFE DEFAULT, WHICH IS THE REAL FINDING ────────────────
#
# Recap.php:117 calls this a documented limit: "hub rows — no edge exists, so is_read
# is the only resolution signal they have." That reasoning holds only if is_read means
# THE MEMBER DEALT WITH IT. On mobile it does not:
#
#   webroot/bottom-nav.js:1125-1128   setTimeout(markAllNotifsRead, 700) fires when the
#                                     sheet renders, and markAllNotifsRead posts
#                                     {action:'read_all'} — ALL rows, not the visible 8.
#
# So opening the notification sheet once, for 700ms, marks every notification read
# server-side. Under the unread-only rule that empties the member's recap; under Ian's
# "empty means send no email" it CANCELS their digest. The member most engaged with the
# bell is the member most reliably unmailed — the digest is inversely correlated with
# engagement, which is the opposite of what a weekly recap is for.
#
# The same 700ms timer is ALREADY the stated reason connection_request refuses to
# consult is_read (Recap.php:110-113). That protection was never extended to hub rows.
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
echo "  live   $LIVE_MD5"
echo "  branch $BRANCH_MD5"
if [ "$LIVE_MD5" != "$WANT_MD5" ] || [ "$BRANCH_MD5" != "$WANT_MD5" ]; then
	echo "REFUSING: Recap.php drifted from $WANT_MD5 — re-lift OUTSTANDING before trusting this."
	exit 1
fi

# The window is LG_WD_Recap_Source::WINDOW_DAYS (7), the digest's only writer.
# `included` is class-lg-wd-recap.php:103-105 restricted to HUB types (the three
# forum.* ones); connection_request is in INCLUDED_TYPES too but is edge-decided and
# so is unaffected by the read gate — it is carried separately below as "other
# material" precisely so the cancelled-email count cannot overstate itself.
echo "── measuring the read gate on LIVE (read-only) ──"
ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -q -f -" > "$OUT/read-gate.txt" <<'SQL'
\pset border 2

\echo '=== 1. IN-WINDOW HUB ROWS THE DIGEST ADMITS, BY TYPE AND READ STATE ==='
\echo '(read rows are the ones the current rule DISCARDS)'
SELECT n.type,
       count(*)                                        AS in_window,
       count(*) FILTER (WHERE n.is_read = false)        AS unread_kept,
       count(*) FILTER (WHERE n.is_read = true)         AS read_DISCARDED
  FROM notifications n
 WHERE n.connection_id IS NULL
   AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
   AND n.created_at >= now() - make_interval(days => 7)
 GROUP BY n.type
 ORDER BY n.type;

\echo ''
\echo '=== 2. THE SAME ROWS, ALL-TIME — is the read gate discarding most of the corpus? ==='
SELECT n.type,
       count(*)                                        AS all_time,
       count(*) FILTER (WHERE n.is_read = false)        AS unread,
       count(*) FILTER (WHERE n.is_read = true)         AS read_
  FROM notifications n
 WHERE n.connection_id IS NULL
   AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
 GROUP BY n.type
 ORDER BY n.type;

\echo ''
\echo '=== 3. THE HEADLINE: members whose digest the read gate CANCELS ==='
\echo 'holds >=1 in-window admitted forum row, ALL of them read, and NOTHING else to send:'
\echo 'no pending connection_request, no unread in-window DM. Today these members get NO EMAIL.'
WITH bridged AS (
    SELECT u.uuid, b.wp_user_id, u.display_name
      FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
),
fr AS (   -- in-window admitted hub rows, per member
    SELECT n.user_uuid,
           count(*)                                   AS n_rows,
           count(*) FILTER (WHERE n.is_read = false)   AS n_unread
      FROM notifications n
     WHERE n.connection_id IS NULL
       AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
       AND n.created_at >= now() - make_interval(days => 7)
     GROUP BY n.user_uuid
),
other AS (  -- anything else that would keep the email alive on its own
    SELECT b.uuid,
           EXISTS (SELECT 1 FROM notifications n2
                    JOIN connections c ON c.id = n2.connection_id
                   WHERE n2.user_uuid = b.uuid
                     AND n2.type = 'connection_request' AND c.status = 'pending'
                     AND n2.created_at >= now() - make_interval(days => 7)) AS has_conn,
           EXISTS (SELECT 1 FROM message_recipients r
                    JOIN message_threads t ON t.id = r.thread_id
                   WHERE r.user_uuid = b.uuid AND r.unread_count > 0 AND r.is_deleted = false
                     AND t.last_message_at >= now() - make_interval(days => 7)) AS has_dm
      FROM bridged b
)
SELECT b.wp_user_id, b.display_name, fr.n_rows AS read_rows_discarded
  FROM fr
  JOIN bridged b ON b.uuid = fr.user_uuid
  JOIN other  o ON o.uuid = fr.user_uuid
 WHERE fr.n_unread = 0            -- every admitted forum row they hold is READ
   AND o.has_conn = false
   AND o.has_dm   = false
 ORDER BY fr.n_rows DESC, b.wp_user_id;

\echo ''
\echo '=== 4. SAME, AS COUNTS (cancelled) vs merely SHORTENED ==='
WITH bridged AS (
    SELECT u.uuid, b.wp_user_id FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
),
fr AS (
    SELECT n.user_uuid,
           count(*) FILTER (WHERE n.is_read = false)   AS n_unread,
           count(*) FILTER (WHERE n.is_read = true)    AS n_read
      FROM notifications n
     WHERE n.connection_id IS NULL
       AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
       AND n.created_at >= now() - make_interval(days => 7)
     GROUP BY n.user_uuid
),
other AS (
    SELECT b.uuid,
           EXISTS (SELECT 1 FROM notifications n2
                    JOIN connections c ON c.id = n2.connection_id
                   WHERE n2.user_uuid = b.uuid
                     AND n2.type = 'connection_request' AND c.status = 'pending'
                     AND n2.created_at >= now() - make_interval(days => 7)) AS has_conn,
           EXISTS (SELECT 1 FROM message_recipients r
                    JOIN message_threads t ON t.id = r.thread_id
                   WHERE r.user_uuid = b.uuid AND r.unread_count > 0 AND r.is_deleted = false
                     AND t.last_message_at >= now() - make_interval(days => 7)) AS has_dm
      FROM bridged b
)
SELECT
  count(*) FILTER (WHERE fr.n_read > 0)                                          AS members_losing_rows,
  count(*) FILTER (WHERE fr.n_read > 0 AND fr.n_unread > 0)                      AS merely_shortened,
  count(*) FILTER (WHERE fr.n_read > 0 AND fr.n_unread = 0
                     AND o.has_conn = false AND o.has_dm = false)                 AS email_CANCELLED
  FROM fr JOIN bridged b ON b.uuid = fr.user_uuid JOIN other o ON o.uuid = fr.user_uuid;

\echo ''
\echo '=== 5. IAN HIMSELF — the case that prompted the question ==='
SELECT b.wp_user_id, u.display_name, n.type, n.is_read, n.created_at,
       (n.created_at >= now() - make_interval(days => 7)) AS in_window
  FROM notifications n
  JOIN users u ON u.uuid = n.user_uuid
  JOIN wp_user_bridge b ON b.user_id = u.id
 WHERE u.slug = 'ian-davlin'
 ORDER BY n.created_at DESC;
SQL

echo
echo "── result ──"
cat "$OUT/read-gate.txt"
