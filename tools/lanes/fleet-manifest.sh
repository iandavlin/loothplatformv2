#!/usr/bin/env bash
# fleet-manifest — every 5 min, record which lane sessions are alive, so
# respawn-fleet.sh knows what to bring back after a reboot. Excludes sessions
# with no worktree (ad-hoc shells are not lanes). Writes atomically: a reboot
# mid-write must not leave a truncated manifest.
set -uo pipefail
OUT="$HOME/.fleet-manifest"
TMP="$OUT.tmp"
tmux list-sessions -F '#{session_name}' 2>/dev/null \
    | while IFS= read -r s; do [[ -d "$HOME/worktrees/$s" ]] && echo "$s"; done > "$TMP" || true
mv -f "$TMP" "$OUT"
