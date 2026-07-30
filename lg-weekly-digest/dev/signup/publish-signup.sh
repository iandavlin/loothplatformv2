#!/usr/bin/env bash
# publish-signup.sh — put the signup-page design behind the dev gate for Ian.
#
# Same path and same discipline as publish-previs.sh: `location ^~ /v2/` aliases to
# /srv/lg-layout-v2/, whose tests/output/ is gitignored (lg-layout-v2/.gitignore:5),
# so publishing adds NOTHING to the serving checkout's porcelain and needs no serve
# window. dev2 is pull-only; nothing is ever hand-written into /var/www/dev.
#
#   bash lg-weekly-digest/dev/signup/publish-signup.sh
#   → https://dev2.loothgroup.com/v2/tests/output/wd-signup/index.html
#
# The sample email (signup-sample-email.html) is a REAL render — the live builder's
# output for the issue, with no recap section injected, which is exactly what a
# list-7 non-member receives. Regenerate it with dev/render-axis2-frames.php's
# sibling snippet if the template changes; do not hand-edit it.
set -euo pipefail

LANE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SRC="$LANE/lg-weekly-digest/dev/signup"
DEST=/srv/lg-layout-v2/tests/output/wd-signup
URL=https://dev2.loothgroup.com/v2/tests/output/wd-signup/index.html

mkdir -p "$DEST"
cp "$SRC"/*.html "$DEST/"

# THE MOCK MUST NOT BE ABLE TO POST ANYWHERE. It carries a real-looking form, and a
# stray action= would make it a live signup form serving a dev-gated page. Asserted
# rather than trusted, because that is the failure that would not announce itself.
if grep -q 'action="' "$DEST/mock-signup.html"; then
  echo "REFUSING: mock-signup.html has a form action — it must be inert." >&2; exit 1
fi

# Publishing must not have dirtied the serving checkout. Porcelain is the only thing
# that can see a staged or untracked file; a tree hash cannot.
SERVE=/home/ubuntu/loothplatformv2-clean
DIRT="$(git -C "$SERVE" status --porcelain | wc -l)"
echo "serve porcelain after publish: $DIRT  (must be 0)"
[ "$DIRT" -eq 0 ] || { echo "REFUSING: publish dirtied the serving checkout." >&2; exit 1; }

echo "published $(ls -1 "$DEST"/*.html | wc -l) files"
echo "$URL"
echo
echo "VERIFY THE GATE FROM THE LAN IP, NOT LOOPBACK — geo \$loothdev_src_local"
echo "authorizes 127.0.0.1 outright, so a loopback 200 is the gate NOT RUNNING:"
echo "  curl -so/dev/null -w '%{http_code}\\n' --resolve dev2.loothgroup.com:443:$(hostname -I | awk '{print $1}') $URL   # expect 403"
