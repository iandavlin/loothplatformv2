#!/bin/bash
# events-tap-navigates-gate.sh — on the events landing, a tap must NAVIGATE.
#
# IAN'S RULING (keeper-signed 2026-07-29): "on MOBILE, stop opening the event
# modal/sheet entirely — a tap on an event navigates straight to the event's post
# page." This gate exists because that is a product ruling encoded in an ABSENCE,
# and an absence is the easiest thing in a codebase to undo by accident. Nothing
# about `webroot/events-mobile.js` announces that a click handler must never come
# back; the next lane asked to "make the events list feel snappier" would add one
# in good faith. So the invariant is asserted, not trusted.
#
# WHAT WENT WRONG BEHIND THE RULING. The retired sheet carried two defects Ian hit
# from his phone on live event 72363, and both were defect classes, not typos:
#   • `.lev-cover` pinned height:170px around a 16:9 poster, so `cover` trimmed
#     ~81 image px off the top AND bottom — 22.6% of the height — cutting the
#     "August 2nd, 3PM" typeset into the artwork. (The landing card had it right
#     all along with aspect-ratio:16/9.)
#   • the description selector led with `.lg-event-header__detail`, which is the
#     when/where strip and whose only <p> is the byte-identical date line, so the
#     time rendered twice AND the real blurb never rendered at all.
# Retiring the sheet deleted the surface both lived on. This gate keeps it deleted.
#
# STATIC BY DESIGN. The honest runtime version needs a browser (one per box, a
# governed slot) and published events to tap — dev2 has ZERO published events, so
# a runtime probe there would pass green against any amount of breakage. Grep
# catches a reintroduced interception on any box, at any hour, with no engine.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1

red=0

# Strip comments before reading intent out of source. events-mobile.js
# deliberately DISCUSSES preventDefault in a comment explaining why it must never
# return; a gate that cannot tell code from prose would redden the very fix that
# satisfies it, get switched off, and then protect nothing.
decomment() { sed -e 's://.*::' -e '/\/\*/,/\*\//d' "$1" 2>/dev/null; }

# The three scripts /pwa.js loads on /events. Named explicitly rather than
# globbed, so adding a fourth events script is a deliberate edit here too.
EV_SCRIPTS="webroot/events-mobile.js webroot/events-live.js webroot/loothalong.js"

echo "--- the event sheet must not exist anywhere ---"
# Retired 2026-07-29. Any of these in CODE means a modal is being built again.
found=0
for pat in '#looth-ev-sheet' 'openEventSheet' 'lev-card' 'lev-cover'; do
  hit=$(grep -rln --include=*.js --include=*.php --include=*.css "$pat" webroot/ events/ 2>/dev/null \
        | while read -r f; do decomment "$f" | grep -q "$pat" && echo "$f"; done)
  if [ -n "$hit" ]; then
    echo "$hit" | sed "s|^|  RED  rebuilds the retired sheet ($pat): |"
    found=1
  fi
done
[ "$found" -ne 0 ] && red=1
[ "$found" -eq 0 ] && echo "  OK   no code builds the events bottom sheet"

echo "--- nothing may intercept a tap on an event card ---"
# Scoped to document/capture-phase handlers. NOT a ban on click listeners: the
# landing's search bar legitimately has one on its own clear button, and a blanket
# ban would fail correct code.
found=0
for f in $EV_SCRIPTS; do
  [ -f "$f" ] || continue
  hit=$(decomment "$f" | grep -nE "document\.addEventListener\(\s*['\"]click['\"]")
  if [ -n "$hit" ]; then echo "$hit" | sed "s|^|  RED  $f:|"; found=1; fi
done
# And no handler may single out the card, at any scope, in any file.
hit=$(grep -rl --include=*.js "lg-evland__card" webroot/ 2>/dev/null \
      | while read -r f; do decomment "$f" | grep -nE "closest\([^)]*lg-evland__card|matches\([^)]*lg-evland__card" \
      | sed "s|^|  RED  $f:|"; done)
if [ -n "$hit" ]; then echo "$hit"; found=1; fi
if [ "$found" -ne 0 ]; then
  echo
  echo "  ^ a tap on an event must reach the anchor and navigate to /event/<slug>/."
  echo "    Ian retired the modal on 2026-07-29; intercepting the click brings it back."
  red=1
else
  echo "  OK   no document-level click handler, no card interception"
fi

echo "--- the card must remain a real link with a real destination ---"
# The ruling is satisfied by the anchor ALREADY carrying the permalink. If the
# card stops being an <a href>, "don't intercept the click" silently becomes
# "tapping does nothing" — which passes the checks above and is worse than the bug.
if grep -qE '<a class="lg-evland__card" href="' events/web/index.php 2>/dev/null; then
  echo "  OK   events/web/index.php emits <a class=\"lg-evland__card\" href=...>"
else
  echo "  RED  events/web/index.php must render the card as an anchor with an href"
  red=1
fi
if grep -qF "LG_EVENTS_EVENT_BASE" events/lib/events-query.php 2>/dev/null; then
  echo "  OK   the href is the event permalink (LG_EVENTS_EVENT_BASE . slug)"
else
  echo "  RED  the card href must be the event permalink, not a placeholder"
  red=1
fi

echo
if [ "$red" -ne 0 ]; then echo "events-tap-navigates gate: RED"; exit 1; fi
echo "events-tap-navigates gate: GREEN"
