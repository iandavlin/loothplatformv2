# Batch 02 — WP plugins, cron, secrets, route verification (read-only)

> Run on live (54.157.13.77). All commands are read-only.
> Paste output back in one block.

Three topics in this batch: (a) full nginx conf so we stop working from
a skeleton, (b) WP plugin/theme/mu-plugin/cron landscape, (c) verifying
the route-collision and CF-fronting questions BATCH-01 surfaced.

```bash
# --- (a) full nginx conf ---

# 11. Full loothgroup.com.conf so we can see the whole picture (137 lines)
sudo cat /etc/nginx/sites-available/loothgroup.com.conf

# --- (b) WP landscape ---
# All wp commands run from the live WP root. WP_PATH below assumes
# /var/www/html — adjust if `pwd && ls wp-config.php` says otherwise.
WP_PATH=/var/www/html

# 12. Active theme + parent
sudo -u looth-live wp --path=$WP_PATH option get template
sudo -u looth-live wp --path=$WP_PATH option get stylesheet

# 13. Active plugins (full list, status)
sudo -u looth-live wp --path=$WP_PATH plugin list --status=active --fields=name,version,status

# 14. mu-plugins on disk (wp mu-plugin list misses some; ls is authoritative)
sudo ls -la $WP_PATH/wp-content/mu-plugins/

# 15. Cron events (frequency + next-run for every WP cron hook)
sudo -u looth-live wp --path=$WP_PATH cron event list --fields=hook,next_run_relative,recurrence

# 16. OS-level cron jobs that may be triggering wp-cron.php
sudo crontab -l 2>/dev/null
sudo -u looth-live crontab -l 2>/dev/null
sudo ls /etc/cron.d/ /etc/cron.daily/ /etc/cron.hourly/ 2>/dev/null

# 17. code-snippets state — PROD-CUTOVER calls out #88/#89/#90 (looth1 lockouts)
#     that need to be disabled at cutover. Check current state.
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, name, active FROM wp_snippets WHERE id IN (88, 89, 90) OR name LIKE '%looth1%' OR name LIKE '%Log Out%'" 2>/dev/null \
  || echo "code-snippets plugin not installed or wp_snippets table missing"

# --- (c) secrets + verification ---

# 18. Existing secrets on live (the ones cutover will mirror)
sudo ls -la /etc/lg-archive-poc-secret /etc/lg-internal-secret 2>&1
# expected: archive-poc-secret exists; internal-secret does NOT (we'll create at cutover)

# 19. Is loothgroup.com behind Cloudflare? Determines whether we need
#     real_ip + CF-Connecting-IP config before adding any rate-limit zone.
dig +short loothgroup.com
dig +short www.loothgroup.com
curl -sI https://loothgroup.com/ | grep -iE '^(server|cf-|cache-control|x-)' | head

# 20. Route-collision verification — hit the strangler URLs from inside
#     the box and see what live currently returns. 404 = clear; 200 = WP
#     is rendering something there we'd be overwriting.
for path in /profile/edit /u/some-slug /p/some-slug /forums-poc/ /wp-json/looth-internal/v1/foo /directory/members; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://loothgroup.com$path")
  echo "$code  $path"
done

# 21. archive-poc on live — quick smoke (we believe it's deployed; confirm)
curl -sI https://loothgroup.com/archive-poc/ | head -1
sudo ls -la /srv/archive-poc/ 2>&1 | head -10

# 22. wp-config sanity — does it already define any LG_* constants? (so
#     we know what's already in scope before we add LG_INTERNAL_SECRET /
#     LG_PROFILE_APP_URL)
sudo grep -nE "^\s*define\s*\(\s*'LG[_A-Z]+'" $WP_PATH/wp-config.php 2>&1

# 23. Postgres on the live box? (open question #1 from inventory — where
#     does profile-app's DB live)
which psql 2>&1
sudo systemctl is-active postgresql 2>&1 || echo "no postgresql service"
sudo ss -tlnp 2>/dev/null | grep -E ':(5432|6432)' || echo "nothing listening on 5432/6432"
```

After paste-back:
- (a) full conf gets archived next to LIVE-INVENTORY.md
- (b) gives us the plugin/theme/cron picture; we cross-check against
      what PROD-CUTOVER.md assumes
- (c) confirms whether the strangler URL space is actually clear,
      whether CF is in front, and whether we need to bring postgres
      to the live box for profile-app

No writes. If anything looks dangerous, refuse — but everything here is
list/query/curl.
