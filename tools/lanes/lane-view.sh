#!/usr/bin/env bash
# lane-view — WATCH-ONLY terminal for one lane, served by ttyd behind the dev
# gate (Ian ruled watch-only, 8/15 night: the browser view must never carry a
# keystroke back into a session running with full permissions).
#
# Read-only is enforced TWICE, independently:
#   1. ttyd runs WITHOUT -W, so the browser's input never reaches this process.
#   2. tmux attaches with -r, so even if input arrived, the client is read-only.
#
# The lane name arrives as $1 from ttyd's URL arg (?arg=<lane>). Only names in
# the fleet manifest are attachable — this script is the allowlist, so a crafted
# ?arg= can never watch (or name) anything that is not a declared lane session.

set -u

LANE="${1:-}"
MANIFEST="$HOME/.fleet-manifest"

if [[ -z "$LANE" ]]; then
    echo "lane-view: no lane named. Use the board's watch links."
    exit 1
fi

if ! grep -qxF "$LANE" "$MANIFEST" 2>/dev/null; then
    echo "lane-view: '$LANE' is not a lane in the fleet manifest."
    exit 1
fi

if ! tmux has-session -t "$LANE" 2>/dev/null; then
    echo "lane-view: lane '$LANE' has no running session right now (parked seats still show; a missing session means it is being recycled — try again in a minute)."
    exit 1
fi

exec tmux attach -rt "$LANE"
