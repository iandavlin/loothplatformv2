#!/bin/bash
# events-tz-window-proof.sh — the RUNTIME proof for the "events leave too soon" fix.
#
# THIS IS NOT A GATE, and it deliberately must not become one. The defect is only
# observable while UTC's calendar day is ahead of the site's — 00:00-04:00Z, i.e.
# 20:00-24:00 America/New_York. A runtime probe run at any other hour passes green
# under the BROKEN code, which is exactly why this shipped and nobody noticed. The
# standing check is the static one, tools/gates/event-date-tz-gate.sh; this script
# is the one-off "prove it on a running dev2" evidence run.
#
# WHAT IT DOES. Stands two instances of the events app side by side against the SAME
# real dev2 WP database — one from the pre-fix tree, one from the fixed tree — and
# renders /events/ from both at the same instant. Inside the window they must
# DISAGREE: the pre-fix page loses the event entirely (the landing page renders only
# the "Upcoming" bucket, so a dropped event becomes "No upcoming events scheduled").
# Outside the window they must AGREE — that agreement is the control, not a pass.
#
# It never touches the serving checkout and never reloads nginx. Both instances run
# as uid `events`, the same user php-fpm's events pool runs as, so the real
# config.php reads the real /etc/lg-events-db at the real path and NO byte of either
# tree under test is modified.
#
# Usage: events-tz-window-proof.sh <old-tree> <new-tree> <fixture-ymd> [outdir]
set -uo pipefail

OLD_TREE="${1:?old tree}"; NEW_TREE="${2:?new tree}"; FIXTURE_YMD="${3:?fixture Ymd}"
OUT="${4:-/tmp/events-tz-proof}"; mkdir -p "$OUT"
OLD_PORT=8812; NEW_PORT=8811

utc_now=$(date -u '+%Y-%m-%d %H:%M:%S'); utc_ymd=$(date -u +%Y%m%d)
site_now=$(TZ=America/New_York date '+%Y-%m-%d %H:%M:%S'); site_ymd=$(TZ=America/New_York date +%Y%m%d)

echo "=============================================================="
echo " UTC now   : $utc_now   (UTC calendar day  $utc_ymd)"
echo " site now  : $site_now   (site calendar day $site_ymd)  America/New_York"
echo " fixture   : event dated $FIXTURE_YMD"
if [ "$utc_ymd" != "$site_ymd" ]; then
  echo " WINDOW    : OPEN — the two clocks disagree, the defect is observable"
  in_window=1
else
  echo " WINDOW    : SHUT — both clocks agree, so this run is a CONTROL, not a proof."
  echo "             Outside 00:00-04:00Z the broken code is indistinguishable from"
  echo "             the fixed code. That is the bug's whole nature. Re-run in window."
  in_window=0
fi
echo "=============================================================="

fetch() { curl -s --max-time 30 "http://127.0.0.1:$1/" ; }

old_html="$OUT/old-${utc_ymd}-$(date -u +%H%M%S).html"
new_html="$OUT/new-${utc_ymd}-$(date -u +%H%M%S).html"
fetch "$OLD_PORT" > "$old_html"; fetch "$NEW_PORT" > "$new_html"

# The discriminator is the event card, not a title substring: the landing page
# renders ONLY the Upcoming bucket, so a dropped event leaves the empty-state behind.
old_cards=$(grep -o 'lg-evland__card' "$old_html" | wc -l)   # grep -c counts LINES; this counts hits
new_cards=$(grep -o 'lg-evland__card' "$new_html" | wc -l)
old_empty=$(grep -o 'lg-evland__empty' "$old_html" | wc -l)
new_empty=$(grep -o 'lg-evland__empty' "$new_html" | wc -l)

printf '\n  %-28s cards=%s  empty-state=%s  %s\n' "OLD ($(git -C "$OLD_TREE" rev-parse --short HEAD))" "$old_cards" "$old_empty" "$old_html"
printf '  %-28s cards=%s  empty-state=%s  %s\n\n' "NEW ($(git -C "$NEW_TREE" rev-parse --short HEAD))" "$new_cards" "$new_empty" "$new_html"

if [ "$in_window" -eq 0 ]; then
  if [ "$old_cards" -eq "$new_cards" ]; then
    echo "CONTROL HOLDS: outside the window both trees render the same $old_cards card(s)."
    echo "Proves the harness works and both instances see the same store. NOT a proof of the fix."
    exit 0
  fi
  echo "CONTROL FAILED: the trees differ OUTSIDE the window. Something else is wrong — investigate."
  exit 1
fi

if [ "$old_cards" -eq 0 ] && [ "$new_cards" -ge 1 ]; then
  echo "PROVEN. In the window, on a running dev2, against the real store:"
  echo "  pre-fix  -> the event is GONE from /events/ (empty state) — Ian's bug, reproduced"
  echo "  fixed    -> the event is STILL THERE"
  exit 0
fi
echo "NOT PROVEN — in the window but the renders do not show the expected split."
echo "  expected old_cards=0 and new_cards>=1; got old=$old_cards new=$new_cards"
exit 1
