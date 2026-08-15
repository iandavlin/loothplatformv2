#!/usr/bin/env bash
# lane-sentinel — the ALWAYS-ON deputy (cron, every 5 min). Built 2026-08-14
# after two stall episodes the single-shot watchdog missed while it was down
# (keeper forgot the relight both times — Ian: "Can you figure out how to
# prevent this type of stalling again?").
#
# The sentinel does NOT wake keeper (cron cannot). It does three things:
#   1. LOGS every finding to ~/keeper-alerts.log (keeper-pulse reads this at
#      the top of every keeper sweep).
#   2. AUTO-NUDGES a stalled lane ONCE per park episode via lane-say: parked
#      >10 min, or parked with unsent composer text. The nudge is generic and
#      safe: continue-or-state-your-blocker. Episode marker clears when the
#      lane works again.
#   3. NEVER answers confirm dialogs (judgment stays with keeper) and NEVER
#      touches a lane Ian is driving (no marker for that today — keep nudges
#      generic and rare so they are harmless if one lands there).
# Also logs when the tracked wake-watchdog is not running, so keeper-pulse
# surfaces "your watchdog is down" the moment keeper next looks at anything.
set -u
LOG="$HOME/keeper-alerts.log"
STATE="$HOME/.lane-sentinel"
MANIFEST="$HOME/.fleet-manifest"
mkdir -p "$STATE"
now() { date '+%Y-%m-%d %H:%M:%S'; }
note() { echo "[$(now)] $*" >> "$LOG"; }

# Wake-watchdog liveness (the tracked one keeper must keep lit)
pgrep -f "tools/lanes/stall-watchdog.sh" >/dev/null 2>&1 || note "WATCHDOG-DOWN — keeper's wake-watchdog is not running (relight on next keeper turn)"

[ -s "$MANIFEST" ] || { note "MANIFEST-EMPTY"; exit 0; }

while read -r L; do
  [ -n "$L" ] || continue
  if ! tmux has-session -t "$L" 2>/dev/null; then
    note "DEAD-SESSION $L"; continue
  fi
  PANE=$(tmux capture-pane -t "$L" -p 2>/dev/null || true)
  if printf '%s' "$PANE" | grep -qE "Do you want to proceed\?"; then
    note "CONFIRM-PROMPT $L — needs keeper judgment, not nudging"; continue
  fi
  if lanes 2>/dev/null | grep -E "^$L " | grep -q parked; then
    STUCK=0
    printf '%s' "$PANE" | grep -qE "^❯ .*[[:alnum:]]" && STUCK=1
    NOWS=$(date +%s)
    FIRST_F="$STATE/$L.first"; NUDGE_F="$STATE/$L.nudged"
    [ -f "$FIRST_F" ] || echo "$NOWS" > "$FIRST_F"
    FIRST=$(cat "$FIRST_F")
    AGE=$((NOWS - FIRST))
    if [ ! -f "$NUDGE_F" ] && { [ "$STUCK" = "1" ] || [ "$AGE" -ge 600 ]; }; then
      bash "$HOME/keeper-repo/tools/lanes/lane-say.sh" "$L" \
        "keeper-sentinel auto-nudge: you are parked with charter work remaining (${AGE}s, stuck-text=$STUCK). If your last plan still stands, continue it now. If you are blocked, say on WHAT via: msg send ubuntu \"$L -> keeper: blocked on ...\" and park. This is an automated nudge; keeper reviews the log." >/dev/null 2>&1 \
        && { touch "$NUDGE_F"; note "NUDGED $L (age=${AGE}s stuck=$STUCK)"; } \
        || note "NUDGE-FAILED $L"
    elif [ -f "$NUDGE_F" ] && [ "$AGE" -ge 1800 ]; then
      note "STILL-PARKED $L after nudge (${AGE}s) — keeper attention needed"
    fi
  else
    rm -f "$STATE/$L.first" "$STATE/$L.nudged" 2>/dev/null
  fi
done < "$MANIFEST"

# Status stamp for Ian's VS Code status line (8/15: "build a time into the
# vs code that I can see that is the cron for the lane checking"). One line,
# overwritten every patrol; keeper-statusline.sh renders it.
W=$(lanes 2>/dev/null | grep -c WORKING || echo "?")
T=$(lanes 2>/dev/null | wc -l || echo "?")
echo "$(date +%s) $(date +%H:%M:%S) working=$W total=$T" > "$HOME/.sentinel-status"
exit 0
