#!/usr/bin/env bash
# GATE — FPM ERROR LOG. "Someone should check the logs" becomes unskippable.
#
#   bash tools/gates/fpm-error-log-gate.sh                 # dev2 + live, last 60 min
#   bash tools/gates/fpm-error-log-gate.sh --minutes 15    # tighter window (post-deploy)
#   bash tools/gates/fpm-error-log-gate.sh --box dev2      # one box only
#
# exit 0 = green   exit 1 = RED (real findings)   exit 2 = CANNOT RUN (no verdict)
#
# ─── WHY THIS EXISTS — it cost the most time of anything on 2026-07-31 ───────────
# `FATAL: role "membership" does not exist` printed on EVERY load of the Manage
# Account page on live, for the whole outage, while two people reasoned about
# bb_mirror_db() and talked each other OUT of the correct fix. The answer was in
# /var/log/php8.3-fpm.log the entire time. Nobody read it.
#
# ─── THE TWO TRAPS THIS GATE IS BUILT AROUND ────────────────────────────────────
#
# 1. HOW YOU READ THE LOG DIFFERS PER BOX, AND GETTING IT WRONG FAILS SILENTLY.
#      live:  /var/log/php8.3-fpm.log carries an ACL that lets `looth-ro` read it
#             DIRECTLY. `sudo` on live needs a password, so `sudo -n grep …`
#             returns nothing, exit 1, and reads exactly like "no errors".
#             That is what was tried on the night, and it is why nothing was found.
#      dev2:  the same file is NOT readable by `ubuntu` (Permission denied) and
#             DOES need sudo.
#    Backwards on each box. So this gate proves it can READ the log before it
#    claims anything about the contents.
#
# 2. ABSENCE NEEDS LIVENESS (docs/CRAFT-STANDARD.md). "No FATALs" is trivially
#    true against an unreadable file, an empty file, or a box with no PHP at all.
#    Every absence assertion here is paired with proof the log is live: readable,
#    non-empty, and carrying at least one line inside the window. Fail any of those
#    and the gate reports CANNOT RUN (exit 2), never green.
#
# ─── WHY IT QUOTES THE LINE ─────────────────────────────────────────────────────
# A gate that says "3 errors" makes someone go and look. A gate that prints
# `FATAL: role "membership" does not exist` fixes it. Findings are deduplicated
# and counted, but the text is always shown.
set -uo pipefail

MINUTES=60
BOXES="dev2 live"
LOG=/var/log/php8.3-fpm.log

while [ $# -gt 0 ]; do
  case "$1" in
    --minutes) MINUTES="$2"; shift 2 ;;
    --box)     BOXES="$2";   shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

red=0; dead=0

# Read the log for a box, on stdout. The per-box difference is confined to here.
#   dev2 → local, sudo REQUIRED
#   live → ssh live-ro, sudo MUST NOT be used (it prompts, fails, and reads as clean)
read_log() {
  case "$1" in
    dev2) sudo cat "$LOG" 2>/dev/null ;;
    live) timeout 90 ssh live-ro "cat $LOG" 2>/dev/null ;;
  esac
}

# FPM stamps lines `[31-Jul-2026 04:02:26]`. Convert to epoch so the window is real
# time, not a line count — a quiet box and a busy box need the same window.
awk_window='
  function mon(m) {
    return (index("JanFebMarAprMayJunJulAugSepOctNovDec", m) + 2) / 3
  }
  match($0, /^\[([0-9]{2})-([A-Za-z]{3})-([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\]/, t) {
    ts = mktime(t[3] " " mon(t[2]) " " t[1] " " t[4] " " t[5] " " t[6])
    keep = (ts >= CUTOFF)
  }
  keep { print }
'

for box in $BOXES; do
  echo "── $box ──────────────────────────────────────────────────────────────"

  raw="$(read_log "$box")"
  rc=$?

  # ── LIVENESS, before any claim of absence ──────────────────────────────────
  if [ $rc -ne 0 ] || [ -z "$raw" ]; then
    echo "  CANNOT RUN — could not read $LOG on $box (empty or permission denied)."
    echo "               'no errors' from an unreadable log is a vacuous pass."
    echo "               dev2 needs sudo; live must NOT use sudo (it prompts)."
    dead=$((dead+1)); echo; continue
  fi
  total=$(printf '%s\n' "$raw" | wc -l)
  echo "  liveness: log readable, $total lines"

  cutoff=$(( $(date +%s) - MINUTES * 60 ))
  windowed="$(printf '%s\n' "$raw" | gawk -v CUTOFF="$cutoff" "$awk_window" 2>/dev/null)"
  if [ -z "$windowed" ]; then
    echo "  CANNOT RUN — log is readable but has NO lines in the last ${MINUTES}m."
    echo "               Nothing to assert absence against. Widen --minutes, or find"
    echo "               out why a serving box is writing no FPM lines at all."
    dead=$((dead+1)); echo; continue
  fi
  wlines=$(printf '%s\n' "$windowed" | wc -l)
  echo "  liveness: $wlines line(s) inside the ${MINUTES}m window — absence is meaningful"

  # ── THE ASSERTION ──────────────────────────────────────────────────────────
  # FATAL and Uncaught are unconditional findings. A single one is a real defect;
  # `role "membership" does not exist` appeared six times and was worth stopping for.
  findings="$(printf '%s\n' "$windowed" \
    | grep -aiE 'FATAL|PHP Fatal error|Uncaught|does not exist|Allowed memory size' \
    || true)"

  if [ -n "$findings" ]; then
    n=$(printf '%s\n' "$findings" | wc -l)
    echo "  ❌ RED — $n line(s) matching FATAL/Uncaught in the last ${MINUTES}m:"
    echo
    # Dedup on the message body (strip timestamp + pid) so one recurring fault
    # reports once with a count, rather than burying the pane.
    printf '%s\n' "$findings" \
      | sed -E 's/^\[[^]]*\] *//; s/child [0-9]+/child <pid>/' \
      | sort | uniq -c | sort -rn \
      | while read -r count line; do
          printf '     %sx  %s\n' "$count" "$line"
        done
    echo
    red=1
  else
    echo "  ✔ no FATAL/Uncaught in the last ${MINUTES}m"
  fi

  # Repeated warnings/notices: not fatal, but a pool emitting the same warning
  # hundreds of times is a defect that has simply not fallen over yet.
  noisy="$(printf '%s\n' "$windowed" \
    | grep -aiE 'PHP Warning|PHP Notice|WARNING: \[pool' \
    | sed -E 's/^\[[^]]*\] *//; s/child [0-9]+/child <pid>/' \
    | sort | uniq -c | sort -rn | awk '$1 >= 20' || true)"
  if [ -n "$noisy" ]; then
    echo "  ❌ RED — repeated warning(s) (≥20 in ${MINUTES}m):"
    printf '%s\n' "$noisy" | while read -r count line; do
      printf '     %sx  %s\n' "$count" "$line"
    done
    red=1
  fi
  echo
done

echo "──────────────────────────────────────────────────────────────────────"
if [ "$red" -ne 0 ]; then
  echo "############ FPM ERROR LOG GATE: RED ############"
  echo "Read the quoted line before theorising about code. On 2026-07-31 the"
  echo "quoted line WAS the answer, for the whole outage."
  exit 1
fi
if [ "$dead" -ne 0 ]; then
  echo "############ FPM ERROR LOG GATE: CANNOT RUN ($dead box) ############"
  exit 2
fi
echo "############ FPM ERROR LOG GATE: GREEN ############"
