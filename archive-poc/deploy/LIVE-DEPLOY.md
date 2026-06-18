# archive-poc — live deploy

Walk this top to bottom on the **live** box (54.157.13.77, `/var/www/html`). Each step is independently reversible.

**Prereqs:**
- root/sudo on live
- live WP table prefix is `wp_` (verify: `grep table_prefix /var/www/html/wp-config.php`)
- PHP 8.3 + php8.3-fpm running
- The active looth-live PHP-FPM pool socket name — check `ls /run/php/`. If it's not `php8.3-fpm-looth-live.sock`, **update the loopback _sync block in the nginx snippet** to match before reloading nginx.

---

## Step 1 — Pull + unzip on live

```bash
TOK='<ASK_IAN_FOR_LOOTHDEV_TOKEN>'   # the loothdev_auth token from dev's nginx conf
curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/archive-poc.zip \
  -o /tmp/archive-poc.zip
unzip -t /tmp/archive-poc.zip > /dev/null && echo "zip OK" || echo "zip CORRUPT, abort"

sudo mkdir -p /srv/archive-poc
sudo unzip -q /tmp/archive-poc.zip -d /srv/
# After unzip, the tree is /srv/archive-poc/{config.php, web/, bin/, deploy/, rows.json, demo-activity.json}
```

**Rollback:** `sudo rm -rf /srv/archive-poc`

## Step 2 — Create the archive-poc system user

```bash
sudo adduser --system --no-create-home --group archive-poc
sudo chown -R archive-poc:archive-poc /srv/archive-poc
# Give the looth-live FPM pool group-read on app dir + group-write on the SQLite (sync writes via that pool)
sudo usermod -aG archive-poc looth-live
sudo chmod g+rx /srv/archive-poc /srv/archive-poc/web /srv/archive-poc/api 2>/dev/null || true
```

**Rollback:** `sudo deluser archive-poc && sudo gpasswd -d looth-live archive-poc`

## Step 3 — Install the archive-poc PHP-FPM pool

```bash
sudo cp /srv/archive-poc/deploy/archive-poc-fpm-pool.conf /etc/php/8.3/fpm/pool.d/archive-poc.conf
sudo mkdir -p /var/log/php-fpm
sudo chown archive-poc:archive-poc /var/log/php-fpm/archive-poc-error.log 2>/dev/null || sudo touch /var/log/php-fpm/archive-poc-error.log
sudo systemctl reload php8.3-fpm
ls /run/php/php8.3-fpm-archive-poc.sock && echo "pool socket OK"
```

**Rollback:** `sudo rm /etc/php/8.3/fpm/pool.d/archive-poc.conf && sudo systemctl reload php8.3-fpm`

## Step 4 — Install the mu-plugin (live-edit sync)

```bash
sudo cp /srv/archive-poc/deploy/archive-poc-sync.mu-plugin.php \
        /var/www/html/wp-content/mu-plugins/archive-poc-sync.php
sudo chown looth-live:looth-live /var/www/html/wp-content/mu-plugins/archive-poc-sync.php
```

**Rollback:** `sudo rm /var/www/html/wp-content/mu-plugins/archive-poc-sync.php`

## Step 5 — Backfill the SQLite index from live's wp_posts

This bootstraps the index from live's existing content. Takes 1–3 min depending on corpus size.

```bash
cd /srv/archive-poc
sudo -u looth-live php bin/backfill.php
# Expected output: "indexed N items in M seconds"
ls -lh /srv/archive-poc/index.sqlite
# Group ownership matters — looth-live's pool needs to write via the sync endpoint
sudo chown archive-poc:archive-poc /srv/archive-poc/index.sqlite
sudo chmod 664 /srv/archive-poc/index.sqlite
```

**Rollback:** `sudo rm /srv/archive-poc/index.sqlite`

## Step 6 — Patch nginx

**Back up first:**
```bash
sudo cp /etc/nginx/sites-available/loothgroup.com.conf{,.bak.$(date +%Y%m%d-%H%M%S)}
```

Open `/etc/nginx/sites-available/loothgroup.com.conf` (or whatever your live site conf is named). Find the HTTPS server block (`listen 443`). Find the catch-all `location / { ... }` near the bottom. Paste the contents of `/srv/archive-poc/deploy/archive-poc.nginx-snippet.conf` **immediately above** the catch-all `location /`.

**Verify the looth-live FPM socket name in the snippet matches reality:**
```bash
ls /run/php/php8.3-fpm-*.sock
# If your live pool isn't `looth-live`, edit the snippet's `_sync` block before reload.
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**Rollback:** restore from the `.bak.*` copy you made and reload nginx.

## Step 7 — Verify

```bash
# Front-end page render — should be HTTP 200
curl -sI https://loothgroup.com/archive-poc/ | head -1

# API endpoint — should return JSON
curl -s https://loothgroup.com/archive-api/v0/search?limit=3 | head -c 400

# Sync endpoint — should ONLY accept loopback (deny external)
curl -sI https://loothgroup.com/archive-api/v0/_sync   # → 403 from external
curl -sI -H "Host: loothgroup.com" http://127.0.0.1/archive-api/v0/_sync  # → 405 (POST only) or 200
```

Then open `https://loothgroup.com/archive-poc/` in a browser. You should see real Looth content with real images.

## Step 8 — Activate `save_post` sync

The mu-plugin starts firing automatically once loaded. To verify:

```bash
# Edit any post in live wp-admin, hit Update. Then:
sudo tail -f /var/log/nginx/loothgroup.access.log | grep "_sync"
# You should see a 200 (or 4xx if something's off) within 1s of save.
```

## Patch deploy (incremental file pulls)

For small fixes (mu-plugin / archive.js / archive.css) after the initial deploy is in place. The patched files are staged on dev at `/var/www/dev/.well-known/`. Token below is the loothdev_auth gate token.

```bash
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'

# --- file pulls (comment out any you don't need this round) ---

# mu-plugin
sudo cp /var/www/html/wp-content/mu-plugins/archive-poc-sync.php \
        /var/www/html/wp-content/mu-plugins/archive-poc-sync.php.bak.$(date +%Y%m%d-%H%M%S)
sudo curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/archive-poc-sync.php.txt \
  -o /var/www/html/wp-content/mu-plugins/archive-poc-sync.php
sudo chown looth-live:looth-live /var/www/html/wp-content/mu-plugins/archive-poc-sync.php
php -l /var/www/html/wp-content/mu-plugins/archive-poc-sync.php

# front-end JS
sudo cp /srv/archive-poc/web/archive.js /srv/archive-poc/web/archive.js.bak.$(date +%Y%m%d-%H%M%S)
sudo curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/archive.js \
  -o /srv/archive-poc/web/archive.js
sudo chown archive-poc:archive-poc /srv/archive-poc/web/archive.js

# SSR template (index.php) — needed when activity card/SSR logic changed
sudo cp /srv/archive-poc/web/index.php /srv/archive-poc/web/index.php.bak.$(date +%Y%m%d-%H%M%S)
sudo curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/index.php.txt \
  -o /srv/archive-poc/web/index.php
sudo chown archive-poc:archive-poc /srv/archive-poc/web/index.php
php -l /srv/archive-poc/web/index.php

# stylesheet
sudo cp /srv/archive-poc/web/archive.css /srv/archive-poc/web/archive.css.bak.$(date +%Y%m%d-%H%M%S)
sudo curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/archive.css \
  -o /srv/archive-poc/web/archive.css
sudo chown archive-poc:archive-poc /srv/archive-poc/web/archive.css

# --- cache busts (scoped, do NOT use FLUSHALL — db2 is loothtool.com) ---
sudo -u looth-live wp --path=/var/www/html cache flush
sudo redis-cli -n 0 FLUSHDB        # loothgroup.com WP object cache (db0)
sudo systemctl reload php8.3-fpm   # drop opcache
```

Hard-reload `loothgroup.com/archive-poc/` (Ctrl-Shift-R) after, because nginx serves the JS/CSS with a long cache TTL.

**Redis DB layout on this box** (verify with `redis-cli INFO keyspace`):
- `db0` — loothgroup.com WP object cache (this is the only one we should flush for archive-poc deploys)
- `db2` — loothtool.com WP object cache (leave alone)

## Common gotchas

- **looth-live FPM socket name.** If yours is named differently (e.g. `php8.3-fpm.sock` default), update the `_sync` block in the nginx snippet BEFORE reloading.
- **wp_table_prefix.** If live's prefix isn't `wp_`, the backfill script will silently return 0 rows. Fix: the script uses `$wpdb` so it should respect prefix automatically — but verify the index.sqlite has rows in `content_item` after step 5.
- **open_basedir.** If your live PHP-FPM config sets `open_basedir`, archive-poc's PHP won't be able to read `/srv/archive-poc/`. Add `/srv/archive-poc:/tmp` to `open_basedir` in the new pool conf if you hit this.
- **R2 thumbs.** Live's image URLs point to R2. If the page renders with broken images, picsum fallback should kick in via `onerror`. Live R2 *should* work; verify with `curl -I` on one of the post image URLs.

## What you have after this

- `https://loothgroup.com/archive-poc/` serving the new front page (NOT yet flipped to `/`)
- Live save_post events syncing to the SQLite index within 1 second
- Old `/` and everything else on live unchanged

When ready to cut `/` over, that's a separate nginx config change — a single `location = /` block that aliases to the archive-poc handler. Don't do that yet; live with `/archive-poc/` for a few days first.
