#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
#
# EXIT CONVENTION — every gate MUST follow it, and the runner depends on it:
#
#   0  PASS        the gate ran and found nothing
#   1  RED         the gate ran and found a defect
#   2  CANNOT RUN  the gate could NOT do its job (no host/token, fixture missing,
#                  dependency absent, mint failed). It is protecting NOTHING.
#
# WHY 2 EXISTS AND WHY IT SHOUTS LOUDER THAN RED
# ----------------------------------------------
# A RED gate is working: it found the thing it was built to find, and it stops the
# push. A gate that cannot run is LYING — it produces no failures, so under the old
# `cmd || red=1` runner it was indistinguishable from a gate that passed cleanly.
# That is not hypothetical: the craft gate sat dead for WEEKS while everyone read the
# absence of complaints as "green", because its token had left the vhost and its
# fixture had been archived. Nobody was told. The whole point of the suite is to
# notice what nobody is watching for, so the one failure it must never be quiet
# about is its own.
#
# Consequence: CANNOT RUN blocks the push exactly like RED, and is reported LAST so
# it is the final thing on screen — you cannot scroll past it.
set -uo pipefail
red=0
dead=0
declare -a RED_GATES=() DEAD_GATES=()

# run_gate <n/total> <description> <command...>
run_gate() {
  local tag="$1" desc="$2"; shift 2
  echo "=== GATE $tag: $desc ==="
  "$@"
  local rc=$?
  case "$rc" in
    0) echo "--- GATE $tag PASS" ;;
    1) echo "--- GATE $tag RED — it ran and found a defect"
       red=1; RED_GATES+=("$tag $desc") ;;
    *) # 2 by convention, but ANY other code lands here on purpose: 127 (script
       # missing), 126 (not executable) and 139 (segfault) all mean the gate did
       # not do its job, and none of them may read as a pass.
       echo "--- GATE $tag !!! CANNOT RUN (exit $rc) — this gate checked NOTHING"
       dead=1; DEAD_GATES+=("$tag $desc (exit $rc)") ;;
  esac
  echo
}

# Prefer the copy that ships in THIS tree over the absolute serve path, so the
# runner exercises the gates it was checked out with (and a lane can prove a
# harness repair without a serve flip). Falls back to /srv for an installed
# runner whose tree layout differs.
VM="$(dirname "$0")/../../profile-app/bin/visibility-matrix.php"
[ -r "$VM" ] || VM=/srv/profile-app/bin/visibility-matrix.php

run_gate "1/5" "visibility matrix (the privacy model)"                          php "$VM"
run_gate "2/5" "web-craft gate (images / weight / eager scripts)"               python3 "$(dirname "$0")/craft-gate.py"
run_gate "3/5" "infra-sec gate (cookie auth / source disclosure / cdp)"         bash "$(dirname "$0")/infra-sec-gate.sh"
run_gate "4/5" "hub paragraph-collapse (content_html keeps its breaks)"         bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
run_gate "5/5" "looth-auth-issue (non-REST mint bounce; recurs every DB reload)" bash "$(dirname "$0")/looth-auth-issue-gate.sh"

# Two CDP/loopback gates are HELD OUT of the runner — they pass standalone but
# flake RED in-sequence (CDP under load / loopback /whoami trips infra's
# limit_req zone). Run them manually:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh          # bb-mirror forum-visibility (C2/H6)
#   bash "$(dirname "$0")/editor-rail-reachable-gate.sh"      # profile editor rail reachable @768 (CDP)

if [ "$red" -ne 0 ]; then
  echo "############ GATES RED — do not push ############"
  for g in "${RED_GATES[@]}"; do echo "   RED         $g"; done
fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES COULD NOT RUN — you are UNPROTECTED, not green ############"
  for g in "${DEAD_GATES[@]}"; do echo "   CANNOT RUN  $g"; done
  echo "   A gate that cannot run reports no failures, so silence here is not a pass."
  echo "   Usually host/token (tools/gates/gate-env.sh) or a missing fixture. Fix it and"
  echo "   run again — this blocks the push exactly like RED."
fi
if [ "$red" -ne 0 ] || [ "$dead" -ne 0 ]; then exit 1; fi
echo "############ ALL GATES GREEN ############"
