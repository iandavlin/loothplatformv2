#!/bin/bash
# events-tz-window-proof.sh — the RUNTIME proof for the "events leave too soon" fix.
#
# THIS IS NOT A GATE, and it deliberately must not become one. The defect is only
# observable while UTC's calendar day is ahead of the site's — 00:00-04:00Z, i.e.
# 20:00-24:00 America/New_York. A runtime probe run at any other hour passes green
# under the BROKEN code, which is exactly why this shipped and nobody noticed. The
# standing check is the static tools/gates/event-date-tz-gate.sh; this script is the
# one-off "prove it on a running dev2" evidence run.
#
# WHY TWO FIXTURES. One event dated TODAY is not enough. If the only fixture is
# today's, then "pre-fix renders 0 cards" is indistinguishable from a broken harness,
# a dead DB handle, or a blank page. So the proof also carries an event dated
# TOMORROW, which BOTH trees must keep in every condition. The tomorrow event is the
# liveness control riding inside the same render:
#
#   in window (UTC day 20260729, site day 20260728):
#     pre-fix  today=20260729 -> today-event 20260728 >= 20260729 FALSE -> DROPPED
#                                tomorrow-event 20260729 >= 20260729 TRUE  -> kept
#     fixed    today=20260728 -> today-event    >= 20260728 TRUE -> kept
#                                tomorrow-event >= 20260728 TRUE -> kept
#   so pre-fix renders exactly ONE card and fixed renders TWO, and the missing one is
#   identified BY SLUG, not inferred from a count.
#
#   outside the window both trees keep both. That agreement is the CONTROL, not a pass.
#
# It never touches the serving checkout and never reloads nginx. Both instances run as
# uid `events`, the same user php-fpm's events pool runs as, so the real config.php
# reads the real /etc/lg-events-db at the real path and NO byte of either tree under
# test is modified.
#
# Usage: events-tz-window-proof.sh <old-tree> <new-tree> <today-slug> <tomorrow-slug> [outdir]
set -uo pipefail

OLD_TREE="${1:?old tree}"; NEW_TREE="${2:?new tree}"
SLUG_TODAY="${3:?slug of the event dated TODAY}"; SLUG_TOMORROW="${4:?slug of the event dated TOMORROW}"
OUT="${5:-/tmp/events-tz-proof}"; mkdir -p "$OUT"
OLD_PORT=8812; NEW_PORT=8811

utc_now=$(date -u '+%Y-%m-%d %H:%M:%S'); utc_ymd=$(date -u +%Y%m%d)
site_now=$(TZ=America/New_York date '+%Y-%m-%d %H:%M:%S'); site_ymd=$(TZ=America/New_York date +%Y%m%d)

echo "=============================================================="
echo " UTC now   : $utc_now   (UTC calendar day  $utc_ymd)"
echo " site now  : $site_now   (site calendar day $site_ymd)  America/New_York"
if [ "$utc_ymd" != "$site_ymd" ]; then
  echo " WINDOW    : OPEN — the clocks disagree, the defect is observable"
  in_window=1
else
  echo " WINDOW    : SHUT — both clocks agree, so this run is a CONTROL, not a proof."
  echo "             Outside 00:00-04:00Z the broken code is indistinguishable from"
  echo "             the fixed code. That is the bug's whole nature."
  in_window=0
fi
echo "=============================================================="

stamp=$(date -u +%Y%m%dT%H%M%SZ)
old_html="$OUT/old-$stamp.html"; new_html="$OUT/new-$stamp.html"
curl -s --max-time 30 "http://127.0.0.1:$OLD_PORT/" > "$old_html"
curl -s --max-time 30 "http://127.0.0.1:$NEW_PORT/" > "$new_html"

# --- ALSO read the REAL /events/ route, which is the strongest evidence available ---
# dev2's serve is running main, which still has the pre-fix gmdate('Ymd'). So the actual
# public route — through nginx, on the events php-fpm pool, unmodified — reproduces Ian's
# bug tonight all by itself. Reading it mutates nothing: no serve window, no checkout, no
# reload. Verified equivalent to the :8812 instance outside the window (both rendered 2
# cards, same slugs, same moment), so this is a cross-check on the harness as well.
# Degrades to UNAVAILABLE rather than failing the run if the gate env or the route is down.
serve_html="$OUT/serve-$stamp.html"; serve_state=UNAVAILABLE
GATE_ENV="$(dirname "$0")/../gates/gate-env.sh"
if [ -r "$GATE_ENV" ] && eval "$(bash "$GATE_ENV" 2>/dev/null | grep -E '^LG_GATE_(TOKEN|ADDR|DOMAIN)=')" 2>/dev/null \
   && [ -n "${LG_GATE_TOKEN:-}" ] && [ -n "${LG_GATE_ADDR:-}" ]; then
  if curl -s --max-time 30 --resolve "${LG_GATE_DOMAIN}:443:${LG_GATE_ADDR}" \
          -H "Cookie: loothdev_auth=${LG_GATE_TOKEN}" \
          "https://${LG_GATE_DOMAIN}/events/" > "$serve_html" && [ -s "$serve_html" ]; then
    serve_state=OK
  fi
fi

# grep -c counts LINES, not occurrences — count hits with -o|wc -l throughout.
has() { [ "$(grep -o "$2" "$1" | wc -l)" -gt 0 ] && echo PRESENT || echo ABSENT; }
cards() { grep -o 'lg-evland__card' "$1" | wc -l; }

o_today=$(has "$old_html" "$SLUG_TODAY");    o_tom=$(has "$old_html" "$SLUG_TOMORROW")
n_today=$(has "$new_html" "$SLUG_TODAY");    n_tom=$(has "$new_html" "$SLUG_TOMORROW")
if [ "$serve_state" = OK ]; then
  s_today=$(has "$serve_html" "$SLUG_TODAY"); s_tom=$(has "$serve_html" "$SLUG_TOMORROW")
  s_cards=$(cards "$serve_html")
else
  s_today=n/a; s_tom=n/a; s_cards=n/a
fi

printf '\n  %-24s %-11s cards=%-4s today=%-8s tomorrow=%s\n' \
  "SERVE /events/" "(real route)" "$s_cards" "$s_today" "$s_tom"
printf '  %-24s %-11s cards=%-4s today=%-8s tomorrow=%s\n' \
  "OLD $(git -C "$OLD_TREE" rev-parse --short HEAD)" "(pre-fix)" "$(cards "$old_html")" "$o_today" "$o_tom"
printf '  %-24s %-11s cards=%-4s today=%-8s tomorrow=%s\n\n' \
  "NEW $(git -C "$NEW_TREE" rev-parse --short HEAD)" "(fixed)" "$(cards "$new_html")" "$n_today" "$n_tom"
echo "  renders: $old_html"
echo "           $new_html"
[ "$serve_state" = OK ] && echo "           $serve_html  <- the REAL dev2 /events/, unmodified"
echo

# The SERVE corroborates but must NOT gate the verdict. It renders whatever is on main,
# and main can change under this script at any moment — keeper merging the fix and pulling
# is a NORMAL event, not a failure, and it would flip the serve from reproducing the bug to
# not reproducing it. Gating on it would turn "the fix shipped" into a red run.
serve_note() {
  case "$serve_state:$1" in
    OK:ABSENT)  echo "  SERVE: the REAL /events/ route drops today's event RIGHT NOW — Ian's bug,"
                echo "         on the actual public surface, running main's pre-fix bytes." ;;
    OK:PRESENT) echo "  SERVE: the real route KEEPS today's event, so main is no longer pre-fix —"
                echo "         the fix has most likely been merged and pulled. Not a failure." ;;
    *)          echo "  SERVE: not reachable this attempt; verdict rests on the two instances." ;;
  esac
}

if [ "$in_window" -eq 0 ]; then
  if [ "$o_today" = PRESENT ] && [ "$n_today" = PRESENT ] \
  && [ "$o_tom"   = PRESENT ] && [ "$n_tom"   = PRESENT ]; then
    echo "CONTROL HOLDS: outside the window both trees keep both events."
    echo "Proves the harness renders cards and both instances see the same store."
    echo "It is NOT a proof of the fix — re-run between 00:00Z and 04:00Z."
    exit 0
  fi
  echo "CONTROL FAILED: outside the window the trees should agree and both events"
  echo "should be listed. Something other than the timezone bug is wrong — investigate"
  echo "before trusting any in-window result."
  exit 1
fi

# ---- in the window: the whole claim, asserted by identity ----
if [ "$o_today" = ABSENT ] && [ "$o_tom" = PRESENT ] \
&& [ "$n_today" = PRESENT ] && [ "$n_tom" = PRESENT ]; then
  echo "PROVEN, on a running dev2, against the real store, with no faked clock:"
  echo "  pre-fix : TODAY'S event is GONE from /events/ — Ian's bug, reproduced"
  echo "  pre-fix : tomorrow's event still renders, so the page and DB are demonstrably"
  echo "            alive — the missing card is the bug, not a broken harness"
  echo "  fixed   : BOTH events render; today's event survives its own evening"
  serve_note "$s_today"
  exit 0
fi
echo "NOT PROVEN — in the window but the renders do not match the expected split."
echo "  expected  OLD today=ABSENT  tomorrow=PRESENT"
echo "            NEW today=PRESENT tomorrow=PRESENT"
echo "  observed  OLD today=$o_today tomorrow=$o_tom"
echo "            NEW today=$n_today tomorrow=$n_tom"
exit 1
