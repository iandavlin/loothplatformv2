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

# ── park subcommand (Ian's ruling 8/18: lifecycle lives here, spin + park) ────
#   spin-lane.sh park <lane> "<reason>"
# Parking = keep the branch, free the seat. The dangerous step is removing the
# worktree BEFORE pushing — this script makes that order impossible. Marks the
# branch with the exact LANE-RULES prefix "PARKED: <reason>" (an empty commit),
# pushes, then removes the worktree. Keeper/Ian action — lanes never park
# themselves (LANE-RULES: lanes don't remove worktrees).
if [[ "${1:-}" == "park" ]]; then
    LANE="${2:?usage: spin-lane.sh park <lane> \"reason\"}"
    REASON="${3:?park needs a reason — it becomes the PARKED: commit}"
    WT="$HOME/worktrees/${LANE}"
    [[ -d "$WT" ]] || { echo "park: no worktree at $WT" >&2; exit 1; }
    BR="$(git -C "$WT" rev-parse --abbrev-ref HEAD)"
    [[ -z "$(git -C "$WT" status --porcelain)" ]] || { echo "park: $LANE has uncommitted changes — commit them first (nothing may be lost by parking)" >&2; exit 1; }
    git -C "$WT" commit --allow-empty -m "PARKED: $REASON"
    git -C "$WT" push -u origin "$BR"
    git -C "$HOME/keeper-repo" worktree remove "$WT"
    echo "park: $BR parked (\"$REASON\") — seat '$LANE' freed, branch safe on origin"
    exit 0
fi

LANE="${1:?usage: spin-lane.sh <issue>-<slug> [charter-path]  |  spin-lane.sh park <lane> \"reason\"}"
CHARTER="${2:-$HOME/lane-prompts/${LANE}.md}"

# ── the plan-mode wall (Ian's ruling 8/19: "There is exactly one door") ───────
# A lane opens ONLY from an OPEN issue carrying the 'approved' label. New lanes
# are named <issue>-<slug>; the leading number IS the issue. Ian's literal
# "SPIN <n>" does not bypass this — keeper applies the label first
# (approve-issue.sh, which records the approval on the issue itself), then
# spins through THIS check. No bypass flag exists, deliberately.
ISSUE="${LANE%%-*}"
[[ "$ISSUE" =~ ^[0-9]+$ ]] || { echo "spin-lane: lane name must start with its issue number (<issue>-<slug>) — plan-mode wall" >&2; exit 1; }
TOKEN="$(grep '^LG_GITHUB_ISSUES_TOKEN=' /etc/looth/env | cut -d= -f2)"
[[ -n "$TOKEN" ]] || { echo "spin-lane: no GitHub token in /etc/looth/env — cannot verify approval, refusing" >&2; exit 1; }

# check_approved <n> → "yes <labels-csv>" or "no (reason)". One wall for the
# primary AND every rider (#141): a single unapproved number refuses the whole
# spawn. Labels feed the domain-dossier prepend (#142).
check_approved() {
    curl -s -m 15 -H "Authorization: Bearer $TOKEN" \
        "https://api.github.com/repos/iandavlin/loothplatformv2/issues/$1" \
      | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
    labels = [l["name"] for l in d.get("labels", [])]
    ok = "approved" in labels and d.get("state") == "open"
    print(("yes " + ",".join(labels)) if ok else "no (state=%s labels=%s)" % (d.get("state"), ",".join(labels) or "none"))
except Exception:
    print("no (API unreadable)")
'
}

# riders (#141): --riders 139,140 anywhere in the args
RIDERS=""
prev=""
for a in "$@"; do
    [[ "$prev" == "--riders" ]] && RIDERS="${a//,/ }"
    prev="$a"
done

GATE="$(check_approved "$ISSUE")"
[[ "$GATE" == yes* ]] || { echo "spin-lane: issue #$ISSUE is not an open, approved issue — $GATE — the wall holds" >&2; exit 1; }
ISSUE_LABELS="${GATE#yes }"
for r in $RIDERS; do
    RG="$(check_approved "$r")"
    [[ "$RG" == yes* ]] || { echo "spin-lane: rider #$r is not open+approved — $RG — one bad rider refuses the whole train" >&2; exit 1; }
    ISSUE_LABELS="$ISSUE_LABELS,${RG#yes }"
done
echo "spin-lane: issue #$ISSUE${RIDERS:+ (+ riders ${RIDERS// /, })} approved — the door opens"
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

echo "spin-lane: $LANE signs as '$(git -C "$WT" config user.name) <$(git -C "$WT" config user.email)>'"

# ── LANE-RULES.md §"What the tooling already handles" — keep these promises TRUE ──
# 1. Folder and branch always match. Three seat-reuse mismatches (8/18 audit) are
#    exactly the "which folder am I in" hazard; refuse rather than inherit one.
BRANCH="$(git -C "$WT" rev-parse --abbrev-ref HEAD)"
[[ "$BRANCH" == "$LANE" ]] || { echo "spin-lane: worktree is on '$BRANCH', not '$LANE' — folder and branch must match (LANE-RULES.md). Re-cut the worktree from origin/main; don't rename or checkout." >&2; exit 1; }

# 2. The branch exists on GitHub from the moment the lane opens.
git -C "$WT" rev-parse --abbrev-ref --symbolic-full-name '@{u}' >/dev/null 2>&1 \
  || { echo "spin-lane: pushing $LANE to origin (work exists in two places from the first commit)"; git -C "$WT" push -u origin "$LANE"; }

# riders recorded on the worktree (lanes-status reads this for the page) and
# on each rider issue's own record
if [[ -n "$RIDERS" ]]; then
    git -C "$WT" config --worktree lane.riders "$RIDERS"
    for r in $RIDERS; do
        curl -s -o /dev/null -m 15 -X POST -H "Authorization: Bearer $TOKEN" \
            -d "{\"body\":\"keeper: riding seat $LANE (batched under #$ISSUE per the rider mechanism).\"}" \
            "https://api.github.com/repos/iandavlin/loothplatformv2/issues/$r/comments" || true
    done
fi

cd "$WT"
# Prompt assembly, in reading order: LANE-RULES (law) → domain dossiers (#142:
# the accumulated knowledge for this issue's domain labels, so a fresh worker
# starts warm) → the charter → rider plans (#141), fetched from each rider
# issue's plan-ready comment.
PROMPT=""
RULES="$WT/LANE-RULES.md"
if [[ -f "$RULES" ]]; then
    PROMPT="$(cat "$RULES")"$'\n\n---\n\n'
else
    echo "spin-lane: WARN — no LANE-RULES.md in this worktree (pre-rules cut)" >&2
fi
for dom in email page membership profile infra; do
    if [[ ",$ISSUE_LABELS," == *",$dom,"* && -f "$WT/docs/domains/${dom^^}.md" ]]; then
        PROMPT+="$(cat "$WT/docs/domains/INDEX.md" 2>/dev/null)"$'\n\n'"$(cat "$WT/docs/domains/${dom^^}.md")"$'\n\n---\n\n'
    fi
done
PROMPT+="$(cat "$CHARTER")"
for r in $RIDERS; do
    RPLAN="$(curl -s -m 15 -H "Authorization: Bearer $TOKEN" \
        "https://api.github.com/repos/iandavlin/loothplatformv2/issues/$r/comments?per_page=50" \
      | python3 -c '
import json, sys
cs = json.load(sys.stdin)
plans = [c["body"] for c in cs if "Files I expect to touch" in c.get("body", "")]
print(plans[-1] if plans else "")
')"
    PROMPT+=$'\n\n---\n\n'"RIDER ISSUE #$r (approved; work it after the primary, same branch, close separately):"$'\n'"${RPLAN:-(no plan comment found — read the issue itself)}"
done
exec "$CLAUDE" --dangerously-skip-permissions --model "$MODEL" "$PROMPT"
