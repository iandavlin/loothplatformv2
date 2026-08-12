#!/bin/bash
# notif-bell-delivery-gate.sh — a FOLLOW actually produces a BELL ROW. End to end.
#
#     bash tools/gates/notif-bell-delivery-gate.sh
#
# notif-bridge lane, 2026-08-09.
#
# ── WHY THIS EXISTS, IN RULING 6's OWN WORDS ────────────────────────────────
# IAN-RULINGS §6: "do not let its gate claim bell notifications ARRIVE — that is the
# bridge's contract, not the composer's." Gate 18 proves the composer WRITES
# forums.topic_follow. Nothing proved the other half, and the other half is this
# lane's. Ian made the bell the DEFAULT follow channel, so "a follow row exists" is
# worth nothing to a member unless it becomes a notification they can see.
#
# Every existing check stops one step short:
#   gate 18                     composer -> topic_follow row          (writes it)
#   notif-followers-proof       topic_follow row -> leg 4 sees it     (reads it)
#   notif-renderer-parity       a row -> a sentence on both bells     (renders it)
#   THIS                        a follow -> a row actually EXISTS     (delivers it)
# Three green gates and a member who still hears nothing. That is the seam.
#
# ── IT CROSSES TWO DATABASES AND TWO OS USERS, WHICH IS THE WHOLE DIFFICULTY ─
# forums.topic_follow is Postgres `looth`, peer-auth as looth-dev. The bell is
# Postgres `profile_app`, peer-auth as profile-app. Neither user can see the other's
# database, and the write between them is an HTTP POST over loopback into a THIRD
# process. So this orchestrates as `ubuntu` and sudos to each side in turn — which is
# also why it can never be a unit test.
#
# ⚠️ IT WRITES REAL ROWS AND CLEANS UP AFTER ITSELF. The follow row must be COMMITTED
# (the bell's writer is a different process and cannot see an open transaction), and
# the notification lands in a database this script cannot roll back. So cleanup is
# explicit, runs on every exit path via trap, and is ASSERTED — a harness that leaves
# fixtures in a real table is worse than no harness. It refuses to run against
# anything but dev2.
#
# Exit 0 = green, 1 = RED, 2 = CANNOT RUN.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
fail=0; did=0
ok() { did=$((did+1)); if [ "$1" = "1" ]; then echo "  PASS  $2"; else fail=$((fail+1)); echo "  RED   $2${3:+  — $3}"; fi; }
phase() { echo; echo "=== $1 ==="; }

# ── Refuse to run anywhere but dev2 ──────────────────────────────────────────
# trap-live-lg-env-says-dev2: LG_ENV cannot tell live from dev2, so key on the
# public host, which can.
host="$(grep -h '^LG_PUBLIC_HOST=' /etc/looth/env 2>/dev/null | cut -d= -f2 | tr -d '\"')"
if [ "$host" != "dev2.loothgroup.com" ]; then
  echo "CANNOT RUN: this writes real rows and only runs on dev2 (LG_PUBLIC_HOST=$host)"
  exit 2
fi
for u in looth-dev profile-app; do
  sudo -n -u "$u" true 2>/dev/null || { echo "CANNOT RUN: no passwordless sudo to $u"; exit 2; }
done

pgapp() { sudo -n -u profile-app psql -d profile_app -tAc "$1" 2>/dev/null; }
pglooth() { sudo -n -u looth-dev psql -d looth -tAc "$1" 2>/dev/null; }

# ── Fixtures ────────────────────────────────────────────────────────────────
TOPIC=$(sudo -n -u looth-dev wp --path=/var/www/dev db query "
  SELECT p.ID FROM wp_posts p
    JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_bbp_forum_id'
    JOIN wp_posts f ON f.ID=m.meta_value
   WHERE p.post_type='topic' AND p.post_status='publish'
     AND p.post_name<>'' AND f.post_name<>'' ORDER BY p.ID DESC LIMIT 1;" 2>/dev/null \
  | grep -E '^[0-9]+$' | tail -1)
# NB: `wp db query` prints a header AND a trailing blank line, so `tail -1` alone
# yields the empty string and every fixture silently reads as absent. Numeric filter.
[ -n "${TOPIC:-}" ] || { echo "CANNOT RUN: no topic with a resolvable deep link"; exit 2; }

AUTHOR=$(sudo -n -u looth-dev wp --path=/var/www/dev db query \
  "SELECT post_author FROM wp_posts WHERE ID=$TOPIC;" 2>/dev/null | grep -E '^[0-9]+$' | tail -1)

# The follower must be BRIDGED (an unbridged recipient is skipped by design and would
# make a green here meaningless) and must NOT be the topic author, or leg 3 claims
# them first and we would be measuring the wrong leg.
FOLLOWER=$(pgapp "SELECT b.wp_user_id FROM wp_user_bridge b JOIN users u ON u.id=b.user_id
                   WHERE b.wp_user_id <> $AUTHOR ORDER BY b.wp_user_id LIMIT 1;" | tr -d ' ')
[ -n "${FOLLOWER:-}" ] || { echo "CANNOT RUN: no bridged member to act as follower"; exit 2; }

echo "topic=$TOPIC (author $AUTHOR)   follower=wp:$FOLLOWER"

PREEXISTING=$(pglooth "SELECT count(*) FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" | tr -d ' ')
# Everything this run raises is scoped by (fixture topic, created after START), so
# cleanup cannot reach a real member's real notification no matter which leg fired.
START=$(pgapp "SELECT now()::text;")

cleanup() {
  # Ordered so a failure in one still attempts the others.
  [ "${PREEXISTING:-1}" = "0" ] && pglooth "DELETE FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" >/dev/null
  pgapp "DELETE FROM notifications WHERE target_id=$TOPIC AND created_at >= '$START';" >/dev/null
}
trap cleanup EXIT

# Fire leg 4 in isolation: the reply's author IS the topic author, so leg 3 self-skips;
# no parent reply, so leg 2 cannot fire; content with no '@', so leg 1 cannot fire.
# Whatever arrives is leg 4 or nothing.
fire() {
  sudo -n -u looth-dev php -d error_reporting=0 -r "
    \$_SERVER['HTTP_HOST']='dev2.loothgroup.com'; \$_SERVER['REQUEST_URI']='/';
    require '/var/www/dev/wp-load.php';
    require '$ROOT/lg-shared/notify-bridge.php';
    lg_notify_on_reply($TOPIC, $TOPIC, $AUTHOR, 0, 'plain body, no mentions here');
    echo 'fired';
  " 2>/dev/null | tail -1
}
# Fire an arbitrary reply shape, so each leg can be driven in isolation.
fire_as() {  # fire_as <reply_id> <author_wp> <parent_reply_id> <content>
  sudo -n -u looth-dev php -d error_reporting=0 -r "
    \$_SERVER['HTTP_HOST']='dev2.loothgroup.com'; \$_SERVER['REQUEST_URI']='/';
    require '/var/www/dev/wp-load.php';
    require '$ROOT/lg-shared/notify-bridge.php';
    lg_notify_on_reply($TOPIC, $1, $2, $3, '$4');
    echo 'fired';
  " 2>/dev/null | tail -1
}
rows_of() {  # rows_of <wp_id> <type>
  pgapp "SELECT count(*) FROM notifications n
           JOIN users u ON u.uuid=n.user_uuid JOIN wp_user_bridge b ON b.user_id=u.id
          WHERE b.wp_user_id=$1 AND n.type='$2' AND n.target_id=$TOPIC
            AND n.created_at >= '$START';" | tr -d ' '
}
rows_for_follower() {
  pgapp "SELECT count(*) FROM notifications n
           JOIN users u ON u.uuid=n.user_uuid JOIN wp_user_bridge b ON b.user_id=u.id
          WHERE b.wp_user_id=$FOLLOWER AND n.type='forum.followed_topic' AND n.target_id=$TOPIC;" | tr -d ' '
}

# ── PHASE 1 — RED-FIRST: no follow row => no bell ───────────────────────────
phase 'PHASE 1 — RED-FIRST: with NO follow row, the same event rings NOTHING'
# Without this, phase 2 proves only "a notification exists", not "the FOLLOW caused
# it" — and a pre-existing row would make the whole gate a tautology.
#
# ⚠️ THIS PHASE CANNOT STAND ALONE: it is green whenever nothing is delivered, which
# includes every way the bridge can die (an unresolvable deep link returns early, a
# wrong Host lands the POST on another vhost, profile-app 500s). PHASE 2 IS ITS
# LIVENESS CONTROL and the two must be read together — exactly the pairing rule in
# feedback-absence-assertion-needs-liveness. The first run of this gate was RED on
# phase 2 while phase 1 sat there green, and that is precisely how it found the Host
# defect rather than concluding "no follow, no bell, working as intended".
pglooth "DELETE FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" >/dev/null
pgapp "DELETE FROM notifications WHERE type='forum.followed_topic' AND target_id=$TOPIC
         AND user_uuid IN (SELECT u.uuid FROM users u JOIN wp_user_bridge b ON b.user_id=u.id
                            WHERE b.wp_user_id=$FOLLOWER);" >/dev/null
ok "$([ "$(rows_for_follower)" = "0" ] && echo 1 || echo 0)" "baseline is clean (0 rows)"
[ "$(fire)" = "fired" ] || { echo "CANNOT RUN: the bridge call did not complete"; exit 2; }
ok "$([ "$(rows_for_follower)" = "0" ] && echo 1 || echo 0)" \
   "no follow row => still 0 bell rows" "got $(rows_for_follower) — something else is raising these"

# ── PHASE 2 — the contract ──────────────────────────────────────────────────
phase 'PHASE 2 — a follow row => a bell row the member can actually see'
pglooth "INSERT INTO forums.topic_follow(user_id,topic_id) VALUES ($FOLLOWER,$TOPIC)
         ON CONFLICT DO NOTHING;" >/dev/null
ok "$([ "$(pglooth "SELECT count(*) FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" | tr -d ' ')" = "1" ] && echo 1 || echo 0)" \
   "the follow row is committed and visible to another process"

[ "$(fire)" = "fired" ] || { echo "CANNOT RUN: the bridge call did not complete"; exit 2; }
n=$(rows_for_follower)
ok "$([ "$n" = "1" ] && echo 1 || echo 0)" "DELIVERED: exactly 1 forum.followed_topic row exists" "got $n"

# The row has to be USABLE, not merely present — presence is not reachability.
url=$(pgapp "SELECT n.target_url FROM notifications n
               JOIN users u ON u.uuid=n.user_uuid JOIN wp_user_bridge b ON b.user_id=u.id
              WHERE b.wp_user_id=$FOLLOWER AND n.type='forum.followed_topic' AND n.target_id=$TOPIC
              ORDER BY n.id DESC LIMIT 1;")
ok "$(echo "$url" | grep -q '^/hub/?topic=' && echo 1 || echo 0)" \
   "it carries a real hub deep link" "target_url=$url"
unread=$(pgapp "SELECT n.is_read FROM notifications n
                  JOIN users u ON u.uuid=n.user_uuid JOIN wp_user_bridge b ON b.user_id=u.id
                 WHERE b.wp_user_id=$FOLLOWER AND n.type='forum.followed_topic' AND n.target_id=$TOPIC
                 ORDER BY n.id DESC LIMIT 1;")
ok "$([ "$unread" = "f" ] && echo 1 || echo 0)" "and it is UNREAD, so the bell will show it" "is_read=$unread"

# ── PHASE 3 — a re-fire coalesces, it does not pile up ──────────────────────
phase 'PHASE 3 — a second reply coalesces onto the same row'
[ "$(fire)" = "fired" ] || { echo "CANNOT RUN"; exit 2; }
n2=$(rows_for_follower)
ok "$([ "$n2" = "1" ] && echo 1 || echo 0)" "still exactly 1 row, not 2" "got $n2"

# ── PHASE 4 — the other three legs deliver too ──────────────────────────────
phase 'PHASE 4 — legs 1-3 deliver (the ones that need NO subscription at all)'
# Leg 4 is the opt-in rung and was the whole reason this gate exists. But legs 1-3
# fire for people holding ZERO subscriptions — a reply to your discussion, a reply to
# your comment, an @mention — which is most members most of the time. The 2026-08-01
# trace reconciled them against live's access log; nothing ASSERTED them, so a
# regression in any of the three would be invisible until someone complained.
#
# Each leg is driven in isolation by choosing the authorship so the others cannot
# claim the recipient first ($notified runs mention > reply_to_reply > reply_to_topic).
REPLY=$(sudo -n -u looth-dev wp --path=/var/www/dev db query "
  SELECT r.ID FROM wp_posts r JOIN wp_posts t ON t.ID=r.post_parent
   WHERE r.post_type='reply' AND r.post_status='publish' AND r.post_parent=$TOPIC
     AND r.post_author<>0 ORDER BY r.ID DESC LIMIT 1;" 2>/dev/null | grep -E '^[0-9]+$' | tail -1)
RAUTH=$(sudo -n -u looth-dev wp --path=/var/www/dev db query \
  "SELECT post_author FROM wp_posts WHERE ID=${REPLY:-0};" 2>/dev/null | grep -E '^[0-9]+$' | tail -1)

if [ -z "${REPLY:-}" ] || [ -z "${RAUTH:-}" ]; then
  echo "  SKIP  no published reply on the fixture topic — legs 1-3 not exercised"
else
  # LEG 3 — reply to a TOPIC you authored. Reply author is anyone but the topic
  # author, so leg 3 is the only leg with a recipient.
  fire_as "$REPLY" "$RAUTH" 0 'plain body, no mentions' >/dev/null
  ok "$([ "$(rows_of "$AUTHOR" forum.reply_to_topic)" = "1" ] && echo 1 || echo 0)" \
     "leg 3: the TOPIC author (wp:$AUTHOR) got forum.reply_to_topic" \
     "got $(rows_of "$AUTHOR" forum.reply_to_topic)"

  # LEG 2 — reply to a REPLY you wrote. Parent is $REPLY (author $RAUTH); the new
  # reply's author is the TOPIC author, so leg 3 self-skips and only leg 2 has a
  # recipient. Requires $RAUTH != $AUTHOR, which the fixture query guarantees.
  if [ "$RAUTH" != "$AUTHOR" ]; then
    fire_as "$REPLY" "$AUTHOR" "$REPLY" 'plain body, no mentions' >/dev/null
    ok "$([ "$(rows_of "$RAUTH" forum.reply_to_reply)" = "1" ] && echo 1 || echo 0)" \
       "leg 2: the PARENT-REPLY author (wp:$RAUTH) got forum.reply_to_reply" \
       "got $(rows_of "$RAUTH" forum.reply_to_reply)"
  else
    echo "  SKIP  leg 2: fixture reply shares its author with the topic"
  fi

  # LEG 1 — @mention, in the canonical minted form the composer writes. The mention
  # must WIN over the other legs, so this fires with the topic author as the mentioned
  # party: if leg 1 did not claim them first they would get reply_to_topic instead,
  # and asserting the TYPE is what catches that.
  pgapp "DELETE FROM notifications WHERE target_id=$TOPIC AND created_at >= '$START';" >/dev/null
  fire_as "$REPLY" "$RAUTH" 0 "hey {{mention_user_id_${AUTHOR}}} take a look" >/dev/null
  ok "$([ "$(rows_of "$AUTHOR" forum.mention)" = "1" ] && echo 1 || echo 0)" \
     "leg 1: the MENTIONED member (wp:$AUTHOR) got forum.mention" \
     "got $(rows_of "$AUTHOR" forum.mention)"
  ok "$([ "$(rows_of "$AUTHOR" forum.reply_to_topic)" = "0" ] && echo 1 || echo 0)" \
     "…and the mention WON — no duplicate reply_to_topic row for the same event" \
     "got $(rows_of "$AUTHOR" forum.reply_to_topic) — one event raised two rows"
fi

# ── Cleanup, asserted ───────────────────────────────────────────────────────
phase 'CLEANUP — the harness leaves nothing behind'
cleanup
# ⚠️ ASSERT WHAT THE CLEANUP ACTUALLY COVERS, NOT ONE CORNER OF IT. This first
# checked only the follower's rows — while phase 4 raises rows for the TOPIC author
# and the parent-reply author too, neither of which that check could see. A cleanup
# assertion narrower than the cleanup itself is worse than none: it reports "nothing
# left behind" while rows sit in a real table for real members.
left_all=$(pgapp "SELECT count(*) FROM notifications WHERE target_id=$TOPIC AND created_at >= '$START';" | tr -d ' ')
ok "$([ "$left_all" = "0" ] && echo 1 || echo 0)" \
   "EVERY row this run raised is gone, for every recipient" "got $left_all"
left_n=$(rows_for_follower)
left_f=$(pglooth "SELECT count(*) FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" | tr -d ' ')
ok "$([ "$left_n" = "0" ] && echo 1 || echo 0)" "no notification rows left behind" "got $left_n"
ok "$([ "$left_f" = "$PREEXISTING" ] && echo 1 || echo 0)" \
   "the follow store is back to how it was ($PREEXISTING)" "got $left_f"
trap - EXIT

echo
[ $fail -eq 0 ] && echo "GREEN — all $did assertions passed" || echo "RED — $fail of $did assertions failed"
exit $([ $fail -eq 0 ] && echo 0 || echo 1)
