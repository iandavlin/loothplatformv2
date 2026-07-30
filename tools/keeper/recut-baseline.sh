#!/usr/bin/env bash
# Re-cut ~/keeper-baseline — the byte-for-byte restore proof of the serving checkout.
# Run ONLY at a moment worth restoring TO: serve on main, porcelain empty, deploy done.
# mu-set recipe verified 2026-07-29: `ls -1 <mu-dir> | sha256sum` reproduces the
# 2026-07 baseline hash exactly. Needs sudo for the mu-plugins dir.
set -euo pipefail

SERVE=/home/ubuntu/loothplatformv2-clean
OUT=/home/ubuntu/keeper-baseline
DOCROOT=/var/www/dev
MU="$DOCROOT/wp-content/mu-plugins"

dirty=$(git -C "$SERVE" status --porcelain)
if [ -n "$dirty" ]; then
    echo "REFUSED: serve porcelain is not empty — a dirty serve is not a baseline." >&2
    echo "$dirty" >&2
    exit 1
fi

git -C "$SERVE" rev-parse HEAD            > "$OUT/serve-head.txt"
git -C "$SERVE" branch --show-current     > "$OUT/serve-branch.txt"
git -C "$SERVE" rev-parse HEAD:           > "$OUT/serve-tree.txt"
git -C "$SERVE" status --porcelain        > "$OUT/serve-status.txt"
( cd "$DOCROOT" && ls -1 *.js *.css 2>/dev/null | sort | xargs sha256sum ) > "$OUT/docroot-sha256.txt"
sudo ls -1 "$MU" | sha256sum              > "$OUT/mu-set.txt"

echo "baseline re-cut: serve $(git -C "$SERVE" rev-parse --short HEAD) on $(cat "$OUT/serve-branch.txt"), $(wc -l < "$OUT/docroot-sha256.txt") docroot files, mu-set $(cut -c1-12 "$OUT/mu-set.txt")…"
