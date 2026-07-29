#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
set -uo pipefail
red=0
echo "=== GATE 1/7: visibility matrix (the privacy model) ==="
php /srv/profile-app/bin/visibility-matrix.php || red=1
echo
echo "=== GATE 2/7: web-craft gate (images / weight / eager scripts) ==="
python3 "$(dirname "$0")/craft-gate.py" || red=1
echo
echo "=== GATE 3/7: infra-sec gate (cookie auth / source disclosure / cdp) ==="
bash "$(dirname "$0")/infra-sec-gate.sh" || red=1
echo
echo "=== GATE 4/7: hub paragraph-collapse (content_html keeps its breaks) ==="
bash "$(dirname "$0")/hub-content-paragraph-gate.sh" || red=1
echo
echo "=== GATE 5/7: looth-auth-issue (non-REST mint bounce; recurs every DB reload) ==="
bash "$(dirname "$0")/looth-auth-issue-gate.sh" || red=1
echo
echo "=== GATE 6/7: event-date TZ (a UTC 'today' must not judge a site-local date) ==="
bash "$(dirname "$0")/event-date-tz-gate.sh" || red=1
echo
echo "=== GATE 7/7: composer topic-meta (forum picker cloning + tags) ==="
node "$(dirname "$0")/composer-topic-meta-test.js" || red=1
echo
# Two CDP/loopback gates are HELD OUT of the runner — they pass standalone but
# flake RED in-sequence (CDP under load / loopback /whoami trips infra's
# limit_req zone). Run them manually:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh          # bb-mirror forum-visibility (C2/H6)
#   bash "$(dirname "$0")/editor-rail-reachable-gate.sh"      # profile editor rail reachable @768 (CDP)
if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
echo "############ ALL GATES GREEN ############"
