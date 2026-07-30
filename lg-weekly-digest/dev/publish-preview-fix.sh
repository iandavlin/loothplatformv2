#!/usr/bin/env bash
# publish-preview-fix.sh — put the before/after for the sample-email panel behind
# the dev gate so Ian can look at it before keeper merges.
#
# SAME PATH AND SAME REASONING AS publish-previs.sh: dev2 is pull-only and nothing
# may be written into /var/www/dev. `location ^~ /v2/` aliases to /srv/lg-layout-v2/,
# and tests/output/ is gitignored there (lg-layout-v2/.gitignore:5), so publishing
# into it adds nothing to the serving checkout's porcelain and needs no serve window.
#
# THE SOURCE IS IN THE REPO (dev/previs-preview-fix/index.html). This script only
# copies. Nothing is hand-authored on the box.
#
# WHAT MAKES THIS ONE HONEST WITHOUT A MERGE: both panels frame LIVE URLs on the
# current serve. The "after" is not simulated — /weekly-email-sign-up/?lg_wd_email_preview=1
# already renders the real email on main today. The bug being fixed is only WHICH of
# those two addresses the signup page points its panel at.
set -uo pipefail

LANE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="$LANE/lg-weekly-digest/dev/previs-preview-fix/index.html"
DEST=/srv/lg-layout-v2/tests/output/wd-preview-fix
URL=https://dev2.loothgroup.com/v2/tests/output/wd-preview-fix/index.html
SERVE=/home/ubuntu/loothplatformv2-clean

[ -r "$SRC" ] || { echo "CANNOT PUBLISH: source missing: $SRC"; exit 2; }

# Refuse to publish from a dirty serve — otherwise the porcelain check below cannot
# tell my publish from somebody else's in-flight work.
BEFORE="$(git -C "$SERVE" status --porcelain | wc -l)"
[ "$BEFORE" -eq 0 ] || { echo "SERVE ALREADY DIRTY ($BEFORE lines) — not publishing"; exit 2; }

mkdir -p "$DEST"
cp "$SRC" "$DEST/index.html"

# Assert the bytes landed. A previous script in this lane printed a success line
# built from strlen() rather than the write result and reported "wrote 2 frames"
# over an empty directory. Assert the write, never your intentions.
BYTES="$(wc -c < "$DEST/index.html" 2>/dev/null || echo 0)"
[ "$BYTES" -gt 2000 ] || { echo "WRITE FAILED OR TRUNCATED: $BYTES bytes at $DEST/index.html"; exit 2; }

AFTER="$(git -C "$SERVE" status --porcelain | wc -l)"
echo "serve porcelain after publish: $AFTER (must be 0)"
[ "$AFTER" -eq 0 ] || { echo "PUBLISH DIRTIED THE SERVE — stop and clean up"; exit 1; }

echo "published $BYTES bytes"
echo "$URL"
