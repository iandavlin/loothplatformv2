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
# DEV-ONLY EXCLUSION (marker-driven): never ship files tagged `@lg-dev-only` to live
# (mail killswitch, dev-mail containment, looth1-bounce disabler, dev2 power, secrets dash).
# Future dev-only files auto-exclude — just add the @lg-dev-only tag to the file header.
mu_excludes=( --exclude='*.bak*' --exclude='vendor' --exclude='node_modules' )
while IFS= read -r _devonly; do
  [ -n "$_devonly" ] && mu_excludes+=( --exclude="$(basename "$_devonly")" )
done < <(grep -rlF '@lg-dev-only' "$REPO/platform/mu-plugins/" 2>/dev/null || true)
echo "mu-plugins dev-only excludes: $(printf '%s ' "${mu_excludes[@]}" | grep -oE -- '--exclude=[^ ]+\.php' | sed 's/--exclude=//' | tr '\n' ' ' || true)"
rsync -a $DRY "${mu_excludes[@]}" "$REPO/platform/mu-plugins/" "$WP/wp-content/mu-plugins/"

# --- server config ---
rs "$REPO/platform/nginx/"   "/etc/nginx/snippets/"
rs "$REPO/platform/fpm/"     "/etc/php/8.3/fpm/pool.d/"
rs "$REPO/platform/systemd/" "/etc/systemd/system/"

# --- webroot static overlay layer → live docroot (PUSH model; standalone: webroot/deploy.sh) ---
# Live serves real files here; dev2 serves them via SYMLINKS into the serve clone (pull-driven).
# GUARD: if the target already serves overlays via symlink, this is a pull-driven box (dev2) and
# rsync -a would replace the symlinks with file copies, silently undoing the repo-serve rewire.
# So refuse — dev2 is pull-only; only live (real files) gets the push. WEBROOT_PATH overrides $WP.
WEBROOT_TARGET="${WEBROOT_PATH:-$WP}"
if [ -L "$WEBROOT_TARGET/bottom-nav.js" ] || [ -L "$WEBROOT_TARGET/pwa.js" ]; then
  echo "SKIP webroot: '$WEBROOT_TARGET' serves overlays via symlink (pull-driven / dev2) — refusing rsync (would clobber the symlink rewire)."
else
  rsync -a $DRY --chown="${WEBROOT_OWNER:-looth-dev}:${WEBROOT_OWNER:-looth-dev}" \
    --exclude='README.md' --exclude='deploy.sh' --exclude='.gitignore' \
    --exclude='*.bak*' --exclude='vendor' --exclude='node_modules' \
    "$REPO/webroot/" "$WEBROOT_TARGET/"
fi

if [ -z "$DRY" ]; then
  echo "--- post-deploy ---"
  echo "chown app dirs to their service users; provision /etc/lg-* secrets (see MANIFEST)"
  echo "nginx -t && systemctl reload nginx"
  echo "systemctl reload php8.3-fpm"
  echo "systemctl daemon-reload && systemctl enable --now bb-mirror-reconcile.timer"
fi
echo "done ($([ -z "$DRY" ] && echo applied || echo dry-run))."
