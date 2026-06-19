#!/usr/bin/env bash
# install-secrets-bridge.sh — box-local provisioning for the Looth secrets dash.
#
# Pins the privileged helper to a ROOT-OWNED path (so the user-writable repo can
# never be sudo-executed), installs a validated sudoers rule, creates the audit
# log, and (on dev) symlinks the mu-plugin into WordPress.
#
# Idempotent — re-run after every `git pull`. Must be run as root.
#   sudo platform/bin/install-secrets-bridge.sh
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "must run as root (sudo)"; exit 1; }

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_HELPER="$REPO/platform/bin/lg-secrets-helper"
SRC_MANIFEST="$REPO/platform/secrets/manifest.php"
SRC_MU="$REPO/platform/mu-plugins/lg-secrets-dash.php"
SRC_SUDOERS="$REPO/platform/sudoers/lg-secrets"

DST_HELPER="/usr/local/sbin/lg-secrets-helper"
DST_MANIFEST="/usr/local/lib/looth/lg-secrets-manifest.php"
DST_SUDOERS="/etc/sudoers.d/lg-secrets"
AUDIT="/var/log/lg-secrets-audit.log"

# WP user/path from the single per-box knob
WPUSER="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_USER:-looth-dev}")"
WPPATH="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_PATH:-/var/www/dev}")"

echo "REPO=$REPO  WPUSER=$WPUSER  WPPATH=$WPPATH"

# 1) helper -> root-owned, root-only-writable
install -o root -g root -m 0755 "$SRC_HELPER" "$DST_HELPER"
echo "✓ helper      -> $DST_HELPER"

# 2) manifest -> root-owned (NOT loaded from the repo, on purpose)
install -d -o root -g root -m 0755 /usr/local/lib/looth
install -o root -g root -m 0644 "$SRC_MANIFEST" "$DST_MANIFEST"
echo "✓ manifest    -> $DST_MANIFEST"

# 3) sudoers — render, validate with visudo, then install atomically
TMP_SUDO="$(mktemp)"
sed "s/@WPUSER@/${WPUSER}/" "$SRC_SUDOERS" > "$TMP_SUDO"
if visudo -cf "$TMP_SUDO" >/dev/null; then
  install -o root -g root -m 0440 "$TMP_SUDO" "$DST_SUDOERS"
  echo "✓ sudoers     -> $DST_SUDOERS"
else
  rm -f "$TMP_SUDO"; echo "✗ sudoers failed validation — aborting"; exit 1
fi
rm -f "$TMP_SUDO"

# 4) audit log
if [ ! -f "$AUDIT" ]; then
  install -o root -g root -m 0600 /dev/null "$AUDIT"
  echo "✓ audit log   -> $AUDIT"
fi

# 5) mu-plugin: symlink on dev (live deploys via deploy.sh rsync instead)
MU_DIR="$WPPATH/wp-content/mu-plugins"
if [ -d "$MU_DIR" ]; then
  ln -sfn "$SRC_MU" "$MU_DIR/lg-secrets-dash.php"
  echo "✓ mu-plugin   -> $MU_DIR/lg-secrets-dash.php (symlink)"
else
  echo "… mu-plugins dir not found ($MU_DIR) — skipping symlink (live: use deploy.sh)"
fi

echo "--- smoke test ---"
sudo -u "$WPUSER" sudo -n "$DST_HELPER" list >/dev/null \
  && echo "✓ $WPUSER can run helper via sudo, manifest loads" \
  || echo "✗ smoke test failed — check sudoers / manifest"
echo "done."
