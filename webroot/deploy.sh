#!/usr/bin/env bash
# Deploy the loothgroup webroot static layer from this repo dir to a live webroot.
# Run as root (it sets ownership). Idempotent.
#
#   sudo ./deploy.sh [WEBROOT] [OWNER]
#     WEBROOT  target docroot   (default: /var/www/dev)
#     OWNER    file owner:group (default: looth-dev   -> looth-dev:looth-dev)
#
# Copies every asset here EXCEPT the repo meta files (README.md, deploy.sh, .gitignore).
# Cache-busting is the ?v=N strings inside pwa.js — bump those when a file changes; filenames
# don't change, so no rename step.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
WEBROOT="${1:-/var/www/dev}"
OWNER="${2:-looth-dev}"

[ -d "$WEBROOT" ] || { echo "ERROR: webroot '$WEBROOT' does not exist" >&2; exit 1; }
echo "Deploying webroot static layer:"
echo "  from: $SRC"
echo "  to:   $WEBROOT   (owner ${OWNER}:${OWNER})"

rsync -a --chown="${OWNER}:${OWNER}" \
  --exclude 'README.md' --exclude 'deploy.sh' --exclude '.gitignore' \
  "$SRC"/ "$WEBROOT"/

echo "Done. Deployed files:"
( cd "$SRC" && ls -1 | grep -vE '^(README\.md|deploy\.sh|\.gitignore)$' )
