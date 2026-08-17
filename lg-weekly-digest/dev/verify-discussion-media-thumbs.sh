#!/usr/bin/env bash
# verify-discussion-media-thumbs.sh — wrapper that makes the two traps unmissable.
#
# 1. --skip-plugins=lg-weekly-digest. The plugin is ACTIVE on dev2, so without this
#    WP declares LG_WD_Query at boot and the test would either fatal on redeclare or
#    silently prove the DEPLOYED class while reporting the branch.
# 2. sudo -u looth-dev. `ubuntu` is not in loothdevs and cannot read wp-config.php;
#    wp then says "not a WordPress installation", which reads like a warning next to
#    a pass rather than a failure.
#
# Exit: 0 green · 1 RED · 2 CANNOT RUN.
set -uo pipefail

WP_PATH="${LG_WP_PATH:-/var/www/dev}"
DEV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ ! -f "$WP_PATH/wp-load.php" ]; then
  echo "CANNOT RUN: no WordPress at $WP_PATH" >&2
  exit 2
fi

sudo -u looth-dev wp --path="$WP_PATH" --skip-plugins=lg-weekly-digest \
  eval-file "$DEV/verify-discussion-media-thumbs.php" 2>&1 \
  | grep -v 'PHP Warning:  Constant DISABLE_WP_CRON already defined' \
  | grep -v 'already defined in phar'
exit "${PIPESTATUS[0]}"
