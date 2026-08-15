# Live paste — 2026-08-15 (~165 commits)

**Everything here is Ian's to run, in this order, on the live box.**
Assembled from live's actual state (checkout at 33daa5a, docroot
`/var/www/dev`, guitardle table lives in the `looth` Postgres DB — all
verified read-only today).

**Arrives switched ON (each passed Ian's look on dev2):** Guitardle fairness
+ rules screen · featured members (tickbox, dash, front-page card) · the
dark-mode fixes (all flag copies agree, alarm-guarded) · hub category doors.
**Arrives switched OFF (proven inert):** compose door + Loothprint modal,
v2 blocks, street-address clamp, admin offline-shell scope, Guitardle
server-play + day-puzzle (two-stage, deliberately later), stripe fences.

## 1. Database step FIRST (Guitardle claims — pull would race it)

    sudo -u ubuntu git -C /home/ubuntu/loothplatformv2-clean fetch origin
    sudo -u ubuntu git -C /home/ubuntu/loothplatformv2-clean show origin/main:archive-poc/sql/guitardle-claim.pg.sql > /tmp/guitardle-claim.sql
    sudo -u postgres psql -d looth -f /tmp/guitardle-claim.sql

Safe to re-run (adds two columns, relaxes two rules; deletes nothing).

## 2. The pull

    lg-deploy

## 3. Same window: the one new plugin link + reload

    sudo ln -s /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-frontend-compose.php /var/www/dev/wp-content/mu-plugins/lg-frontend-compose.php
    sudo nginx -t && sudo systemctl reload nginx

(The reload matters: two routing files changed. A pulled conf is not
deployed until nginx reloads.)

## 4. One-shot heal for the stale June discussion edit

    sudo -u www-data wp --path=/var/www/dev eval 'bb_mirror_sync_dispatch("reply", 71432, "upsert");'

(Use whichever user normally runs wp on live if not www-data.)

## 5. Smoke, on the box (never a plain public curl — Cloudflare 403s it)

    curl -sk --resolve loothgroup.com:443:127.0.0.1 https://loothgroup.com/ -o /dev/null -w '%{http_code}\n'
    curl -sk --resolve loothgroup.com:443:127.0.0.1 https://loothgroup.com/archive-poc/guitardle/index.html -o /dev/null -w '%{http_code}\n'

Both should say 200. Then the human checks: play a Guitardle round (one
claim, resume works), glance at sign-in in dark mode, open the featured dash.

## Rollback, if Guitardle misbehaves (two steps, order matters)

1. Set `LG_GUITARDLE_DAILY_CLAIM` false in `archive-poc/api/v0/_flags.php`
2. `sudo -u postgres psql -d looth -c "DELETE FROM discovery.guitardle_results WHERE moves IS NULL;"`

Step 2 only removes never-finished attempts — it cannot destroy a recorded
score (gate 37 asserts this).
