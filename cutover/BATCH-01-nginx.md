# Batch 01 — nginx on live (read-only)

> Run on live (54.157.13.77). All commands are read-only. Paste output back.
> Goal: see the actual nginx layout on live so we can plan how strangler
> snippets land without colliding with existing routes.

```bash
# 1. List the live sites-available + sites-enabled inventory
sudo ls -la /etc/nginx/sites-available/ /etc/nginx/sites-enabled/

# 2. Confirm whether the snippets/ extraction pattern exists on live yet
sudo ls -la /etc/nginx/snippets/ 2>/dev/null || echo "no snippets/ dir"

# 3. Show the loothgroup.com server-block structure (locations + includes,
#    not full file — we just want the routing skeleton)
sudo grep -nE '^\s*(server_name|listen|location|include|set \$|if \(|return )' \
  /etc/nginx/sites-available/loothgroup.com.conf 2>/dev/null \
  || sudo find /etc/nginx/sites-available/ -name 'loothgroup*' -print -exec \
       grep -nE '^\s*(server_name|listen|location|include|set \$|if \(|return )' {} \;

# 4. Same skim for loothtool.com (we want to confirm it's a separate site
#    with no strangler footprint to worry about)
sudo grep -nE '^\s*(server_name|listen|location|include|return )' \
  /etc/nginx/sites-available/loothtool.com.conf 2>/dev/null | head -40 \
  || echo "no loothtool conf at that name — find it:"; \
  sudo find /etc/nginx/sites-available/ -name 'loothtool*' -print

# 5. List FPM pools running on live (we want to see what sockets exist
#    so we can size up how the existing archive-poc + looth-live pools
#    are laid out; also confirms whether anything resembling
#    bb-mirror or profile-app already exists)
sudo ls -la /run/php/ /etc/php/8.3/fpm/pool.d/

# 6. nginx config test (proves the current config is valid before any
#    cutover edits — also catches "what's actually loaded vs what's on disk")
sudo nginx -t

# 7. Total line count for the main conf (just so we know roughly what
#    we're dealing with when we propose edits)
sudo wc -l /etc/nginx/sites-available/loothgroup.com.conf

# 8. Look for any existing reference to the strangler URLs we plan to
#    introduce — collision check
sudo grep -nE '/(profile/edit|u/|p/|profile-api|whoami|forums-poc|bb-mirror|looth-internal)' \
  /etc/nginx/sites-available/loothgroup.com.conf 2>/dev/null

# 9. Cloudflare or other upstream signal: is there a real_ip module +
#    set_real_ip_from for CF in this conf? (drives where rate-limit
#    keys come from + which IP gets logged)
sudo grep -nE '(real_ip|set_real_ip_from|CF-Connecting-IP|http_cf)' \
  /etc/nginx/nginx.conf /etc/nginx/sites-available/loothgroup.com.conf 2>/dev/null

# 10. Show the version we're running (rules out obscure
#     directive-not-supported surprises when we hand snippets over)
sudo nginx -v
```

After paste-back: cutover folds findings into `LIVE-INVENTORY.md`,
proposes next batch (probably WP plugins / mu-plugins + active theme).

No write operations in this batch.
