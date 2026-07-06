#!/bin/bash
# provision-message-media.sh — provision a box for DM image attachments
# (lane: message-images). Idempotent; safe to re-run.
#
# REPO-MANDATE: this RECIPE lives in the repo; the secret VALUES never do.
# Pass the R2 credentials via environment when the dedicated messages bucket
# + its scoped token exist (until then, the app falls back to a local store
# under /srv/profile-app-message-media and everything works identically):
#
#   sudo LG_MSG_R2_BUCKET=<bucket> LG_MSG_R2_KEY=<access-key-id> \
#        LG_MSG_R2_SECRET=<secret> ./provision-message-media.sh
#
# What it does:
#   1. /srv/profile-app-message-media        local-fallback store (profile-app:profile-app, 2775)
#   2. /etc/looth/messages-r2                secret file (640 root:profile-app) — only if
#                                            LG_MSG_R2_BUCKET/KEY/SECRET are provided AND the
#                                            file does not already exist (never clobbers)
#   3. sql/2026-06-30-message-media.sql      applied as postgres (idempotent ADD COLUMN IF NOT EXISTS)
#   4. nginx                                 NOT touched — apply deploy/message-images.nginx.md
#                                            by hand, then nginx -t && systemctl reload nginx
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "run as root (sudo)"; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"

# R2 account endpoint (fixed account 2b34fc01…; override only if the account moves)
ENDPOINT="${LG_MSG_R2_ENDPOINT:-https://2b34fc01f7fc32230a76c1490ac64b13.r2.cloudflarestorage.com}"
CONF=/etc/looth/messages-r2

echo "== 1. local-fallback store =="
mkdir -p /srv/profile-app-message-media
chown profile-app:profile-app /srv/profile-app-message-media
chmod 2775 /srv/profile-app-message-media
ls -ld /srv/profile-app-message-media

echo "== 2. secret file =="
if [ -f "$CONF" ]; then
    echo "$CONF already exists — leaving it alone"
elif [ -n "${LG_MSG_R2_BUCKET:-}" ] && [ -n "${LG_MSG_R2_KEY:-}" ] && [ -n "${LG_MSG_R2_SECRET:-}" ]; then
    umask 027
    cat > "$CONF" <<CONF_EOF
# DM image attachments — DEDICATED messages bucket (NOT the profile bucket).
# Same shape as /etc/looth/profile-r2. Read by profile-app/src/MessageR2.php.
endpoint=$ENDPOINT
bucket=$LG_MSG_R2_BUCKET
key=$LG_MSG_R2_KEY
secret=$LG_MSG_R2_SECRET
CONF_EOF
    chown root:profile-app "$CONF"
    chmod 640 "$CONF"
    echo "wrote $CONF (640 root:profile-app, bucket=$LG_MSG_R2_BUCKET)"
else
    echo "no creds passed and $CONF absent — MessageR2 stays disabled (local fallback)."
    echo "when the bucket + scoped token exist: re-run with LG_MSG_R2_BUCKET/KEY/SECRET set."
fi

echo "== 3. pg migration (idempotent) =="
sudo -u postgres psql -d profile_app -v ON_ERROR_STOP=1 -f "$HERE/../sql/2026-06-30-message-media.sql"
sudo -u postgres psql -d profile_app -At -c \
  "SELECT string_agg(column_name, ',') FROM information_schema.columns
    WHERE table_name='messages' AND column_name LIKE 'media_%';"

echo "== 4. nginx (manual) =="
echo "apply $HERE/message-images.nginx.md to the live strangler snippet, then:"
echo "  nginx -t && systemctl reload nginx"
echo "done."
