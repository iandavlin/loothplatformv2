#!/usr/bin/env bash
# publish-previs.sh — put the suppression previs behind the dev gate so Ian can look at it.
#
# WHY THIS PATH AND NOT THE DOCROOT: dev2 is pull-only and nothing may be written
# into /var/www/dev (keeper, 2026-07-27). `location ^~ /v2/` aliases to
# /srv/lg-layout-v2/, which is a symlink into the SERVING CHECKOUT — but
# tests/output/ is gitignored there (lg-layout-v2/.gitignore:5), so publishing into
# it adds nothing to the serve's porcelain and needs no serve window. That is the
# same path the thread-follow lane publishes its mocks to.
#
# The SOURCE lives in the repo (dev/previs/index.html + dev/frames/*.html). This
# script only copies. Nothing here is hand-authored on the box.
#
#   bash lg-weekly-digest/dev/publish-previs.sh
#   → https://dev2.loothgroup.com/v2/tests/output/wd-recap/index.html
set -euo pipefail

LANE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST=/srv/lg-layout-v2/tests/output/wd-recap
URL=https://dev2.loothgroup.com/v2/tests/output/wd-recap/index.html

mkdir -p "$DEST"
cp "$LANE/lg-weekly-digest/dev/previs/index.html" "$DEST/"
cp "$LANE/lg-weekly-digest/dev/frames/"*.html     "$DEST/"

# The publish target must not have dirtied the serving checkout. Prove it, do not
# assume it — porcelain is the only thing that can see a staged or untracked file.
SERVE=/home/ubuntu/loothplatformv2-clean
DIRT="$(git -C "$SERVE" status --porcelain | wc -l)"
echo "serve porcelain lines after publish: $DIRT  (must be 0)"
[ "$DIRT" -eq 0 ] || { echo "PUBLISH DIRTIED THE SERVE — stop and clean up"; exit 1; }

echo "published $(ls -1 "$DEST" | wc -l) files"
echo "$URL"
