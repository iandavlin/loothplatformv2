#!/usr/bin/env bash
# 202-decision-preview — render THIS BRANCH's lanes page into the preview slot.
#
#   bash tools/preview/202-decision-preview.sh
#   → /home/ubuntu/worktrees/202-web-decision-box/.preview/index.html
#   → https://dev2.loothgroup.com/preview/202-web-decision-box/
#     (after: tools/preview/lane-preview.sh up 202-web-decision-box)
#
# WHY A SCRIPT AND NOT A COMMAND IN A HANDOFF. The lanes page is written by a
# systemd timer out of ~/keeper-repo, which carries MAIN. Rendering the branch
# by hand is three flags long and every one of them is a way to accidentally
# photograph main and call it verified — the box's most-repeated mistake. This
# is that command, in the repo, traceable to a commit.
#
# ⚠️ It reads the REAL question store (~/.lg-decisions) on purpose. The whole
# point of the preview is to see what Ian will see, and the store is the one
# input that must not be a fixture — a preview drawn from a made-up question
# proves the CSS and nothing else.
#
# ⚠️ It reuses the live page's own lanes.json as the seat input rather than
# running `lanes --json` again. That costs nothing, cannot flake, and cannot add
# load during a busy fleet — and the seats are not what this lane changed.
set -euo pipefail

BRANCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$BRANCH_DIR/.preview"
SEATS="${LG_PREVIEW_SEATS:-/var/www/dev/lanes/lanes.json}"

[ -f "$SEATS" ] || { echo "no seat data at $SEATS — is the timer alive?" >&2; exit 1; }

mkdir -p "$OUT"
python3 "$BRANCH_DIR/tools/lanes-page.py" \
    --json-file "$SEATS" \
    --out "$OUT" \
    --repo "$BRANCH_DIR"

echo "rendered: $OUT/index.html  ($(wc -c < "$OUT/index.html") bytes)"
echo -n "pending decisions baked into the button: "
python3 -c "import json;print(json.load(open('$OUT/lanes.json'))['decisions'])"
echo "URL: https://dev2.loothgroup.com/preview/202-web-decision-box/"
