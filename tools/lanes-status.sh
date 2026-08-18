#!/usr/bin/env bash
# lanes — the worktree status table (handoff-2 spec, approved by Ian 8/18).
#
# Read-only, no state, no memory: every run recomputes from git. Quiet when
# healthy — the deploy block and the push column render only when something is
# wrong, so SILENCE ONLY EVER MEANS HEALTHY. The one inversion of that rule a
# failed live read could cause is handled by printing UNKNOWN loudly instead.
#
#   lanes            table + deploy agreement check (one ssh to live, 6s cap)
#   lanes --no-live  skip the live ssh (tight loops)
#   lanes --agents   append the old tmux view (WORKING/parked per session) —
#                    on trial for the column-cull week, then keep or kill
#
# Install: sudo ln -sf /home/ubuntu/keeper-repo/tools/lanes-status.sh /usr/local/bin/lanes
set -euo pipefail

REPO="/home/ubuntu/keeper-repo"
SERVE="/home/ubuntu/loothplatformv2-clean"
NO_LIVE=0; AGENTS=0
for a in "$@"; do case "$a" in --no-live) NO_LIVE=1;; --agents) AGENTS=1;; esac; done

# ── deploy line: main / dev2 serve / live. Invisible when all agree. ─────────
MAIN=$(git -C "$REPO" rev-parse main)
DEV2=$(git -C "$SERVE" rev-parse HEAD 2>/dev/null || echo MISSING)
LIVE="(skipped)"
if [[ $NO_LIVE -eq 0 ]]; then
    # Fix #1 (handoff-2 reply) wants rev-parse so packed refs can't cause a
    # false UNKNOWN. Measured 8/18: rev-parse fails for the read-only live user
    # (git "dubious ownership" — the repo belongs to ubuntu). So: rev-parse
    # first (works the day live adds safe.directory for looth-ro), then the
    # loose ref, then packed-refs — loose before packed, matching git's own
    # precedence. UNKNOWN only when every read genuinely fails.
    LIVE=$(timeout 6 ssh live-ro '
        git -C /home/ubuntu/loothplatformv2-clean rev-parse main 2>/dev/null \
        || cat /home/ubuntu/loothplatformv2-clean/.git/refs/heads/main 2>/dev/null \
        || sed -n "s|^\([0-9a-f]\{40\}\) refs/heads/main$|\1|p" /home/ubuntu/loothplatformv2-clean/.git/packed-refs 2>/dev/null | head -1
    ') || true
    [[ -z "$LIVE" ]] && LIVE="UNKNOWN"
fi
GAP=0
[[ "$DEV2" != "$MAIN" ]] && GAP=1
[[ $NO_LIVE -eq 0 && "$LIVE" != "$MAIN" ]] && GAP=1
if [[ $GAP -eq 1 ]]; then
    echo "DEPLOY GAP:"
    echo "  main  ${MAIN:0:7}"
    if [[ "$DEV2" == "MISSING" ]]; then echo "  dev2  MISSING (serving checkout unreadable)"; else
        echo "  dev2  ${DEV2:0:7}$([[ "$DEV2" != "$MAIN" ]] && echo '   <- differs from main')"; fi
    if [[ $NO_LIVE -eq 0 ]]; then
        if [[ "$LIVE" == "UNKNOWN" ]]; then echo "  live  UNKNOWN — read failed; NOT proof of health"; else
            echo "  live  ${LIVE:0:7}$([[ "$LIVE" != "$MAIN" ]] && echo '   <- differs from main')"; fi
    fi
    echo
fi

# ── rows: one per worktree, straight from git ────────────────────────────────
ROWS=""          # sortkey|folder|branch|behind|unique|push|status
SHOW_PUSH=0      # push column materializes only when a lane has unpushed work
folder=""
while IFS= read -r line; do
    case "$line" in
        "worktree "*) folder="${line#worktree }" ;;
        "detached")
            ROWS+="999999|${folder#/home/ubuntu/}|(detached)|-|-|-|detached — investigate"$'\n' ;;
        "branch refs/heads/"*)
            branch="${line#branch refs/heads/}"
            if [[ "$branch" == "main" ]]; then
                # the parent checkout is not a seat; the deploy line covers it
                ROWS+="-1|${folder#/home/ubuntu/}|main|0|0|0|— (parent checkout)"$'\n'
                continue
            fi
            lr=$(git -C "$REPO" rev-list --left-right --count "origin/main...$branch" 2>/dev/null | tr '\t' ' ' || true)
            [[ -z "$lr" ]] && lr="? ?"
            behind="${lr%% *}"; unique="${lr##* }"
            if git -C "$REPO" rev-parse --verify -q "refs/remotes/origin/$branch" >/dev/null; then
                push=$(git -C "$REPO" rev-list --count "origin/$branch..$branch")
            else
                push="NO REMOTE"
            fi
            subj=$(git -C "$REPO" log -1 --format=%s "$branch" 2>/dev/null || echo "")
            # Status: spec's five rules. Precedence note: AT RISK outranks
            # re-cut — the loud flag must never be masked by a big behind count.
            if   [[ "$unique" == "0" ]]; then status="done — seat freeable"
            elif [[ "$subj" == "STOOD DOWN: "* ]]; then status="stood down"
            elif [[ "$push" == "NO REMOTE" ]]; then status="AT RISK — work on one disk only"
            elif [[ "$behind" =~ ^[0-9]+$ && "$behind" -gt 300 ]]; then status="re-cut, don't rebase"
            else status="live lane"
            fi
            # push cell: loud only when work is actually unpushed. A merged
            # branch with no remote has nothing at risk — stays quiet.
            cell="$push"
            if [[ "$push" == "NO REMOTE" ]]; then
                if [[ "$unique" == "0" ]]; then cell="-"; else SHOW_PUSH=1; fi
            elif [[ "$push" != "0" ]]; then SHOW_PUSH=1; fi
            ROWS+="$behind|${folder#/home/ubuntu/}|$branch|$behind|$unique|$cell|$status"$'\n' ;;
    esac
done < <(git -C "$REPO" worktree list --porcelain)

# ── print, sorted by behind ascending (freshest first) ───────────────────────
if [[ $SHOW_PUSH -eq 1 ]]; then
    printf "%-34s %-24s %7s %7s %10s  %s\n" "FOLDER" "BRANCH" "BEHIND" "UNIQUE" "UNPUSHED" "STATUS"
else
    printf "%-34s %-24s %7s %7s  %s\n" "FOLDER" "BRANCH" "BEHIND" "UNIQUE" "STATUS"
fi
printf '%s' "$ROWS" | sort -t'|' -k1,1n | while IFS='|' read -r _ f b behind unique cell status; do
    [[ -z "$f" ]] && continue
    if [[ $SHOW_PUSH -eq 1 ]]; then
        printf "%-34s %-24s %7s %7s %10s  %s\n" "$f" "$b" "$behind" "$unique" "$cell" "$status"
    else
        printf "%-34s %-24s %7s %7s  %s\n" "$f" "$b" "$behind" "$unique" "$status"
    fi
done

# ── --agents: the old tmux view, unchanged (trial column) ────────────────────
if [[ $AGENTS -eq 1 ]]; then
    echo
    echo "── agents (tmux):"
    found=0
    for s in $(tmux list-sessions -F "#{session_name}" 2>/dev/null); do
        found=1
        if tmux capture-pane -t "$s" -p 2>/dev/null | grep -q "esc to interrupt"; then st="WORKING"; else st="parked "; fi
        last=$(tmux capture-pane -t "$s" -p -S -30 2>/dev/null \
              | grep -vE "^\s*$|^─|^❯|bypass permissions|tmux |Auto-update|/clear to save|control this session" \
              | tail -1 | sed "s/^[ ●✻⎿·]*//" | cut -c1-90)
        printf "%-20s %s  %s\n" "$s" "$st" "$last"
    done
    [[ $found -eq 0 ]] && echo "(no agent sessions)"
fi
