# profile-app — slice-zero live deploy

Run this on the **live** box (loothgroup.com / 54.157.13.77), as a sudoer.
The bootstrap is idempotent — re-run if a step fails.

## One-shot

```bash
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'
curl -fSL --cookie "loothdev_auth=$TOK" \
  https://dev.loothgroup.com/.well-known/profile-app-live-bootstrap.sh \
  -o /tmp/profile-app-live-bootstrap.sh
bash /tmp/profile-app-live-bootstrap.sh
```

The script will pause at step 7 and PRINT the one nginx-include line you
need to paste into the `loothgroup.com` SSL server block:

```
include snippets/profile-app.conf;
```

After pasting it inside the server { … } block (above the catch-all
`location /`), run:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

…then re-run the bootstrap. Earlier steps short-circuit; only the smoke
tests at the end actually re-execute.

## What it does (full list inside the script)

1. apt-installs `postgresql postgresql-contrib php8.3-pgsql composer`
2. unzips staged source → `/srv/profile-app/`
3. creates `profile-app` system user + Postgres role/DB (peer auth)
4. applies `sql/0001_init.sql`
5. composer-installs `ramsey/uuid`
6. installs FPM pool → `/etc/php/8.3/fpm/pool.d/profile-app.conf`
7. installs nginx snippet → `/etc/nginx/snippets/profile-app.conf`
   and prompts to add `include snippets/profile-app.conf;` to vhost
8. provisions `/etc/lg-profile-app-secret` + sets `wp_options.profile_hook_secret`
9. installs mu-plugin → `/var/www/html/wp-content/mu-plugins/profile-sync.php`
10. grants `profile-app`@localhost MySQL unix_socket auth on `looth_live` + `lg_membership`
11. runs backfill and prints summary

## Rollback (each step is independently reversible)

```bash
# Plugin off
sudo rm /var/www/html/wp-content/mu-plugins/profile-sync.php

# Nginx
sudo rm /etc/nginx/snippets/profile-app.conf
# also remove `include snippets/profile-app.conf;` from loothgroup.com.conf
sudo nginx -t && sudo systemctl reload nginx

# Pool
sudo rm /etc/php/8.3/fpm/pool.d/profile-app.conf
sudo systemctl reload php8.3-fpm

# Code + data (nukes the database!)
sudo rm -rf /srv/profile-app
sudo -u postgres psql -c 'DROP DATABASE profile_app;'
sudo -u postgres psql -c 'DROP USER "profile-app";'
sudo userdel profile-app
sudo mysql -e "DROP USER 'profile-app'@'localhost';"
sudo rm /etc/lg-profile-app-secret
```
