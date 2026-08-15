#!/usr/bin/env bash
# keeper-statusline.sh — one-line fleet status for the Claude Code status line
# (Ian 8/15: a visible time for the lane-checking cron). Reads the stamp the
# sentinel writes every 5-minute patrol; screams if patrols stop.
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
