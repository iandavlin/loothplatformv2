#!/bin/bash
# event-date-tz-gate.sh — a UTC "today" must never judge a SITE-LOCAL stored date.
#
# THE DEFECT CLASS (found three times before it was gated, Ian's report 2026-07-28):
# `events_start_date_and_time_` holds a bare calendar date (Ymd) that an editor typed
# in SITE-LOCAL time. Comparing it against gmdate('Ymd') — a UTC calendar day — makes
# every event fall out of "upcoming" at 20:00 America/New_York (19:00 in winter),
# hours before the day it belongs to has ended. For an evening event the listing drops
# it at the exact minute it starts.
#
# Measured on live: event 72327 "Frank Brothers process and shop tour", start 20260727
# 8:00 pm, was absent from /events/ at 22:14 local — it had vanished at 20:00.
#
# THE RULE: any "today" compared against one of these stored calendar dates must come
# from the SITE's timezone.
#   • WP-booted code   -> current_time('Ymd')          (see lg-weekly-digest/includes/
#                                                        class-lg-wd-query.php:232)
#   • no-WP surfaces   -> lg_events_today_ymd()        (events/lib/events-query.php),
#                         which reads wp_options.timezone_string from the DB it is
#                         already connected to.
#
# This gate is deliberately a STATIC check: the bug is only observable for 4–5 hours a
# day, so a runtime probe would pass green all morning and hide it. Grepping the source
# catches it at any hour.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1

red=0

# SCOPE THIS TIGHTLY. A blanket ban on gmdate('Ymd') is WRONG and the first draft of
# this gate proved it: it reddened profile-app/src/R2.php and MessageR2.php, whose
# `$dateStamp = gmdate('Ymd')` is an AWS SigV4 credential scope and MUST be UTC. A
# gate that fails correct code gets switched off, and then it protects nothing.
#
# The real class is narrow: a UTC "today" judging the SITE-LOCAL calendar date stored
# in `events_start_date_and_time_`. So only inspect files that touch that meta key,
# and only look at CODE — comments naming the bug (this fix adds several) are not it.
echo "--- files touching events_start_date_and_time_ must not build 'today' from UTC ---"
consumers=$(grep -rl "events_start_date_and_time_" --include=*.php . 2>/dev/null \
             | grep -v '/node_modules/' | grep -v '/vendor/')
found=0
for f in $consumers; do
  # drop comment lines (* … , // … , /* … , # …) before looking for the call
  hit=$(grep -nE "gmdate\(\s*['\"]Ymd['\"]\s*\)" "$f" 2>/dev/null \
         | grep -vE '^[0-9]+:[[:space:]]*(\*|//|/\*|#)')
  if [ -n "$hit" ]; then
    echo "$hit" | sed "s|^|  RED  $f:|"
    found=1
  fi
done
if [ "$found" -ne 0 ]; then
  echo
  echo "  ^ a UTC calendar day judging an editor-entered site-local date drops rows"
  echo "    4-5 hours early. Use current_time('Ymd') under WP, or lg_events_today_ymd()"
  echo "    on a no-WP surface. (Unrelated UTC uses — AWS SigV4 stamps, etc. — are fine"
  echo "    and are intentionally out of this gate's scope.)"
  red=1
else
  echo "  OK   $(echo "$consumers" | grep -c .) event-date consumers, none using a UTC today"
fi

# The three known consumers must each resolve "today" the right way. Named explicitly
# so that deleting the call — rather than fixing it — cannot quietly pass the gate.
echo "--- the known event-listing consumers resolve today site-locally ---"
check() { # <file> <required-substring> <label>
  if [ ! -f "$1" ]; then echo "  SKIP $3 (file absent: $1)"; return; fi
  if grep -qF "$2" "$1"; then
    echo "  OK   $3"
  else
    echo "  RED  $3 — expected '$2' in $1"
    red=1
  fi
}
check events/lib/events-query.php                    "lg_events_today_ymd()" "events app (/events/ listing)"
check lg-events-shortcode.php                        "current_time('Ymd')"   "[looth_events] shortcode"
check lg-patreon-stripe-poller/src/Wp/UpcomingEvents.php "current_time( 'Ymd' )" "poller UpcomingEvents widget"

# And the resolver itself must not silently fall back to UTC — a UTC default would
# reintroduce the exact bug on any box whose timezone_string is unset.
echo "--- the no-WP resolver must not default to UTC ---"
if grep -qF "America/New_York" events/lib/events-query.php 2>/dev/null \
   && ! grep -qE "new DateTimeZone\(\s*['\"]UTC['\"]\s*\)" events/lib/events-query.php 2>/dev/null; then
  echo "  OK   lg_events_today_ymd() falls back to the platform locale, not UTC"
else
  echo "  RED  lg_events_today_ymd() must fall back to a real locale, never UTC"
  red=1
fi

echo
if [ "$red" -ne 0 ]; then echo "event-date-tz gate: RED"; exit 1; fi
echo "event-date-tz gate: GREEN"
