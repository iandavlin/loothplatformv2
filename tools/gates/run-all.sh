#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
#
# ============================================================================
# THREE STATES, NOT TWO. A GATE THAT CANNOT RUN IS LOUDER THAN ONE THAT FAILS.
# ============================================================================
# Until 2026-07-28 this runner had two outcomes: exit 0, or "GATES RED". A gate
# that FAILED and a gate that could not EXECUTE were indistinguishable — same
# banner, same exit code, same line in a report. That is exactly how the craft
# gate stayed dead for weeks while every lane assumed it was passing: it had been
# pointing at the retired dev.loothgroup.com and a dead dev1 test handle, and it
# printed errors and exited non-zero, which read as an ordinary RED.
#
# The asymmetry matters. A RED gate is DOING ITS JOB — it found something and it
# is telling you. A gate that cannot run is LYING BY OMISSION: it asserts nothing
# while looking like it asserted something. The second is more dangerous and must
# therefore be noisier.
#
#   exit 0  GREEN       the gate ran and found nothing
#   exit 1  RED         the gate ran and found a real violation  -> fix it
#   exit 2  CANNOT RUN  the gate could not execute at all        -> FIX THE GATE
#
# Gate authors: return 2 whenever a PREREQUISITE is missing rather than a defect
# found — no browser on :9222, no dev token, an unreadable vhost, a host that does
# not resolve. Never exit 1 for a missing prerequisite; that is the lie this
# section exists to stop.
#
# Adopted from an observation by the profile-audit lane, 2026-07-28.
# ============================================================================
set -uo pipefail

TOTAL=6
n=0
declare -a GREEN_GATES=() RED_GATES=() DEAD_GATES=()

run_gate() {          # run_gate "<label>" <command...>
    local label="$1"; shift
    n=$((n+1))
    echo "=== GATE $n/$TOTAL: $label ==="
    "$@"
    local rc=$?
    case "$rc" in
        0) GREEN_GATES+=("$label") ;;
        2) DEAD_GATES+=("$label (exit 2)") ;;
        *) RED_GATES+=("$label (exit $rc)") ;;
    esac
    echo
}

# Prefer the copy that ships in THIS tree over the absolute serve path, so the
# runner exercises the gates it was checked out with (and a lane can prove a
# harness repair without a serve flip). Falls back to /srv for an installed
# runner whose tree layout differs.
VM="$(dirname "$0")/../../profile-app/bin/visibility-matrix.php"
[ -r "$VM" ] || VM=/srv/profile-app/bin/visibility-matrix.php

run_gate "visibility matrix (the privacy model)"                        php  "$VM"
run_gate "web-craft gate (images / weight / eager scripts)"             python3 "$(dirname "$0")/craft-gate.py"
run_gate "infra-sec gate (cookie auth / source disclosure / cdp)"       bash "$(dirname "$0")/infra-sec-gate.sh"
run_gate "hub paragraph-collapse (content_html keeps its breaks)"       bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
run_gate "looth-auth-issue (non-REST mint bounce; recurs every DB reload)" bash "$(dirname "$0")/looth-auth-issue-gate.sh"
run_gate "event-date TZ (a UTC 'today' must not judge a site-local date)"  bash "$(dirname "$0")/event-date-tz-gate.sh"

# Two CDP/loopback gates are HELD OUT of the runner — they pass standalone but
# flake RED in-sequence (CDP under load / loopback /whoami trips infra's
# limit_req zone). Run them manually:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh          # bb-mirror forum-visibility (C2/H6)
#   bash "$(dirname "$0")/editor-rail-reachable-gate.sh"      # profile editor rail reachable @768 (CDP)

echo "================= SUMMARY ================="
printf '  GREEN      %d/%d\n' "${#GREEN_GATES[@]}" "$TOTAL"
printf '  RED        %d\n'    "${#RED_GATES[@]}"
printf '  CANNOT RUN %d\n'    "${#DEAD_GATES[@]}"
for g in "${RED_GATES[@]}";  do echo "    RED         $g"; done
for g in "${DEAD_GATES[@]}"; do echo "    CANNOT RUN  $g"; done
echo "==========================================="

# CANNOT RUN outranks RED deliberately. If a gate is dead you do not actually know
# whether the rest of the tree is clean, so a dead gate is reported first and sets
# the exit code even when other gates are also red.
if [ "${#DEAD_GATES[@]}" -ne 0 ]; then
    echo
    echo "###########################################################"
    echo "#  GATES COULD NOT RUN — YOU HAVE NO VERDICT, NOT A PASS  #"
    echo "#  A dead gate asserts NOTHING while looking like it did. #"
    echo "#  FIX THE HARNESS before reading anything else here.     #"
    echo "###########################################################"
    exit 2
fi
if [ "${#RED_GATES[@]}" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
echo "############ ALL GATES GREEN ############"
