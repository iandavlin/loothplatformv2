#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
set -uo pipefail

# THREE STATES, not two. A gate that CANNOT RUN is louder than one that fails:
# reporting "red" for a gate that never executed is indistinguishable from real
# findings, and gate 2 spent weeks looking red while it was in fact dead. By
# convention here: exit 0 = green, exit 1 = RED (real findings), exit 2 = CANNOT
# RUN (no verdict — missing engine, unmintable cookies, an unresponsive CDP).
red=0
dead=0
run() {  # run <label> <command...>
  "${@:2}"
  case $? in
    0) ;;
    2) dead=$((dead+1)); echo "  ^^ $1 produced NO VERDICT (exit 2)";;
    *) red=1;;
  esac
}
echo "=== GATE 1/9: visibility matrix (the privacy model) ==="
run "visibility matrix" php /srv/profile-app/bin/visibility-matrix.php
echo
echo "=== GATE 2/9: web-craft gate (images / weight / eager scripts) ==="
run "web-craft" python3 "$(dirname "$0")/craft-gate.py"
echo
echo "=== GATE 3/9: infra-sec gate (cookie auth / source disclosure / cdp) ==="
run "infra-sec" bash "$(dirname "$0")/infra-sec-gate.sh"
echo
echo "=== GATE 4/9: hub paragraph-collapse (content_html keeps its breaks) ==="
run "hub-paragraph" bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
echo
echo "=== GATE 5/9: looth-auth-issue (non-REST mint bounce; recurs every DB reload) ==="
run "looth-auth" bash "$(dirname "$0")/looth-auth-issue-gate.sh"
echo
echo "=== GATE 6/9: event-date TZ (a UTC 'today' must not judge a site-local date) ==="
run "event-date-tz" bash "$(dirname "$0")/event-date-tz-gate.sh"
echo
echo "=== GATE 7/9: events tap NAVIGATES (Ian retired the mobile modal 2026-07-29) ==="
run "events-tap-navigates" bash "$(dirname "$0")/events-tap-navigates-gate.sh"
echo
echo "=== GATE 8/9: composer topic-meta (forum picker cloning + tags) ==="
run "composer-topic-meta" node "$(dirname "$0")/composer-topic-meta-test.js"
echo
echo "=== GATE 9/9: author socials RESOLVE, never mirror (byline drift class) ==="
run "author-socials-live" bash "$(dirname "$0")/author-socials-live-gate.sh"
echo
# Two CDP/loopback gates are HELD OUT of the runner — they pass standalone but
# flake RED in-sequence (CDP under load / loopback /whoami trips infra's
# limit_req zone). Run them manually:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh          # bb-mirror forum-visibility (C2/H6)
#   bash "$(dirname "$0")/editor-rail-reachable-gate.sh"      # profile editor rail reachable @768 (CDP)
if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES INCOMPLETE — $dead gate(s) COULD NOT RUN ############"
  echo "Nothing red, but $dead gate(s) reached no verdict, so this is NOT green."
  echo "Fix the environment and re-run before treating this as a pass."
  exit 2
fi
echo "############ ALL GATES GREEN ############"
