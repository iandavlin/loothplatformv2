#!/usr/bin/env bash
# stall-watchdog — wakes keeper when a lane needs attention (Ian, 2026-08-11:
# "I have to ask you every time to check on them"). Runs as a keeper background
# task; EXITING is the alert — the harness re-invokes keeper with the last
# output line, keeper handles it, then relaunches this script.
#
# Triggers (any one exits with an ALERT line):
#   dead-session   — a manifest lane has no tmux session (reboot/blip kill)
#   confirm-prompt — a lane is frozen on a yes/no confirmation dialog
#   parked-long    — a lane sat parked >10 min and is not on the expected-park
#                    list (~/.lane-park-ok, one lane name per line)
set -u
# KEEPER RULE (after the 8/12 gap): reading an ALERT and relaunching this script
# happen in the SAME tool call, never separately. Every alert below prints the
# relaunch order as its last line so the wake-up itself carries the instruction.
trap 'echo "==> RELAUNCH NOW (same tool call): bash ~/keeper-repo/tools/lanes/stall-watchdog.sh in background"' EXIT
MANIFEST="$HOME/.fleet-manifest"
OKFILE="$HOME/.lane-park-ok"
STATED="$HOME/.lane-park-state"
mkdir -p "$STATED"
PARK_LIMIT=600
while true; do
  [ -s "$MANIFEST" ] || { echo "ALERT empty-manifest"; exit 0; }
  while read -r L; do
    [ -n "$L" ] || continue
    if ! tmux has-session -t "$L" 2>/dev/null; then
      echo "ALERT dead-session $L — respawn per fleet rule"; exit 0
    fi
    PANE=$(tmux capture-pane -t "$L" -p 2>/dev/null || true)
    if printf '%s' "$PANE" | grep -qE "Do you want to proceed\?"; then
      echo "ALERT confirm-prompt $L — a lane is frozen on a yes/no dialog"; exit 0
    fi
    if lanes 2>/dev/null | grep -E "^$L " | grep -q parked; then
      grep -qxF "$L" "$OKFILE" 2>/dev/null && { rm -f "$STATED/$L"; continue; }
      NOW=$(date +%s)
      if [ -f "$STATED/$L" ]; then
        FIRST=$(cat "$STATED/$L")
        if [ $((NOW - FIRST)) -ge $PARK_LIMIT ]; then
          rm -f "$STATED/$L"
          echo "ALERT parked-long $L — parked over $((PARK_LIMIT/60)) min, sweep it"; exit 0
        fi
      else
        echo "$NOW" > "$STATED/$L"
      fi
    else
      rm -f "$STATED/$L" 2>/dev/null
    fi
  done < "$MANIFEST"
  sleep 90
done
