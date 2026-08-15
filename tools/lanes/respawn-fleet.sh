#!/usr/bin/env bash
# respawn-fleet — bring the lane fleet back after a reboot, WITH their memories.
#
# Ian, 2026-08-08: "is there any way to make it so we can survive a reboot. I
# really don't want to leave the server running non stop."
#
# HOW THIS WORKS, and why it can: a lane's conversation transcript is written to
# disk (~/.claude/projects/<worktree-key>/) as it runs, so the process dying in a
# reboot loses nothing that matters — files are on disk, commits are pushed, and
# `claude --continue` in the lane's worktree resumes the SAME conversation. This
# script reads the manifest of lanes that were alive (written every 5 min by the
# fleet-manifest cron) and resumes each one in a fresh tmux session.
#
# DELIBERATELY MANUAL. Ian reboots the box precisely when he wants it quiet, and
# an auto-respawn on boot would silently relight N paid sessions with nobody
# watching. So: reboot freely; when you want the fleet back, run
#
#     bash ~/keeper-repo/tools/lanes/respawn-fleet.sh
#
# and every lane that was working before the reboot comes back mid-thought.
set -uo pipefail

MANIFEST="$HOME/.fleet-manifest"
CLAUDE="${CLAUDE_BIN:-$HOME/.local/bin/claude}"
MODEL="${LANE_MODEL:-opus[1m]}"

[[ -f "$MANIFEST" ]] || { echo "respawn-fleet: no manifest at $MANIFEST — nothing recorded as running"; exit 0; }
[[ -x "$CLAUDE"   ]] || { echo "respawn-fleet: claude not executable at $CLAUDE" >&2; exit 1; }

# Memory guard: each lane costs ~500MB. Refuse to relight a fleet the box can't hold.
FREE_MB=$(free -m | awk '/^Mem:/{print $7}')
WANT=$(wc -l < "$MANIFEST")
NEED=$(( WANT * 600 ))
if (( FREE_MB < NEED )); then
    echo "respawn-fleet: $WANT lane(s) want ~${NEED}MB, only ${FREE_MB}MB available — respawn fewer by editing $MANIFEST" >&2
    exit 1
fi

while IFS= read -r LANE; do
    [[ -z "$LANE" ]] && continue
    WT="$HOME/worktrees/$LANE"
    if tmux has-session -t "$LANE" 2>/dev/null; then
        echo "respawn-fleet: $LANE already running — skipped"
        continue
    fi
    if [[ ! -d "$WT" ]]; then
        echo "respawn-fleet: $LANE has no worktree at $WT — skipped (retired?)"
        continue
    fi
    # --continue resumes the worktree's most recent conversation. The seed message
    # makes the resumed lane re-verify the world instead of trusting pre-reboot
    # state — running processes, seats, and serving checkouts all changed.
    tmux new-session -d -s "$LANE" \
        "cd '$WT' && export LG_LANE=1 && exec '$CLAUDE' --continue --dangerously-skip-permissions --model '$MODEL' \
         'keeper: the box rebooted and you have been resumed with your prior context. Files and pushes survived; RUNNING STATE did not — re-verify anything you believed about live processes (gates mid-run, browsers, cron state) before continuing your charter work from where you left off.'"
    echo "respawn-fleet: $LANE resumed"
done < "$MANIFEST"

echo "respawn-fleet: done — check with 'lanes' in ~30s"
