#!/usr/bin/env bash
# provision-sheets-bot.sh — box-local provisioning for the Showrunner Sheet bot.
#
# The Google Sheet ("Looth Group Live — Showrunner Tracker") authenticates to the
# WordPress sheets-bridge REST API (/wp-json/loothdev/v1/*) as a dedicated WP user
# `sheets-bot` using a WordPress Application Password (Basic auth). This script:
#
#   1. Ensures the `sheets-bot` WP user exists (role: author — has publish_posts +
#      upload_files, which the bridge's permission callback requires). Idempotent.
#   2. Mints a fresh Application Password labelled "Showrunner Tracker Sheet" and
#      PRINTS it ONCE for pasting into the Sheet's Script Properties.
#
# The secret VALUE is never written to disk or git — it is shown on stdout only.
# It lives ONLY in the Sheet: Apps Script -> Project Settings -> Script Properties
#   WP_USERNAME      = sheets-bot
#   WP_APP_PASSWORD  = <the value this script prints>   (paste WITHOUT spaces)
#
# WP user/path come from the single per-box knob (/etc/looth/env). Run on the box
# whose WordPress the Sheet should publish to:
#   dev2:  bash platform/bin/provision-sheets-bot.sh
#   live:  bash platform/bin/provision-sheets-bot.sh         (after setting /etc/looth/env)
#
# A WP DB reload WIPES application passwords (and can recycle the user ID) — re-run
# this after every reload and re-paste the printed password into Script Properties.
#
# Flags:
#   --rotate   delete any existing "Showrunner Tracker Sheet" app-passwords first
#              (use when rotating a leaked/old credential; default is to ADD one).
set -euo pipefail

BOT_LOGIN="sheets-bot"
BOT_EMAIL="sheets-bot@loothgroup.com"
BOT_ROLE="author"
BOT_DISPLAY="Showrunner Sheet Bot"
APP_PW_LABEL="Showrunner Tracker Sheet"

ROTATE=0
[ "${1:-}" = "--rotate" ] && ROTATE=1

# Single per-box knob.
WPUSER="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_USER:-looth-dev}")"
WPPATH="$(. /etc/looth/env 2>/dev/null; echo "${LG_WP_PATH:-/var/www/dev}")"
HOST="$(. /etc/looth/env 2>/dev/null; echo "${LG_PUBLIC_HOST:-dev2.loothgroup.com}")"

wp() { sudo -u "$WPUSER" wp --path="$WPPATH" "$@"; }

echo "Provisioning '$BOT_LOGIN' on $HOST  (WPUSER=$WPUSER  WPPATH=$WPPATH)"

# 1) user — create if absent, else ensure role.
if wp user get "$BOT_LOGIN" --field=ID >/dev/null 2>&1; then
  BOT_ID="$(wp user get "$BOT_LOGIN" --field=ID)"
  echo "✓ user exists (ID $BOT_ID)"
  # ensure the role grants publish_posts + upload_files
  if ! wp user list --login="$BOT_LOGIN" --field=roles | grep -qw "$BOT_ROLE"; then
    wp user set-role "$BOT_LOGIN" "$BOT_ROLE"
    echo "  · role set -> $BOT_ROLE"
  fi
else
  BOT_ID="$(wp user create "$BOT_LOGIN" "$BOT_EMAIL" \
              --role="$BOT_ROLE" --display_name="$BOT_DISPLAY" --porcelain)"
  echo "✓ user created (ID $BOT_ID)"
fi

# 2) application password.
if [ "$ROTATE" -eq 1 ]; then
  # delete existing app-passwords with our label (uuid per row)
  for uuid in $(wp user application-password list "$BOT_LOGIN" \
                  --name="$APP_PW_LABEL" --field=uuid 2>/dev/null || true); do
    wp user application-password delete "$BOT_LOGIN" "$uuid" >/dev/null 2>&1 || true
    echo "  · revoked old app-password $uuid"
  done
fi

APP_PW="$(wp user application-password create "$BOT_LOGIN" "$APP_PW_LABEL" --porcelain)"

cat <<EOF

------------------------------------------------------------------
  PASTE INTO THE SHEET  (Apps Script -> Project Settings ->
  Script Properties). Shown ONCE — not stored anywhere.

    WP_HOST          = $HOST
    WP_USERNAME      = $BOT_LOGIN
    WP_APP_PASSWORD  = $APP_PW
                       (paste WITHOUT the spaces)

  Also re-resolve the Sheet's per-row WP author IDs after a DB
  reload (the bot ID and authors are per-box): clear Config col D
  and re-run the Sheet's user lookup.
------------------------------------------------------------------
EOF
