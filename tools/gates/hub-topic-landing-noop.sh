#!/bin/bash
# hub-topic-landing-noop.sh — prove LG_HUB_TOPIC_LANDING OFF is a BYTE-IDENTICAL
# no-op, by rendering this branch and its merge-base and diffing the bytes.
#
# WHY THIS IS A SEPARATE SCRIPT AND NOT PART OF GATE 20. It needs a second
# checkout of the merge-base, so it is a PRE-MERGE proof, not a standing gate:
# once merged, merge-base == HEAD and it would compare a tree with itself. That
# is a green measuring nothing, so it refuses to run in that state rather than
# printing a pass (see the vacuity guard below).
#
# It lived in /tmp during development and a reboot took it, which is the other
# reason it is here: the merge rule "OFF must be a proven byte-identical no-op"
# is only as good as the thing that proves it, and that thing has to survive.
#
# WHAT IT CAUGHT, both of which look like nothing and are not:
#   · _feed.php shipping ONE EXTRA NEWLINE on every hub page with the flag off.
#     A 1-byte diff is still not a no-op.
#   · itself, reporting five routes IDENTICAL at 0 bytes — the scratchpad was
#     0700 so the pool user could not read the base tree, and two empty renders
#     compare equal. It now fails LOUD on an empty render.
#
# Renders run as the bb-mirror pool user, because that is who serves these
# routes; sudo strips the environment, so every variable is passed through `env`
# explicitly rather than exported and hoped for.
#
# Usage:  bash tools/gates/hub-topic-landing-noop.sh [base-ref]     (default: origin/main)
# Exit:   0 identical · 1 a difference · 2 CANNOT RUN (no verdict)
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_REF="${1:-origin/main}"
POOL_USER="${LG_NOOP_POOL_USER:-bb-mirror}"
# Deliberately NOT under the session scratchpad: that directory is 0700, and the
# pool user must be able to read every file it is asked to render.
WORKTREE="${LG_NOOP_WORKTREE:-/home/ubuntu/worktrees/_hub-topic-landing-noop-base}"

ROUTES=(
  "/hub/"
  "/hub/general/"
  "/hub/general/crazy-electrical-issue/"
  "/hub/nope/nothing-here/"
  "/hub/?body=32413"
  "/hub/?replies=32413"
  "/hub/?body=99999999"
  "/hub/?replies=99999999"
  "/hub/?q=neck"
  "/hub/general/?sort=newest"
  "/hub/?type=discussions"
)
API_ROUTES=(
  "/bb-mirror-api/v0/topic?forum=general&topic=crazy-electrical-issue"
  "/bb-mirror-api/v0/topic?forum=neck-reset-database&topic=harmony-sovereign-h182-1971"
  "/bb-mirror-api/v0/topic?forum=nope&topic=nothing"
)

die()  { echo "CANNOT RUN  $1" >&2; exit 2; }

command -v git >/dev/null || die "no git"
BASE_SHA=$(git -C "$REPO" rev-parse --verify "$BASE_REF^{commit}" 2>/dev/null) || die "cannot resolve $BASE_REF"
MERGE_BASE=$(git -C "$REPO" merge-base HEAD "$BASE_SHA" 2>/dev/null) || die "no merge-base with $BASE_REF"
HEAD_SHA=$(git -C "$REPO" rev-parse HEAD)

# ── VACUITY GUARD. Comparing a tree with itself is the easiest green there is
#    and it proves nothing at all. Refuse, loudly.
if [ "$MERGE_BASE" = "$HEAD_SHA" ]; then
    die "HEAD is its own merge-base with $BASE_REF — nothing to compare. This proof is PRE-MERGE only."
fi

rm -rf "$WORKTREE"
git -C "$REPO" worktree remove --force "$WORKTREE" >/dev/null 2>&1
git -C "$REPO" worktree add -q --detach "$WORKTREE" "$MERGE_BASE" || die "could not create the base worktree"
sudo -n -u "$POOL_USER" test -r "$WORKTREE/bb-mirror/web/index.php" \
  || die "$POOL_USER cannot read $WORKTREE — check the path is traversable (0700 dirs are the usual cause)"

# entry = <tree> <uri> <script-relative-path>
render() {
    sudo -n -u "$POOL_USER" env U="$2" T="$1" S="$3" php -r '
$_SERVER["REQUEST_URI"] = getenv("U");
$_SERVER["HTTP_HOST"]   = "dev2.loothgroup.com";
$_SERVER["REQUEST_METHOD"] = "GET";
require getenv("T") . "/" . getenv("S");' 2>/dev/null
}
# Cache-busters are filemtime(); two checkouts always differ there and it is not
# a content difference. Normalise ONLY that.
norm() { sed -E 's/\?v=[0-9]+/?v=N/g'; }

fail=0; n=0
check() {  # check <uri> <script>
    local a b
    a=$(render "$WORKTREE" "$1" "$2" | norm)
    b=$(render "$REPO"     "$1" "$2" | norm)
    n=$((n+1))
    if [ -z "$a" ] || [ -z "$b" ]; then
        printf '  ERROR      %-44s base=%sb head=%sb  (empty render — NOT measuring)\n' "$1" "${#a}" "${#b}"
        fail=1; return
    fi
    if [ "$a" = "$b" ]; then
        printf '  IDENTICAL  %-44s %8sb\n' "$1" "${#a}"
    else
        printf '  DIFFERS    %-44s base=%sb head=%sb\n' "$1" "${#a}" "${#b}"
        diff <(printf '%s' "$a" | fold -w110) <(printf '%s' "$b" | fold -w110) | head -8
        fail=1
    fi
}

echo "base $(git -C "$REPO" log --oneline -1 "$MERGE_BASE")"
echo "head $(git -C "$REPO" log --oneline -1 HEAD)"
echo "flag LG_HUB_TOPIC_LANDING unset (the shipped default)"
echo
for u in "${ROUTES[@]}";     do check "$u" "bb-mirror/web/index.php";      done
for u in "${API_ROUTES[@]}"; do check "$u" "bb-mirror/api/v0/topic.php";   done

git -C "$REPO" worktree remove --force "$WORKTREE" >/dev/null 2>&1
echo
if [ "$fail" -ne 0 ]; then
    echo "NOT A NO-OP — the OFF path differs from $BASE_REF. Do not merge."
    exit 1
fi
echo "NO-OP PROVEN — $n routes byte-identical to $BASE_REF with the flag off."
