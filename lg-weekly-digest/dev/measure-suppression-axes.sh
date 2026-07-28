#!/usr/bin/env bash
# measure-suppression-axes.sh — re-run every measurement behind RECAP-SUPPRESSION-PROPOSAL.md.
#
# READ-ONLY. Every statement here is a SELECT against LIVE via `live-ro`, which is a
# read-only account by construction (PG `looth_ro`, MySQL `/home/looth-ro/.my.cnf`).
# It writes nothing anywhere and needs no serve window.
#
# WHY THIS EXISTS: the proposal declines to build two of the three axes, and both
# declines carry a named trigger to revisit. A trigger nobody can cheaply evaluate is
# not a trigger. Run this; the answers are the triggers.
#
#   Rule 2 reopens if  forum types exceed ~10% of listable items (see §1.1 / §1.2)
#   Rule 3b was gated on a Weekly Digest campaign recording a `failed` recipient.
#                      THAT TRIGGER IS ALREADY MET — campaign 283, 2026-06-01, six
#                      members. The proposal now recommends BUILDING 3b. Block (b)
#                      below stays because it is how you check whether it has
#                      happened AGAIN, and how you size it if it does.
#
# Run:  bash lg-weekly-digest/dev/measure-suppression-axes.sh
#
# ── VERIFICATION STATUS (2026-07-28) ─────────────────────────────────────────
# RUN END TO END, clean, against LIVE. Every block below produced output.
#
# WHAT THE FIRST FULL RUN CAUGHT — the reason this file exists:
# The proposal claimed "no weekly digest has ever had a failed recipient." FALSE.
# I had matched campaign titles on LIKE '%Week of%', got an empty result, and read
# that empty result as a real zero. The live titles are 'Weekly Digest — June 1,
# 2026'. Campaign 283 IS a digest, and it lost 6 real members to an SES signature
# error. That single wrong character-string inverted a build/don't-build call.
#
# SO: IF A BLOCK BELOW RETURNS EMPTY, SUSPECT THE PATTERN BEFORE YOU CONCLUDE THE
# ANSWER IS ZERO. An empty result from a wrong LIKE is indistinguishable from a
# genuine zero, and this file's own history is the cautionary case.
#
# The sibling trap, in the opposite direction: a naive REGEXP 'group' returns 5,689
# hits, every one of them the words "The Looth Group" in the site name. Neither a
# zero nor a big number means anything until you have checked the pattern.
#
# NOTE on the email log: FluentSMTP retains 14 days. Any question needing a longer view
# cannot be answered from it at all — that is one of the three reasons the proposal
# rejects log-scraping as the instrument for axis 2.

set -uo pipefail

PG="psql -h 127.0.0.1 -U looth_ro -d profile_app -A -F'|'"
MY="mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e"

echo "=============================================================="
echo " AXIS 1 — read on the website (SHIPPED). Recap-shaped, 7d."
echo "=============================================================="
ssh live-ro "$PG -c \"
SELECT n.type,
       count(*) FILTER (WHERE n.is_read = false) AS listable,
       count(*) FILTER (WHERE n.is_read)         AS suppressed_by_read,
       count(*)                                  AS total
  FROM notifications n
  LEFT JOIN connections c ON c.id = n.connection_id
 WHERE n.created_at >= now() - interval '7 days'
   AND (n.connection_id IS NULL
        OR (n.type = 'connection_request' AND c.status = 'pending')
        OR (n.type = 'connection_accept'  AND c.status = 'accepted'))
 GROUP BY 1 ORDER BY 1;
\" -c \"
-- raw minus the above total = rows the connections.status test drops on its own
SELECT count(*) AS raw_rows_7d FROM notifications WHERE created_at >= now() - interval '7 days';
\""

echo
echo "=============================================================="
echo " AXIS 2 — already per-event emailed. RULE 2 TRIGGER."
echo "=============================================================="
echo "-- email log coverage (retention is 14d; a longer view is impossible here)"
ssh live-ro "$MY \"SELECT MIN(created_at) AS oldest, MAX(created_at) AS newest, COUNT(*) AS rows_logged FROM wp_fsmpt_email_logs;\""

echo
echo "-- outbound classified against the bell's types."
echo "-- CAUTION: do NOT match on 'group' — 'The Looth Group' is in every subject and"
echo "--          returns thousands of false positives."
ssh live-ro "$MY \"
SELECT 'OVERLAPPABLE mentioned'   k, COUNT(*) c, COUNT(DISTINCT \\\`to\\\`) r FROM wp_fsmpt_email_logs WHERE subject LIKE '%mentioned you%'
UNION ALL SELECT 'OVERLAPPABLE replied',  COUNT(*), COUNT(DISTINCT \\\`to\\\`) FROM wp_fsmpt_email_logs WHERE subject LIKE '%replied%'
UNION ALL SELECT 'no bell type: new-disc', COUNT(*), COUNT(DISTINCT \\\`to\\\`) FROM wp_fsmpt_email_logs WHERE subject LIKE '%New discussion:%'
UNION ALL SELECT 'no sender: connection',  COUNT(*), COUNT(DISTINCT \\\`to\\\`) FROM wp_fsmpt_email_logs WHERE subject REGEXP 'connect|friend|wants to'
UNION ALL SELECT 'no sender: dm',          COUNT(*), COUNT(DISTINCT \\\`to\\\`) FROM wp_fsmpt_email_logs WHERE subject REGEXP 'message|sent you'
UNION ALL SELECT 'no sender: reaction',    COUNT(*), COUNT(DISTINCT \\\`to\\\`) FROM wp_fsmpt_email_logs WHERE subject REGEXP 'react|liked';
\""

echo
echo "-- every forum bell row that has ever existed, with the last overlappable email."
echo "-- If the newest email PREDATES the oldest bell row, the comparable window is"
echo "-- empty and the overlap rate is UNTESTED, not zero-confirmed."
ssh live-ro "$PG -c \"
SELECT id, type, created_at, is_read FROM notifications
 WHERE type LIKE 'forum.%' ORDER BY created_at;
\""
ssh live-ro "$MY \"SELECT MAX(created_at) AS newest_overlappable_email FROM wp_fsmpt_email_logs WHERE subject LIKE '%mentioned you%' OR subject LIKE '%replied%';\""

echo
echo "=============================================================="
echo " AXIS 3 — already digested."
echo "=============================================================="
echo "-- (a) cadence drift: a constant 7d window cannot meet a drifting send time."
ssh live-ro "$MY \"SELECT id, LEFT(title,40) title, created_at FROM wp_fc_campaigns WHERE title LIKE '%Weekly Digest%' ORDER BY id DESC LIMIT 8;\""
echo "   Compare consecutive gaps to 7d00h00m: gap > 7d loses a band, gap < 7d duplicates one."

echo
echo "-- (b) RULE 3b: 'failed' recipients on a Weekly Digest campaign."
echo "--     NOT empty as of 2026-07-28: campaign 283 (June 1) lost 6 members to an"
echo "--     SES SignatureDoesNotMatch, never re-sent. Match on 'Weekly Digest', NOT"
echo "--     'Week of' — the wrong pattern here is what produced the false all-clear."
ssh live-ro "$MY \"
SELECT c.id, LEFT(c.title,40) title, e.status, COUNT(*) n
  FROM wp_fc_campaigns c JOIN wp_fc_campaign_emails e ON e.campaign_id = c.id
 WHERE c.title LIKE '%Weekly Digest%' AND e.status <> 'sent'
 GROUP BY 1,2,3 ORDER BY c.id DESC;
\""
echo "   (platform-wide failure history — NOTE 283 IS a digest; only 38 is not:)"
ssh live-ro "$MY \"SELECT campaign_id, COUNT(*) fails, LEFT(MIN(note),60) cause FROM wp_fc_campaign_emails WHERE status='failed' GROUP BY 1 ORDER BY 1 DESC;\""

echo
echo "-- (c) the backlog a floorless watermark would expose on its first send."
ssh live-ro "$PG -c \"
WITH b AS (
  SELECT n.user_uuid, count(*) c
    FROM notifications n LEFT JOIN connections c2 ON c2.id = n.connection_id
   WHERE n.created_at < now() - interval '7 days' AND n.is_read = false
     AND (n.connection_id IS NULL
          OR (n.type = 'connection_request' AND c2.status = 'pending')
          OR (n.type = 'connection_accept'  AND c2.status = 'accepted'))
   GROUP BY 1)
SELECT count(*) members, sum(c) items, round(avg(c),2) avg, max(c) worst,
       count(*) FILTER (WHERE c >= 5)  AS mem_5plus,
       count(*) FILTER (WHERE c >= 10) AS mem_10plus
  FROM b;
\""

echo
echo "-- (d) RULE 3b's PROPOSED WINDOW, demonstrated. No new schema: the window start"
echo "--     is the send time of the most recent digest THAT MEMBER ACTUALLY RECEIVED."
echo "--     Asked as of the June 22 digest, the two failed members below must reach"
echo "--     BACK to 2026-05-25, and the healthy controls must stay at 2026-06-01."
echo "--     964 + 1884 = real casualties of campaign 283. 1000 + 1001 = controls."
ssh live-ro "$MY \"
SELECT e.subscriber_id, MAX(c.created_at) AS window_start
  FROM wp_fc_campaigns c
  JOIN wp_fc_campaign_emails e ON e.campaign_id = c.id
 WHERE c.title LIKE 'Weekly Digest%' AND e.status = 'sent' AND c.id < 319
   AND e.subscriber_id IN (964, 1884, 1000, 1001)
 GROUP BY 1 ORDER BY 1;
\""
echo "   Expected: 964 -> 2026-05-25 | 1000 -> 2026-06-01 | 1001 -> 2026-06-01 | 1884 -> 2026-05-25"

echo
echo "Done. Nothing was written. See docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md."
