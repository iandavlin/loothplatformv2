#!/usr/bin/env bash
# spin-lane.sh — start a lane with a SIGNED git identity and its charter.
#
# Ian, 2026-07-30, after an audit of the 168 commits between live and main found
# 100 of them authored by the generic box user `Ubuntu <ubuntu@ip-172-31-78-94>`:
# lane work must be attributable. Only weekly-digest-recap was signing. Without a
# per-lane identity you cannot separate one lane's work from another's, and the
# only usable separation handle left is the merge commit.
#
#   bash tools/lanes/spin-lane.sh <lane-name> [charter-path]
#
# Charter defaults to ~/lane-prompts/<lane>.md. Worktree must already exist at
# ~/worktrees/<lane> (cut it with `git worktree add` off origin/main first).
#
# The identity is set with `git config --local`, so it lives in the WORKTREE and
# cannot leak into keeper-repo or the serving checkout.
set -euo pipefail

LANE="${1:?usage: spin-lane.sh <lane-name> [charter-path]}"
CHARTER="${2:-$HOME/lane-prompts/${LANE}.md}"
WT="$HOME/worktrees/${LANE}"
CLAUDE="${CLAUDE_BIN:-$HOME/.local/bin/claude}"
MODEL="${LANE_MODEL:-opus[1m]}"

[[ -d "$WT"        ]] || { echo "spin-lane: no worktree at $WT — cut it first" >&2; exit 1; }
[[ -f "$CHARTER"   ]] || { echo "spin-lane: no charter at $CHARTER" >&2; exit 1; }
[[ -x "$CLAUDE"    ]] || { echo "spin-lane: claude not executable at $CLAUDE" >&2; exit 1; }

# ── the point of this script ───────────────────────────────────────────────────
# `--local` is WRONG here and silently breaks signing: git worktrees SHARE one
# .git/config, so every lane's --local write lands in the same file and the LAST
# one wins. Measured 2026-07-30: all seven worktrees reported "commit-provenance
# lane". `--worktree` writes per-worktree, and needs extensions.worktreeConfig on
# the parent repo, so set that first (idempotent).
git -C "$WT" config extensions.worktreeConfig true
git -C "$WT" config --worktree user.name  "${LANE} lane"
git -C "$WT" config --worktree user.email "claude@loothgroup.com"

echo "spin-lane: $LANE signs as '$(git -C "$WT" config --local user.name) <$(git -C "$WT" config --local user.email)>'"

cd "$WT"
exec "$CLAUDE" --dangerously-skip-permissions --model "$MODEL" "$(cat "$CHARTER")"
