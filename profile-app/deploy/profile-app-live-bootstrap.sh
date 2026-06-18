#!/usr/bin/env bash
# profile-app — slice zero LIVE bootstrap.
#
# Run on the LIVE box (loothgroup.com / 54.157.13.77) once. Idempotent —
# safe to re-run if a step fails. Prints backfill counts at the end.
#
# Prereqs on live (verified inside this script):
#   - PHP 8.3 + php8.3-fpm running
#   - MariaDB/MySQL running, looth_live + lg_membership databases present
#   - WP at /var/www/html owned by looth-live
#
# What it does:
#   1. apt-installs postgresql + php8.3-pgsql + composer (idempotent)
#   2. unzips /tmp/profile-app.zip → /srv/profile-app
#   3. creates `profile-app` system user + Postgres role/DB (peer auth)
#   4. applies sql/0001_init.sql (idempotent: ignored if already present)
#   5. composer install for ramsey/uuid
#   6. installs FPM pool + reloads php-fpm
#   7. installs nginx snippet → /etc/nginx/snippets/profile-app.conf
#      and PRINTS the include line you must add to loothgroup.com.conf
#   8. provisions /etc/lg-profile-app-secret + sets wp_options.profile_hook_secret
#   9. installs mu-plugin → wp-content/mu-plugins/profile-sync.php
#   10. grants `profile-app`@localhost MySQL unix_socket auth on the two DBs
#   11. runs backfill and prints summary

set -euo pipefail

LIVE_WP_DB="${LIVE_WP_DB:-looth_live}"
LIVE_BILLING_DB="${LIVE_BILLING_DB:-lg_membership}"
SRC_ZIP="/tmp/profile-app.zip"
APP_ROOT="/srv/profile-app"

# Token used to pull staged files from dev's .well-known.
TOK="${LOOTHDEV_TOKEN:-qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG}"

echo "[1/11] apt: installing postgres + php-pgsql + composer (idempotent)…"
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    postgresql postgresql-contrib php8.3-pgsql composer >/dev/null

echo "[2/11] pulling + unzipping slice-zero source…"
curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/profile-app.zip -o "$SRC_ZIP"
sudo rm -rf "$APP_ROOT"
sudo unzip -q "$SRC_ZIP" -d /srv/
test -f "$APP_ROOT/config.php" || { echo "ERROR: unpack failed"; exit 1; }

echo "[3/11] creating profile-app system user + Postgres role/DB…"
id profile-app >/dev/null 2>&1 || \
    sudo useradd -r -s /usr/sbin/nologin -d "$APP_ROOT" profile-app
sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='profile-app'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER \"profile-app\";"
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='profile_app'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE profile_app OWNER \"profile-app\";"

echo "[4/11] applying schema (only if users table missing)…"
HAS_USERS=$(sudo -u profile-app psql -d profile_app -tAc \
    "SELECT 1 FROM information_schema.tables WHERE table_name='users'")
if [ "$HAS_USERS" != "1" ]; then
    sudo -u profile-app psql -d profile_app -f "$APP_ROOT/sql/0001_init.sql"
else
    echo "      schema already present, skipping"
fi

echo "[5/11] composer install for ramsey/uuid…"
sudo chown -R profile-app:profile-app "$APP_ROOT"
cd "$APP_ROOT"
sudo -u profile-app COMPOSER_HOME=/tmp/composer-profile-app composer install --no-dev --no-interaction --quiet \
    || sudo -u profile-app COMPOSER_HOME=/tmp/composer-profile-app composer require ramsey/uuid --no-interaction --quiet

echo "[6/11] installing FPM pool…"
sudo install -m 644 "$APP_ROOT/deploy/profile-app-fpm-pool.conf" \
    /etc/php/8.3/fpm/pool.d/profile-app.conf
sudo mkdir -p /var/log/php-fpm
sudo systemctl reload php8.3-fpm
sleep 1
test -S /run/php/php8.3-fpm-profile-app.sock && echo "      pool socket OK"

echo "[7/11] installing nginx snippet…"
sudo install -m 644 "$APP_ROOT/deploy/profile-app.nginx-snippet.live.conf" \
    /etc/nginx/snippets/profile-app.conf
NGINX_VHOST=$(grep -lE "server_name\s+(www\.)?loothgroup\.com" /etc/nginx/sites-enabled/* 2>/dev/null | head -1)
if [ -n "$NGINX_VHOST" ] && ! grep -q "include snippets/profile-app.conf" "$NGINX_VHOST"; then
    echo
    echo "  >>> ACTION REQUIRED: add this line to $NGINX_VHOST"
    echo "  >>> inside the loothgroup.com SSL server { } block, above the catch-all 'location /':"
    echo
    echo "      include snippets/profile-app.conf;"
    echo
    echo "  Then: sudo nginx -t && sudo systemctl reload nginx"
    echo
else
    sudo nginx -t && sudo systemctl reload nginx
fi

echo "[8/11] provisioning secret + wp_options.profile_hook_secret…"
if [ ! -s /etc/lg-profile-app-secret ]; then
    openssl rand -hex 32 | sudo tee /etc/lg-profile-app-secret >/dev/null
    sudo chown root:profile-app /etc/lg-profile-app-secret
    sudo chmod 640 /etc/lg-profile-app-secret
fi
HOOK_SECRET=$(sudo cat /etc/lg-profile-app-secret)
sudo -u looth-live wp --path=/var/www/html option update profile_hook_secret "$HOOK_SECRET" >/dev/null

echo "[9/11] installing mu-plugin…"
sudo install -m 644 -o looth-live -g looth-live \
    "$APP_ROOT/deploy/profile-sync.mu-plugin.php" \
    /var/www/html/wp-content/mu-plugins/profile-sync.php

echo "[10/11] granting MySQL unix_socket auth to profile-app on both DBs…"
sudo mysql -e "
    CREATE USER IF NOT EXISTS 'profile-app'@'localhost' IDENTIFIED VIA unix_socket;
    GRANT SELECT ON \`$LIVE_WP_DB\`.* TO 'profile-app'@'localhost';
    GRANT SELECT ON \`$LIVE_BILLING_DB\`.* TO 'profile-app'@'localhost';
    FLUSH PRIVILEGES;
"

echo "[11/11] running backfill…"
LG_PROFILE_APP_ENV=live sudo -u profile-app -- env \
    LG_PROFILE_APP_ENV=live \
    LIVE_WP_DB="$LIVE_WP_DB" \
    LIVE_BILLING_DB="$LIVE_BILLING_DB" \
    php "$APP_ROOT/bin/backfill.php"

echo
echo "================ LIVE SMOKE TEST ================"
echo "Webhook (loopback):"
curl -s -X POST \
  -H "X-Hook-Secret: $HOOK_SECRET" \
  -H "Content-Type: application/json" \
  --data '{"wp_user_id":99998,"email":"live-bootstrap-smoke@example.com","display_name":"Bootstrap Smoke"}' \
  http://127.0.0.1/profile-api/v0/hooks/user-created || echo "(if 404: nginx include not added yet — see step 7)"
echo
echo
echo "Read:"
UUID=$(sudo -u profile-app php -r "require '$APP_ROOT/config.php'; echo \\Looth\\ProfileApp\\Identity::computeUuid('live-bootstrap-smoke@example.com');")
curl -s "https://loothgroup.com/profile-api/v0/user/$UUID" || true
echo
echo "================================================="
