#!/usr/bin/env bash
# sweep-merged.sh — delete branches fully merged into main, under the STANDING
# GRANT (Ian, handoff-2, 8/18): the --merged class ONLY. Every commit on these
# branches is reachable from main; deletion loses nothing. Unmerged branches
# always need Ian's explicit word — this script cannot touch them: the list is
# built from --merged, and local deletion uses -d, which refuses non-merged.
#
# Paper trail: the full deleted list (name, sha, subject) is committed and
# pushed BEFORE anything is deleted.
set -euo pipefail
cd /home/ubuntu/keeper-repo

# Fix #2 (handoff-2 reply): --merged tests against LOCAL refs, and the standing
# grant means nobody re-checks the result — so current truth is fetched first,
# never assumed.
git fetch origin --prune

[[ "$(git rev-parse --abbrev-ref HEAD)" == "main" ]] || { echo "sweep: keeper-repo not on main — fix that first" >&2; exit 1; }
[[ -z "$(git status --porcelain)" ]] || { echo "sweep: keeper-repo dirty — commit or stash first" >&2; exit 1; }
git pull --ff-only origin main

mapfile -t DEAD < <(git branch --merged main | sed 's/^[+* ] *//' | grep -vx main)
if [[ ${#DEAD[@]} -eq 0 ]]; then echo "sweep: nothing merged to sweep"; exit 0; fi

STAMP=$(date +%Y%m%d-%H%M)
OUT="docs/branch-sweeps/sweep-$STAMP.txt"
mkdir -p docs/branch-sweeps
{
    echo "# merged-branch sweep $STAMP — every commit below is reachable from main"
    echo "# standing grant: Ian, handoff-2, 8/18 (--merged class only)"
    for b in "${DEAD[@]}"; do
        echo "$b $(git rev-parse "$b") :: $(git log -1 --format=%s "$b")"
    done
} > "$OUT"
git add "$OUT"
git commit -m "branch sweep $STAMP: record ${#DEAD[@]} merged branches before deleting them (standing grant 8/18)"
git push origin main

for b in "${DEAD[@]}"; do
    # -d, never -D: git itself re-verifies merged-ness; branches checked out in
    # a worktree are refused and kept, loudly.
    git branch -d "$b" 2>/dev/null && echo "deleted local  $b" \
        || echo "KEPT $b (checked out in a worktree, or no longer strictly merged)"
    if git rev-parse --verify -q "refs/remotes/origin/$b" >/dev/null; then
        # same mechanical test on the remote ref before deleting it upstream
        if git merge-base --is-ancestor "origin/$b" main; then
            git push origin --delete "$b" && echo "deleted origin $b"
        else
            echo "KEPT origin/$b (remote ref not an ancestor of main)"
        fi
    fi
done
echo "sweep: done — record at $OUT"
