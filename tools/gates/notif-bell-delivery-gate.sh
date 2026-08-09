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

cleanup() {
  # Ordered so a failure in one still attempts the others.
  [ "${PREEXISTING:-1}" = "0" ] && pglooth "DELETE FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" >/dev/null
  pgapp "DELETE FROM notifications WHERE type='forum.followed_topic' AND target_id=$TOPIC
           AND user_uuid IN (SELECT u.uuid FROM users u JOIN wp_user_bridge b ON b.user_id=u.id
                              WHERE b.wp_user_id=$FOLLOWER) AND created_at > now() - interval '10 minutes';" >/dev/null
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

# ── Cleanup, asserted ───────────────────────────────────────────────────────
phase 'CLEANUP — the harness leaves nothing behind'
cleanup
left_n=$(rows_for_follower)
left_f=$(pglooth "SELECT count(*) FROM forums.topic_follow WHERE user_id=$FOLLOWER AND topic_id=$TOPIC;" | tr -d ' ')
ok "$([ "$left_n" = "0" ] && echo 1 || echo 0)" "no notification rows left behind" "got $left_n"
ok "$([ "$left_f" = "$PREEXISTING" ] && echo 1 || echo 0)" \
   "the follow store is back to how it was ($PREEXISTING)" "got $left_f"
trap - EXIT

echo
[ $fail -eq 0 ] && echo "GREEN — all $did assertions passed" || echo "RED — $fail of $did assertions failed"
exit $([ $fail -eq 0 ] && echo 0 || echo 1)
