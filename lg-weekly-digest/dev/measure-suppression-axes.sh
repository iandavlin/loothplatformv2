#!/usr/bin/env bash
# measure-suppression-axes.sh — re-measure the digest AS RULED, on LIVE.
#
# READ-ONLY. Every statement is a SELECT against LIVE via `live-ro`, a read-only
# account by construction (PG `looth_ro`, MySQL `/home/looth-ro/.my.cnf`). Writes
# nothing anywhere, needs no serve window, filters in SQL rather than in the shell.
#
#   bash lg-weekly-digest/dev/measure-suppression-axes.sh
#
# ── REWRITTEN 2026-07-28. IT WAS ASKING QUESTIONS THE RULINGS HAVE ANSWERED. ──
#
# The previous version evaluated triggers for two builds that Ian then declined and
# one rule he replaced. Kept as history in git; not kept here, because a monitoring
# script that reports on a design nobody is building is worse than no script — it
# invites someone to act on it.
#
#   RETIRED  Rule 3a/3b triggers (failed digest recipients, the watermark window,
#            the floorless-backlog cost). Ian ruled the FIXED 7-DAY WINDOW.
#   RETIRED  the Rule 2 trigger AS A PERCENTAGE (forum types exceeding ~10% of
#            listable items). The threshold was the wrong instrument — see §6.
#
# ── ⚠️ A RETRACTION, 2026-07-30. THIS HEADER USED TO BE WRONG. ────────────────
#
# It said: "Rule 2 is retired PERMANENTLY, not deferred: the digest cannot carry
# followed-thread activity under ANY §9.1 outcome ... there is nothing to
# de-duplicate against and no volume at which that changes."
#
# EVERY CLAUSE AFTER THE FIRST IS FALSE. The first is true — `forum.followed_topic`
# is excluded on the to-do test. But the digest NAMES `forum.reply_to_topic`, and
# BB's reply mailer (`bb_send_forums_subscribed_reply`, class-bp-forums-notification
# .php:989) removes only the REPLIER from the recipient list, never the topic author
# — its own comment says otherwise and the comment is wrong. So a member holding the
# thread-follow ✉ bit on a topic THEY authored receives the same reply twice: once as
# a per-event email, once as a NAMED digest row.
#
# I reasoned correctly about one excluded type and then spoke for the whole axis. A
# script that tells the next reader a question is permanently closed is the most
# expensive kind of wrong, because it removes the reason to look again — so the
# trigger is restored as §6, and it is deliberately NOT a threshold on these
# numbers. See RECAP-SUPPRESSION-PROPOSAL.md §4.1b and §4.1c.
#
# WHAT IS WORTH WATCHING NOW is what the rulings made load-bearing: who gets mail at
# all, whether the counted register's copy still matches reality, and whether the
# verbosity guardrails still bound the worst week.
#
# ── TRAPS THIS FILE HAS ALREADY FALLEN INTO. Read before trusting an empty result. ──
#
# 1. AN EMPTY RESULT FROM A WRONG PATTERN IS INDISTINGUISHABLE FROM A REAL ZERO.
#    A `LIKE '%Week of%'` returned empty; the live titles read `Weekly Digest — June
#    1, 2026`. That single wrong string inverted a build decision. Suspect the
#    pattern before you conclude the answer is zero.
# 2. THE SIBLING, IN THE OTHER DIRECTION: a naive REGEXP 'group' returns thousands of
#    hits, every one the words "The Looth Group" in the site name.
# 3. DO NOT SPLIT A MEASURED TOTAL INTO PLAUSIBLE PARTS. I published a per-type stale
#    breakdown inferred from a real total; measured, every stale item was one type.
#    If a breakdown is not in the output below, it was not measured.
# 4. COUNT IN THE UNIT THAT SHIPS. A verbosity measurement in raw reply EVENTS said
#    17.5% of member-weeks were over 3 rows. In BELL ROWS — what the digest renders,
#    after notify-bridge coalesces — it is 5.1%. Same data, different question.

set -uo pipefail

PG="psql -h 127.0.0.1 -U looth_ro -d profile_app -A -F'|'"
PGL="psql -h 127.0.0.1 -U looth_ro -d looth -A -F'|'"
MY="mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e"

# The admission rule, in SQL, exactly as LG_WD_Recap::INCLUDED_TYPES declares it.
TODO="'connection_request','forum.mention','forum.reply_to_topic','forum.reply_to_reply'"
# Outstanding = the edge still waits on them, or the bell row is unread. NOT is_read
# for connection_request — a member who glanced at a request has not answered it, and
# the mobile sheet auto-marks everything read 700ms after it opens.
OUTSTANDING="(CASE WHEN n.type='connection_request' THEN c.status='pending' ELSE n.is_read=false END)"

echo "=============================================================="
echo " 1. COMPOSITION AS RULED — named (fresh) vs counted (stale)"
echo "=============================================================="
echo "-- bridged members only: an unbridged row can never be mailed"
ssh -o BatchMode=yes live-ro "$PG -c \"
SELECT n.type,
       count(*) FILTER (WHERE n.created_at >= now() - interval '7 days') AS named_fresh,
       count(*) FILTER (WHERE n.created_at <  now() - interval '7 days') AS counted_stale
  FROM notifications n
  JOIN users u ON u.uuid = n.user_uuid
  JOIN wp_user_bridge b ON b.user_id = u.id
  LEFT JOIN connections c ON c.id = n.connection_id
 WHERE n.type IN ($TODO) AND $OUTSTANDING
 GROUP BY 1 ORDER BY 3 DESC, 2 DESC;
\""

echo
echo "-- what the TO-DO TEST excludes, so its cost stays visible rather than assumed"
ssh -o BatchMode=yes live-ro "$PG -c \"
SELECT n.type, count(*) AS rows_all_time,
       count(*) FILTER (WHERE n.created_at >= now() - interval '7 days') AS in_window
  FROM notifications n
 WHERE n.type IN ('connection_accept','reaction.on_post')
 GROUP BY 1 ORDER BY 2 DESC;
\""

echo
echo "=============================================================="
echo " 2. WHO GETS AN EMAIL AT ALL  (empty means send nothing)"
echo "=============================================================="
echo "-- THE number to watch. If 'total_mailed' collapses, the digest has gone quiet"
echo "-- for a reason nobody will otherwise notice — there is no bounce and no error."
ssh -o BatchMode=yes live-ro "$PG -c \"
WITH item AS (
  SELECT b.wp_user_id, (n.created_at >= now() - interval '7 days') AS fresh
    FROM notifications n
    JOIN users u ON u.uuid=n.user_uuid
    JOIN wp_user_bridge b ON b.user_id=u.id
    LEFT JOIN connections c ON c.id=n.connection_id
   WHERE n.type IN ($TODO) AND $OUTSTANDING
), per AS (
  SELECT wp_user_id, count(*) FILTER (WHERE fresh) f, count(*) FILTER (WHERE NOT fresh) s
    FROM item GROUP BY 1
)
SELECT count(*) FILTER (WHERE f>0 AND s=0) AS named_only,
       count(*) FILTER (WHERE f=0 AND s>0) AS counted_only,
       count(*) FILTER (WHERE f>0 AND s>0) AS both,
       count(*)                            AS total_mailed,
       count(*) FILTER (WHERE f>0)         AS would_be_mailed_without_counted_register
  FROM per;
\""

echo
echo "-- denominator: how many are on the list at all (MySQL, list 3)"
ssh -o BatchMode=yes live-ro "$MY \"
SELECT 'list3_rows' k, COUNT(*) v FROM wp_fc_subscriber_pivot p
  WHERE p.object_id=3 AND p.object_type LIKE '%Lists%'
UNION ALL SELECT 'subscribed', COUNT(*) FROM wp_fc_subscriber_pivot p
  JOIN wp_fc_subscribers s ON s.id=p.subscriber_id
 WHERE p.object_id=3 AND p.object_type LIKE '%Lists%' AND s.status='subscribed';\""

echo
echo "=============================================================="
echo " 3. DOES THE COUNTED REGISTER'S COPY STILL MATCH REALITY?"
echo "=============================================================="
echo "-- The line reads 'You have N <thing> waiting'. Two things can drift:"
echo "--   the SINGULAR stops being the common case, or N grows past what reads well."
ssh -o BatchMode=yes live-ro "$PG -c \"
WITH stale AS (
  SELECT b.wp_user_id, count(*) AS n
    FROM notifications n
    JOIN users u ON u.uuid=n.user_uuid
    JOIN wp_user_bridge b ON b.user_id=u.id
    LEFT JOIN connections c ON c.id=n.connection_id
   WHERE n.created_at < now() - interval '7 days'
     AND n.type IN ($TODO) AND $OUTSTANDING
   GROUP BY 1
)
SELECT n AS stale_items_held, count(*) AS members FROM stale GROUP BY 1 ORDER BY 1;
\""

echo
echo "-- HAS A FORUM TYPE EVER GONE STALE? As of 2026-07-28 the counted register was"
echo "-- 100% connection_request, so 'You have 2 replies to your comments waiting' had"
echo "-- NEVER been rendered against real data. A non-zero here is the first time."
ssh -o BatchMode=yes live-ro "$PG -c \"
SELECT n.type, count(*) AS stale_now
  FROM notifications n
  JOIN users u ON u.uuid=n.user_uuid
  JOIN wp_user_bridge b ON b.user_id=u.id
 WHERE n.created_at < now() - interval '7 days'
   AND n.type IN ('forum.mention','forum.reply_to_topic','forum.reply_to_reply')
   AND n.is_read = false
 GROUP BY 1;
\""
echo "   (no rows = still never happened; the copy for those types remains unexercised)"

echo
echo "=============================================================="
echo " 4. VERBOSITY — do the two existing guardrails still bound it?"
echo "=============================================================="
echo "-- Guardrail 1: notify-bridge COALESCES before the digest sees anything."
echo "--   reply_to_topic anchor=0            -> one row per TOPIC"
echo "--   reply_to_reply anchor=parent reply -> one row per COMMENT OF YOURS"
echo "-- Guardrail 2: LG_WD_Recap::MAX_ROWS = 8, tail rolled into 'N more waiting'."
echo "-- Measured in BELL ROWS from the mirror, which is the unit that renders."
ssh -o BatchMode=yes live-ro "$PGL -c \"
WITH rows_ AS (
  SELECT t.author_id AS member, date_trunc('week', r.created_at) AS wk, 'rtt:'||t.id AS k
    FROM forums.reply r JOIN forums.topic t ON t.id=r.topic_id
   WHERE r.author_id IS DISTINCT FROM t.author_id AND t.author_id IS NOT NULL
     AND r.status='publish' AND r.created_at >= now() - interval '2 years'
  UNION ALL
  SELECT p.author_id, date_trunc('week', r.created_at), 'rtr:'||p.id
    FROM forums.reply r JOIN forums.reply p ON p.id=r.parent_reply_id
   WHERE r.author_id IS DISTINCT FROM p.author_id AND p.author_id IS NOT NULL
     AND r.status='publish' AND r.created_at >= now() - interval '2 years'
), per AS (SELECT member, wk, count(DISTINCT k) AS bell_rows FROM rows_ GROUP BY 1,2)
SELECT count(*) AS member_weeks, round(avg(bell_rows),2) AS avg_rows,
       count(*) FILTER (WHERE bell_rows > 3) AS over_3,
       count(*) FILTER (WHERE bell_rows > 8) AS over_the_8_row_cap,
       max(bell_rows) AS worst_week
  FROM per;
\""
echo "   Baseline 2026-07-28: 1564 member-weeks, avg 1.53, over_3 = 80 (5.1%),"
echo "   over the cap = 6 in two years, worst 12 -> renders as 8 rows + '4 more'."
echo "   A third guardrail was NOT built because these two already bound it."
echo
echo "-- NOT MEASURED ANYWHERE ABOVE, and said so rather than left implied: MENTIONS."
echo "-- Each mention is its own bell row (anchor = the reply), and counting historical"
echo "-- mentions needs content parsing for @slug. It is also the component most likely"
echo "-- to grow — the autocomplete minter only shipped 2026-07-23. If a wall ever"
echo "-- appears in this section, mentions are where it comes from, not replies."

echo
echo "=============================================================="
echo " 5. THE FOURTH AXIS — still open, still Ian's"
echo "=============================================================="
echo "Nothing marks a notification read when a member clicks a link in the EMAIL."
echo "markRead is called ONLY from /me-notifications, the bell modal. So an"
echo "email-only reader keeps seeing the same items — now as a COUNTED line rather"
echo "than a repeated named row, which is softer but not a fix."
echo "The obvious cure (a click-clear redirect) is deliberately NOT built: mail"
echo "scanners follow every link with no human involved, and on this platform's own"
echo "click data 7-10% of apparent clickers are machines hitting 10-20 links inside"
echo "four seconds. A GET that cleared items would wipe a member's recap before they"
echo "opened it, and the failure would look exactly like the feature working."
echo "WEEKLY-DIGEST-RECAP.md §9.2."

echo
echo "=============================================================="
echo " 6. AXIS 2 — THE TRIGGER I WRONGLY DELETED, RESTORED 2026-07-30"
echo "=============================================================="
echo "-- The header of this script used to say Rule 2 was retired PERMANENTLY and"
echo "-- that there was 'no volume at which that changes'. THAT WAS WRONG. It"
echo "-- reasoned about forum.followed_topic (excluded, correctly) and then spoke"
echo "-- for axis 2 as a whole. The digest NAMES forum.reply_to_topic, and BB's"
echo "-- reply mailer excludes only the REPLIER, never the topic author -- so a"
echo "-- member with the thread-follow email bit on their OWN topic gets the same"
echo "-- reply twice: a per-event email and a named digest row."
echo "-- See RECAP-SUPPRESSION-PROPOSAL.md 4.1b / 4.1c."
echo "--"
echo "-- THE TRIGGER IS NOT A PERCENTAGE. It is another lane's ship date:"
echo "--   reopen when thread-follow's mail toggle ships on the admitted types"
echo "--   (THREAD-FOLLOW-SPEC 3.5's menu) OR our own sender ships (9.2) --"
echo "--   whichever lands first, and BEFORE it lands. A threshold on the numbers"
echo "--   below would let the defect ship while the numbers were still small."
echo "-- What the numbers are for: sizing the blast radius on the day it reopens."
echo
echo "-- (a) HOW BIG IS THE OVERLAPPABLE POPULATION AT ALL"
ssh -o BatchMode=yes live-ro "$PG -c \"
SELECT count(*) FILTER (WHERE n.type LIKE 'forum.%')  AS overlappable_items,
       count(*) FILTER (WHERE n.type NOT LIKE 'forum.%') AS no_email_path_exists
  FROM notifications n
  JOIN users u ON u.uuid=n.user_uuid
  JOIN wp_user_bridge b ON b.user_id=u.id
  LEFT JOIN connections c ON c.id=n.connection_id
 WHERE n.type IN ($TODO) AND $OUTSTANDING;
\""
echo "   Connections/DMs live in profile_app and NOTHING emails about them, so the"
echo "   right-hand column can never overlap. It is the left one that can."
echo
echo "-- (b) THE ONE THAT DECIDES WHETHER SUPPRESSION IS SAFE AT ALL."
echo "-- Rule 5 says empty means SEND NO EMAIL. So for any member whose entire"
echo "-- digest is forum rows, suppressing the emailed ones does not SHORTEN their"
echo "-- email -- it DELETES it. If 'forum_ONLY_would_be_silenced' is not 0, then"
echo "-- axis 2 cannot ship without a floor, and the previs frame is the argument."
ssh -o BatchMode=yes live-ro "$PG -c \"
WITH item AS (
  SELECT b.wp_user_id, n.type
    FROM notifications n
    JOIN users u ON u.uuid=n.user_uuid
    JOIN wp_user_bridge b ON b.user_id=u.id
    LEFT JOIN connections c ON c.id=n.connection_id
   WHERE n.type IN ($TODO) AND $OUTSTANDING
), per AS (
  SELECT wp_user_id, count(*) AS all_items,
         count(*) FILTER (WHERE type LIKE 'forum.%') AS forum_items
    FROM item GROUP BY 1
)
SELECT count(*)                                        AS members_mailed,
       count(*) FILTER (WHERE forum_items > 0)         AS have_any_forum_item,
       count(*) FILTER (WHERE forum_items = all_items) AS forum_ONLY_would_be_silenced
  FROM per;
\""
echo "   Baseline 2026-07-30: 258 mailed, 7 have a forum item, and ALL 7 have"
echo "   ONLY forum items -- every member axis 2 could touch today would be"
echo "   silenced completely, not shortened. The two populations are disjoint."
