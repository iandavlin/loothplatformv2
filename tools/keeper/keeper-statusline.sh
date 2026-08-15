#!/usr/bin/env bash
# keeper-statusline.sh — one-line fleet status for the Claude Code status line
# (Ian 8/15: a visible time for the lane-checking cron). Reads the stamp the
# sentinel writes every 5-minute patrol; screams if patrols stop.
# LANE SESSIONS RENDER NOTHING (8/15, an hour after shipping): the shared
# user settings put this line into every LANE terminal too, displacing the
# activity line the `lanes` monitor parses — six working teams read as
# "parked, working=0" and the sentinel stamped garbage. The clock is for
# Ian and keeper; a lane wearing it blinds the shepherd.
[ "${LG_LANE:-0}" = "1" ] && exit 0
S="$HOME/.sentinel-status"
if [ ! -f "$S" ]; then echo "🐕 deputy: no patrol recorded yet"; exit 0; fi
read -r EPOCH HMS REST < "$S"
NOW=$(date +%s)
AGE=$(( NOW - EPOCH ))
NEXT=$(( ((EPOCH / 300) + 1) * 300 ))
NEXT_HM=$(date -d "@$NEXT" +%H:%M)
if [ "$AGE" -gt 660 ]; then
    echo "🚨 deputy SILENT ${AGE}s (last $HMS) — cron may be down"
else
    echo "🐕 deputy ✓ $HMS · next ~$NEXT_HM · $REST"
fi
