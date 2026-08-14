#!/usr/bin/env bash
# keeper-pulse — ONE command keeper runs at the top of EVERY sweep.
# Surfaces in five lines: is the wake-watchdog alive, what has the sentinel
# logged since last pulse, and what are the lanes doing. A dead watchdog can
# no longer hide behind a quiet board (the 8/14 lesson, twice).
set -u
LOG="$HOME/keeper-alerts.log"
MARK="$HOME/.keeper-pulse-mark"
echo "── keeper-pulse $(date '+%H:%M:%S') ──"
if pgrep -f "tools/lanes/stall-watchdog.sh" >/dev/null 2>&1; then
  echo "watchdog: ALIVE"
else
  echo "watchdog: *** DOWN — RELIGHT NOW (background: bash ~/keeper-repo/tools/lanes/stall-watchdog.sh) ***"
fi
if [ -f "$LOG" ]; then
  NEW=$(comm -13 <(sort "$MARK" 2>/dev/null) <(sort "$LOG") 2>/dev/null | tail -12)
  if [ -n "$NEW" ]; then echo "sentinel alerts since last pulse:"; echo "$NEW" | sed 's/^/  /'; else echo "sentinel: quiet"; fi
  cp "$LOG" "$MARK" 2>/dev/null
else
  echo "sentinel: no log yet"
fi
lanes 2>/dev/null || echo "(no lanes)"
