#!/usr/bin/env bash
# install-sheets-bridge.sh — point WordPress at the repo copy of the Showrunner
# sheets-bridge mu-plugin, so the LIVE file == the repo file and deploy = git pull
# (same pattern as lg-secrets-dash / lg-siteurl-from-env).
#
# Replaces any STANDALONE copy of loothdev-sheets-bridge.php in WP's mu-plugins
# with a symlink into the serve tree. The old standalone (if any) is backed up
# next to it once, then removed. Idempotent — safe to re-run after every git pull.
#
#   sudo platform/bin/install-sheets-bridge.sh
#
# WP user/path come from the single per-box knob (/etc/looth/env).
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "must run as root (sudo)"; exit 1; }

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_MU="$REPO/platform/mu-plugins/loothdev-sheets-bridge.php"
SRC_GS="$REPO/platform/mu-plugins/loothdev-sheets-bridge.apps-script.gs.txt"

WPUSER="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_USER:-looth-dev}")"
WPPATH="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_PATH:-/var/www/dev}")"
MU_DIR="$WPPATH/wp-content/mu-plugins"
DST="$MU_DIR/loothdev-sheets-bridge.php"

echo "REPO=$REPO  WPUSER=$WPUSER  MU_DIR=$MU_DIR"
[ -f "$SRC_MU" ] || { echo "✗ repo mu-plugin missing: $SRC_MU"; exit 1; }
[ -d "$MU_DIR" ] || { echo "… mu-plugins dir not found ($MU_DIR) — nothing to do"; exit 0; }

# If a real (non-symlink) standalone file is present, archive it once before replacing.
if [ -f "$DST" ] && [ ! -L "$DST" ]; then
  BAK="$DST.standalone-bak-$(date +%Y%m%d%H%M%S)"
  cp -a "$DST" "$BAK"
  echo "✓ standalone backed up -> $BAK"
fi

ln -sfn "$SRC_MU" "$DST"
chown -h "$WPUSER":"$(id -gn "$WPUSER")" "$DST" 2>/dev/null || true
echo "✓ mu-plugin   -> $DST -> $SRC_MU (symlink)"

# The Apps Script source-of-record (a .gs.txt next to the plugin) is reference
# only; keep it symlinked too so the box always shows what the Sheet should run.
if [ -f "$SRC_GS" ]; then
  ln -sfn "$SRC_GS" "$MU_DIR/loothdev-sheets-bridge.apps-script.gs.txt"
  chown -h "$WPUSER":"$(id -gn "$WPUSER")" "$MU_DIR/loothdev-sheets-bridge.apps-script.gs.txt" 2>/dev/null || true
  echo "✓ apps-script -> symlinked (reference)"
fi

echo "--- verify ---"
ls -l "$DST"
php -l "$SRC_MU" >/dev/null && echo "✓ php lint ok"
echo "done."
