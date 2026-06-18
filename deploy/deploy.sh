#!/usr/bin/env bash
# looth-platform deploy — places each repo subtree at its live target.
# Source of truth = this repo. See MANIFEST.md.
#
# Usage:
#   ./deploy.sh            # DRY RUN (shows what would change, touches nothing)
#   ./deploy.sh --apply    # actually deploy
#
# Run ON THE TARGET SERVER (new box / live). Live is Claude-free — a human runs this.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WP="${WP_PATH:-/var/www/html}"             # live WP docroot
DRY="--dry-run"; [ "${1:-}" = "--apply" ] && DRY=""

rs(){ rsync -a $DRY --exclude='*.bak*' --exclude='vendor' --exclude='node_modules' "$1" "$2"; }

echo "REPO=$REPO  WP=$WP  MODE=$([ -z "$DRY" ] && echo APPLY || echo DRY-RUN)"

# --- standalone apps → /srv ---
for app in profile-app archive-poc bb-mirror lg-shared events; do
  rs "$REPO/$app/" "/srv/$app/"
done

# --- WP plugins → wp-content/plugins ---
for pl in lg-layout-v2 lg-legacy-import lg-patreon-stripe-poller; do
  rs "$REPO/$pl/" "$WP/wp-content/plugins/$pl/"
done

# --- mu-plugins → wp-content/mu-plugins (FLAT .php; lg-membership-chrome dir needs its loader) ---
rs "$REPO/platform/mu-plugins/" "$WP/wp-content/mu-plugins/"

# --- server config ---
rs "$REPO/platform/nginx/"   "/etc/nginx/snippets/"
rs "$REPO/platform/fpm/"     "/etc/php/8.3/fpm/pool.d/"
rs "$REPO/platform/systemd/" "/etc/systemd/system/"

if [ -z "$DRY" ]; then
  echo "--- post-deploy ---"
  echo "chown app dirs to their service users; provision /etc/lg-* secrets (see MANIFEST)"
  echo "nginx -t && systemctl reload nginx"
  echo "systemctl reload php8.3-fpm"
  echo "systemctl daemon-reload && systemctl enable --now bb-mirror-reconcile.timer"
fi
echo "done ($([ -z "$DRY" ] && echo applied || echo dry-run))."
