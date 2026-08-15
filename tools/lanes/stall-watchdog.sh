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
    # Lost instruction: parked with NON-EMPTY composer text ("❯ something").
    # No 10-minute grace — a sticky note nobody pressed Enter on is a stall
    # the moment it exists (the 8/14 class: 'draw the door pictures' sat idle).
    if lanes 2>/dev/null | grep -E "^$L " | grep -q parked; then
      if printf '%s' "$PANE" | grep -qE "^❯ .*[[:alnum:]]"; then
        echo "ALERT lost-instruction $L — parked with unsent composer text"; exit 0
      fi
    fi
    if lanes 2>/dev/null | grep -E "^$L " | grep -q parked; then
      grep -qxF "$L" "$OKFILE" 2>/dev/null && { rm -f "$STATED/$L"; continue; }
      NOW=$(date +%s)
      if [ -f "$STATED/$L" ]; then
        FIRST=$(cat "$STATED/$L")
        if [ $((NOW - FIRST)) -ge $PARK_LIMIT ]; then
          rm -f "$STATED/$L"
          # Unacked-instruction check (2026-08-15): if keeper lane-said this
          # lane AFTER its last board post, it parked ON an instruction rather
          # than after answering one — say so, it changes keeper's response
          # from "read their report" to "the message never got absorbed".
          SENT="$HOME/.lane-say/sent-$L.ts"
          if [ -f "$SENT" ]; then
            LASTPOST=$(msg inbox 2>/dev/null | grep -F "$L -> keeper" | tail -1 | grep -oE '2026-[0-9-]+ [0-9:]+' | tail -1)
            LASTPOST_TS=$(date -d "$LASTPOST" +%s 2>/dev/null || echo 0)
            if [ "$(cat "$SENT")" -gt "${LASTPOST_TS:-0}" ] 2>/dev/null; then
              echo "ALERT parked-long-UNACKED $L — parked over $((PARK_LIMIT/60)) min AND keeper's last instruction has no board answer; the message may never have been absorbed. Re-deliver, do not just read the pane."; exit 0
            fi
          fi
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
