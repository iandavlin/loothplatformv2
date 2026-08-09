#!/usr/bin/env bash
# fleet-manifest — every 5 min, record which lane sessions are alive, so
# respawn-fleet.sh knows what to bring back after a reboot. Excludes sessions
# with no worktree (ad-hoc shells are not lanes). Writes atomically: a reboot
# mid-write must not leave a truncated manifest.
#
# ⚠️ AN EMPTY RESULT NEVER OVERWRITES THE MANIFEST, and the first version did.
# Measured 2026-08-09, 31 minutes after a reboot: tmux was empty (everything died
# with the box), this cron fired, wrote zero lines — and respawn-fleet then had
# nothing to restore. The record of the fleet was destroyed by the very job meant
# to preserve it, at exactly the moment it was needed. Zero sessions means the
# fleet is DOWN (reboot or teardown), which is precisely when the last known-good
# list is the whole point. So: only a non-empty reading is ever written.
#
# Consequence to know: after a deliberate teardown the manifest is stale by design.
# respawn-fleet skips already-running lanes and missing worktrees, so a stale entry
# costs a skipped line, not a wrong lane.
set -uo pipefail
OUT="$HOME/.fleet-manifest"
TMP="$OUT.tmp"
tmux list-sessions -F '#{session_name}' 2>/dev/null \
    | while IFS= read -r s; do [[ -d "$HOME/worktrees/$s" ]] && echo "$s"; done > "$TMP" || true
if [[ -s "$TMP" ]]; then
    mv -f "$TMP" "$OUT"
else
    rm -f "$TMP"
fi
