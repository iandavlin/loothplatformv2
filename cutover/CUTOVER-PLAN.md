# Cutover Plan — v0.3 (blue-green)

> 2026-05-28. Model rewrite per Ian's ratification
> (`docs/reply-to-cutover-ian-decisions.md`). The cutover is now
> **blue-green via a fresh EC2 + DNS swing**, not in-place surgery on
> live (54.157.13.77).
>
> Pace: **relaxed** — no maintenance window. Build can take days. Old
> box (54.157.13.77) stays up through DNS propagation as natural
> fallback. Rollback for nearly every step is "point DNS back."
>
> Target pattern: **B-now / A-later / Stripe dormant** — strangler
> stack ships with Patreon adapter feeding `/whoami`; Stripe poller
> ships dormant (no creds → no `payment_source='stripe'` writes →
> LGPO operates unchanged → no users affected).
>
> **Driver for storage consolidation:** mobile imminent. Cross-schema
> discipline (§3i): schema = API; consumers go through owner endpoints.
>
> v0.3 changes vs v0.2:
> - Whole-plan rewrite from in-place to blue-green
> - User-visible comms: **removed** (Ian: skip; DNS swing is the only event)
> - Cloudflare cache purge: **removed at launch** (CF will miss
>   naturally on first request post-swing; can manually purge from
>   dashboard if needed)
> - Step 13 hard go/no-go gate stays — but it's now "before DNS swing"
>   instead of "before declaring cutover complete," because rollback
>   *after* DNS swing is just "swing it back"
> - P3 owner: **lg-shell** (not lg-layout-v2; renamed workstream per
>   CHAT-LINEAGE.md)
> - P9 ✅ Patreon adapter built + smoked

## Pre-cutover prereqs

Things that must be true before DNS can swing. Each owned by a project
lane.

| # | Prereq | Owner | Status |
|---|---|---|---|
| P1 | `/whoami` + batch `/users?uuids=` ship on dev (profile-app slice 3.5) | profile-app | ⏳ in flight |
| P2 | Patreon adapter — exposes `/wp-json/looth-internal/v1/user-context/{id}` reading downstream of LGPO (`payment_source` usermeta + `WP_User->roles`) | poller chat | ✅ shipped + smoked |
| P3 | Shared header partial included by lg-layout-v2, archive-poc, bb-mirror | **lg-shell** | ⏳ in flight |
| P4 | `LG_PROFILE_APP_URL` constant in poller's `PurgeNotifier` (currently hardcodes dev host) | poller chat | ⏳ |
| P5 | bb-mirror mu-plugin smoke + backfill rehearsed on dev | bb-mirror | ⏳ |
| P6 | archive-poc switches dev cookie-gating from `lg_tier` cookie to `/whoami`-backed for sensitive gates | archive-poc | ⏳ pending P1 |
| P7a | archive-poc port to postgres on dev (`discovery` schema, TIMESTAMPTZ upgrade, `websearch_to_tsquery`, **no pgloader** — re-run backfill against `wp_posts`) | archive-poc | ⏳ greenlit |
| P7b | bb-mirror port to postgres on dev (schema=`forums`, pgloader from SQLite, `reply`+`attachment` tables, `bb_mirror` role, `forum_read_state` v1) | bb-mirror | ⏳ 10-step plan drafted |
| P7c | `edit_archive_poc` cap mu-plugin (registers cap; grants to administrator) | archive-poc | ⏳ |
| P8 | Dev smoke of poller in dormant mode (no Stripe creds, clean boot, no nulls) | poller chat | ⏳ |
| P9 | Patreon adapter built + tested against representative users | poller chat | ✅ shipped (BATCH-04B coexistence analysis ratified) |
| P10 | File/key ownership posture on new box — webroot `looth-live`, `/srv` apps to per-app system users, **every secret/key (esp. `/etc/looth/jwt-private.pem`) group-readable by the consuming FPM pool**. Full audit + the dev legacy-ownership picture (why a blanket chown is wrong on dev): `docs/OWNERSHIP-CUTOVER-AUDIT.md` | **ubuntu** (sysadmin) | ⏳ audited 2026-06-02 |

When P1, P3, P4, P5, P6, P7a–c, P8 are ✅, the new EC2 build can run
end-to-end.

## Blue-green cutover sequence (live)

Numbered for execution order. Each step's rollback assumes DNS hasn't
swung yet (step 10) — for any step before that, "stop and restart on
a fresh box" is also valid. After step 10, rollback is "swing DNS
back" (step 10R).

Old box (54.157.13.77) is **read-only / no-touch** through this entire
sequence, except for the DB export at step 7 and the eventual
decommission at step 12. Old box stays running until the soak window
closes — it's the natural rollback target.

### Step 1 — Provision new EC2

✅ Postgres on-box ratified. EC2 sized to match (or exceed) old-box
specs. Same Ubuntu LTS as dev (`uname -r` from claude.loothgroup.com).

**WRITE COMMAND — review before running** (this is Ian's AWS console
or `aws ec2 run-instances`; commands shown for reference):
```bash
# Launch instance — match dev box's instance type unless Ian wants larger
aws ec2 run-instances \
  --image-id <ubuntu-22.04-ami> \
  --instance-type <match-or-exceed-old> \
  --key-name <ian's key> \
  --security-group-ids <sg-with-22,80,443> \
  --tag-specifications 'ResourceType=instance,Tags=[{Key=Name,Value=loothgroup-newbox}]'
```
**Rollback:** terminate the instance. No production impact.
**Risk:** none — instance is dark until DNS swings.
**Go/no-go:** SSH connectable as ubuntu; sudo works; public IP captured.

### Step 2 — Bake new box to match dev's stack

Install the canonical stack: nginx 1.24+, php8.3 + php8.3-fpm +
php8.3-pgsql, postgres-16, systemd units, R2 / Cloudflare credentials,
let's-encrypt certbot, redis (for object cache), msmtp/mailpit if
desired for diagnostic mail.

**WRITE COMMAND — review before running**
```bash
sudo apt-get update
sudo apt-get install -y \
  nginx \
  php8.3 php8.3-fpm php8.3-pgsql php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-intl php8.3-imagick php8.3-redis \
  postgresql-16 \
  redis-server \
  certbot python3-certbot-nginx \
  unzip rclone
sudo systemctl enable --now nginx postgresql redis-server php8.3-fpm
```
**Rollback:** terminate instance, restart step 1.
**Risk:** low — additive on a fresh box. The `php -m | grep pgsql` check at end is the gate.
**Go/no-go:** all services active; `php -m` shows `pgsql`, `redis`, `mysqli`, `gd`, `imagick`; `nginx -v` ≥ 1.24.

### Step 3 — Postgres database + schemas + per-app roles + secrets

Per coord §3i canonical pattern. Per-app role owns per-app schema;
secret file at `/etc/lg-<app>-db` mode 0640; Unix-socket DSN exported
via FPM pool env.

**WRITE COMMAND — review before running**
```bash
# (3a) Database + three schemas
sudo -u postgres psql -c "CREATE DATABASE looth"
sudo -u postgres psql -d looth -c "CREATE SCHEMA profile_app; CREATE SCHEMA forums; CREATE SCHEMA discovery;"

# (3b) Roles, one per app, owning its own schema. Each app's system
# user must exist on the box before this (created at app-deploy time).
for spec in "profile_app:profile_app:/etc/lg-profile-app-db:profile-app" \
            "discovery:archive_poc:/etc/lg-archive-poc-db:archive-poc" \
            "forums:bb_mirror:/etc/lg-bb-mirror-db:bb-mirror"; do
  IFS=: read schema role secret group <<<"$spec"
  pw=$(openssl rand -hex 32)
  sudo -u postgres psql -d looth -c "CREATE ROLE $role LOGIN PASSWORD '$pw'"
  sudo -u postgres psql -d looth -c "ALTER SCHEMA $schema OWNER TO $role"
  sudo bash -c "echo $pw > $secret"
  sudo chown root:$group $secret
  sudo chmod 0640 $secret
done

# (3c) Verify Unix-socket connectivity from each app user
sudo -u archive-poc psql "host=/var/run/postgresql dbname=looth user=archive_poc" -c '\dn'
sudo -u profile-app psql "host=/var/run/postgresql dbname=looth user=profile_app" -c '\dn'
sudo -u bb-mirror psql "host=/var/run/postgresql dbname=looth user=bb_mirror" -c '\dn'
```
**Rollback:** `dropdb looth; drop roles; rm secret files`. New-box only — old box not touched.
**Risk:** low. Secret-file mode is the most-likely-bug; the `ls -l /etc/lg-*-db` verify catches it.
**Go/no-go:** each role can connect via Unix socket; each owns its target schema; secret files are root:<app> 0640.

### Step 4 — Deploy strangler apps on new box

Each app deployed using the same zip-from-dev / unzip-on-new-box
pattern that archive-poc and lg-layout-v2 already follow. Order matters
only for dependency reasons (apps need their FPM pools + nginx snippets
before smoke-testing).

**Substeps (each independently rollbackable by removing its dir + pool conf):**

- (4a) **profile-app**: system user, FPM pool, app dir `/srv/profile-app/`, apply DB migrations to `profile_app` schema
- (4b) **archive-poc**: system user, FPM pool, app dir `/srv/archive-poc/`, apply postgres DDL to `discovery` schema (NO pgloader; data fills from step 7 backfill)
- (4c) **bb-mirror**: system user, FPM pool, app dir `/srv/bb-mirror/`, apply postgres DDL to `forums` schema (data filled at step 7 via pgloader from old-box SQLite)
- (4d) **lg-shell shared header partial**: deployed where lg-layout-v2 / archive-poc / bb-mirror include it from
- (4e) **lg-layout-v2 plugin**: drop into WP plugins dir at step 7 (after wp-content sync)
- (4f) **lg-patreon-stripe-poller (dormant) + Patreon adapter**: dropped at step 7 with the rest of WP plugins; activated in step 8

**Rollback:** `rm -rf /srv/<app>/; rm /etc/php/8.3/fpm/pool.d/<app>.conf; reload php8.3-fpm`. Each per app. New-box only.
**Risk:** low — fresh box, additive. Same gotcha as before: **strip every `if ($loothdev_is_authorized != 1)` cookie-gate guard** from nginx snippets before copying to new box. Grep deployed snippets for `loothdev_is_authorized` post-copy as a gate.
**Go/no-go:** each FPM pool socket exists at `/run/php/php8.3-fpm-<app>.sock`; `php -l` clean on each app's entry point.

### Step 5 — Provision `/etc/lg-internal-secret`

```bash
sudo bash -c "openssl rand -hex 32 > /etc/lg-internal-secret"
sudo chown root:www-data /etc/lg-internal-secret
sudo chmod 0640 /etc/lg-internal-secret
```
**Rollback:** `rm /etc/lg-internal-secret`. New-box only.
**Risk:** none.

### Step 6 — nginx config on new box

Install the canonical `loothgroup.com.conf` + snippets pattern:

- (6a) Main conf adapted from old box (`/etc/nginx/sites-available/loothgroup.com.conf` from 54.157.13.77, captured at BATCH-01) — preserves existing archive-poc routes (will be re-keyed to /srv/archive-poc still works)
- (6b) Create `/etc/nginx/snippets/` and drop `strangler-profile-app.conf`, `strangler-archive-poc.conf`, `strangler-bb-mirror.conf` from dev — **stripping every `if ($loothdev_is_authorized != 1)` cookie-gate guard**
- (6c) Add `^~ /wp-json/looth-internal/` exempt route to main conf
- (6d) Include the strangler snippets above the WP catch-all
- (6e) Get Let's Encrypt cert (DNS-01 challenge — can be done before DNS swing using the API)
- (6f) `sudo nginx -t && sudo systemctl reload nginx`

**Rollback:** restore nginx to default conf. New-box only.
**Risk:** medium — cookie-gate strip is the load-bearing edit; grep verify. SSL cert must be valid before DNS swing or browsers will reject — verify with `openssl s_client` to the new IP using Host header.
**Go/no-go:** `nginx -t` clean; SSL cert present + valid; strangler snippets have zero `loothdev_is_authorized` matches.

### Step 7 — DB import + WP filesystem migration

The data-move from old box → new box. Done while old box is still
serving production (read-side import only).

**WRITE COMMAND — review before running** (snapshot on old box, then
transfer + restore on new box):
```bash
# (7a) On old box: mysqldump (run during a quiet period — see BATCH-05B
# data: Sun 23:00–Mon 03:00 ET is the canonical low-traffic window).
# Includes a brief --single-transaction so we don't lock writes.
mysqldump --single-transaction --routines --triggers wp_loothgroup | \
  gzip | rclone rcat r2:loothgroup-backups/cutover/wp_loothgroup-$(date +%s).sql.gz

# (7b) Transfer wp-content uploads to new box (largest single artifact)
sudo rsync -avzP --exclude='cache/*' /var/www/html/wp-content/uploads/ \
  ubuntu@<new-box-ip>:/srv/wp-content-uploads/

# (7c) On new box: restore + chown
sudo mysql wp_loothgroup < <(curl -sL r2:.../wp_loothgroup-*.sql.gz | gunzip)
sudo cp -r /srv/wp-content-uploads /var/www/html/wp-content/uploads
sudo chown -R looth-live:looth-live /var/www/html/wp-content/

# (7c-bis) OWNERSHIP/KEY POSTURE (P10, ubuntu) — blue-green means the dev box's
#   legacy team-user ownership (ian/buck owning thousands of webroot files from the
#   live-era per-user gating) does NOT propagate: only uploads + DB carry over, code
#   is re-dropped fresh in 7d. So the new box starts clean — webroot looth-live (7c
#   above), /srv apps owned by their per-app system users (Step 4). The one thing to
#   GET RIGHT, because it silently broke for a week on dev (2026-05-25→06-02): every
#   secret/key must be group-readable by the FPM pool that consumes it.
#     chown root:<fpm-pool> /etc/looth/jwt-private.pem && chmod 640 ...
#   (dev bug: key was root:profile-app 640, empty group; looth-dev FPM couldn't read
#    it → looth_id mint threw + was swallowed → zero cookies for ALL members. Verify
#    each key with `sudo -u <pool> test -r <keyfile>`.) Full picture: docs/OWNERSHIP-CUTOVER-AUDIT.md

# (7d) Drop WP plugins + theme into new-box wp-content (zip-from-dev pattern)
# Includes: lg-layout-v2 (sync header/constant version!), lg-patreon-stripe-poller,
# buddyboss-theme + child, all lg-* + buddyboss + fluent* + etc per BATCH-02 list

# (7e) pgloader: bb-mirror SQLite → forums schema (data is authoritative on old box's bb-mirror)
# (Skipped on the new box if bb-mirror's old-box install doesn't exist — bb-mirror is live-on-dev only;
# at cutover the new box gets a fresh bb-mirror install from dev, then runs backfill against new-box wp_posts)

# (7f) archive-poc: NO pgloader. Re-run backfill against new-box wp_posts.
sudo -u looth-live wp --path=/var/www/html eval-file /srv/archive-poc/bin/backfill.php

# (7g) Run profile-app's slice 4 migration on new box's data
sudo -u profile-app php /srv/profile-app/bin/migrate-from-xprofile.php

# (7h) Bulk-set location defaults for existing members (Ian ruled 2026-06-01)
# New members already default to members-visible/city (schema default changed pre-cut).
# Existing members: set location_visibility='members' + location_pin_precision='city'
# for any row where both are still at the old 'private'/'exact' defaults (i.e. never
# explicitly set by the user). Members who touched their own dial are left alone.
sudo -u profile-app psql -d profile_app -c "
  UPDATE users
     SET location_visibility    = 'members',
         location_pin_precision = 'city'
   WHERE location_visibility    = 'private'
     AND location_pin_precision = 'exact';
"
# Verify: should update the bulk of existing members; anyone who self-configured is untouched.
```

**Rollback before DNS swing:** drop the new-box DB, re-run from a fresh export. Old box not touched.
**Risk:** medium — biggest step. The MySQL export is the slowest part (size = `wp_loothgroup` DB size on live). pre-flight by timing a dummy mysqldump against the production DB during a quiet window so we know the actual duration. wp-content/uploads can also be large; rsync resumes if interrupted.
**Go/no-go:**
- Row counts match between old-box and new-box `wp_loothgroup.*`
- `discovery.content_item` populated by backfill (matches `wp_posts` count for indexed types)
- `forums.*` populated by bb-mirror backfill
- `profile_app.users` row count = pre-migration `wp_users` count (with the slice-4 walk script's assertions passing)
- A sample of 20 users across tiers renders correctly via `curl -H "Host: loothgroup.com" http://<new-ip>/u/<slug>` (using a hosts-file override on the smoke-test machine)

### Step 8 — Plugin activation + WP options sync on new box

```bash
# Activate the dormant poller + Patreon adapter
sudo -u looth-live wp --path=/var/www/html plugin activate lg-patreon-stripe-poller

# Verify the adapter responds correctly
curl -sH "X-LG-Internal-Auth: $(cat /etc/lg-internal-secret)" \
  "http://localhost/wp-json/looth-internal/v1/user-context/<known_paid_user_id>" | jq
```

**Rollback before DNS swing:** `wp plugin deactivate ...`. Bonus: `lgpo_sync_changelog` revertable batches (3-day TTL) for any wrong Patreon-side moves.
**Risk:** medium — activation triggers `register_activation_hook`. LGPO's existing `payment_source='stripe'` skip-guard means the dormant-Stripe ship is mechanically safe (no users have that flag → guard no-ops). Watch error log post-activation.
**Go/no-go:** `/wp-json/looth-internal/v1/user-context/<uid>` returns contract shape for a known paid user; `provenance` matches expected; sample 3-5 users across tiers (looth1/2/3/4 + admin).

### Step 9 — Final smoke on new box via hosts-file override

Before DNS, prove the box works end-to-end as if it were `loothgroup.com`.

**Smoke laptop / smoke server**: `/etc/hosts` add `<new-box-ip> loothgroup.com www.loothgroup.com`, then run the full Step 13 cutover-completion-gate checklist against `https://loothgroup.com/...` resolving locally to the new box.

**Six checks** (hard yes/no gate; **default-on-uncertainty = do NOT swing DNS**):

1. Anonymous sees public content
2. looth1 user sees same as anonymous + can comment
3. looth2/3 see paid tier content
4. looth4 sees pro content + admin tools where applicable
5. `/whoami` returns matching shape from anonymous, JWT, and cookie auth
6. Cache-purge fires when role flips (add/remove a role via wp-admin and tail PHP error log for `PurgeNotifier` POST)

Plus:
- 20 spot-checks across paying tiers on `/u/<slug>`, `/p/<slug>`, `/directory/members`
- archive-poc renders identically
- bb-mirror forum threads render
- profile-app `/profile/edit` loads for an authed user
- BB hijack 302s (`/members/<slug>` → `/u/<slug>`) work

**Pass** → step 10 (DNS swing). **Fail** → fix on new box; do not swing.

### Step 10 — DNS swing

Pre-step: lower DNS TTL for `loothgroup.com` + `www.loothgroup.com` to
the minimum that the registrar allows (60s ideal, 300s typical) at
least one full TTL window *before* this step. That way the swing
propagates quickly.

**WRITE COMMAND — Ian executes via the DNS provider's UI or API**

Point `loothgroup.com` (A) + `www.loothgroup.com` (A or CNAME) at the
new box's public IP. Leave Cloudflare proxy enabled (assuming it was
before).

**Rollback (10R):** swing DNS back to 54.157.13.77. Propagation matches the TTL you set. Old box has been read-only during cutover so its state is intact.
**Risk:** this is the user-visible moment. Old box continues serving traffic during propagation. Some users see new box, some see old box for up to TTL seconds. Both should work — old box is unchanged, new box is verified.
**Go/no-go:** new box's nginx access log shows real loothgroup.com traffic landing; old box's traffic gradually drops to zero over TTL period.

### Step 11 — Soak

Window: at least 7 days before considering decommission. Watch:
- New box error rates / latency
- Patreon sync runs cleanly (cron fires at next interval)
- Forum write round-trip via BB REST works (write a test reply, observe sync to bb-mirror's `forums` schema)
- `lgpo_sync_changelog` shows expected role transitions, no surprises
- profile-app editor works end-to-end for a test user

If any of the above looks wrong → rollback per 10R while still inside the soak window.

### Step 12 — Decommission old box

After soak passes. Stop EC2 instance 54.157.13.77; preserve EBS volume for a week as belt-and-suspenders backup; terminate after that. Update memory `project_dev_migration_20260515.md` to reflect the new live-box arrangement.

---

## Pending decisions (for plan finalization)

- ✅ Postgres on-box (ratified)
- ✅ Cutover approach: blue-green (ratified)
- ✅ User comms: skip (ratified)
- ✅ CF cache purge: skip at launch (ratified)
- 🔒 Cutover window timing — preference for the mysqldump in step 7a's quiet period. BATCH-05B data points to **Sun 23:00 → Mon 03:00 ET**. Ian to confirm or pick alternate.
- ⏳ DNS TTL pre-lower — when is "long enough before step 10" — recommend 24h ahead of the planned DNS swing
- ⏳ Old-box decommission timing — at least 7 days post-swing; Ian's call on final teardown

## What's collapsed in v0.3 (vs v0.2)

- Per-step rollback complexity collapsed — most are "stop building" or "swing DNS back"
- User-visible comms section: removed
- Cloudflare cache-bust list: removed
- Snippet #90 / code-snippet hygiene: no longer cutover-blocking — new box starts clean. (Old box's snippets stay running until decommission; harmless.)
- In-place "maintenance window pressure": eliminated. Build can take days.
- `LG_PROFILE_APP_URL` hardcoded-dev-host fix (was P4) — still needed but trivial on the new box; just ensure it's set to `https://loothgroup.com` at deploy time.

## What carries forward (still load-bearing)

- Cookie-gate strip on every dev nginx snippet before copying to new box
- pdo_pgsql install (step 2)
- canonical per-app role + secret-file pattern (step 3)
- `^~ /wp-json/looth-internal/` exempt route in main conf (step 6c)
- archive-poc skip-pgloader / re-run-backfill model (step 7f)
- bb-mirror pgloader from dev SQLite (step 7e — bb-mirror lives on dev today)
- Step 13 6-check gate, now gating DNS-swing not "cutover-complete declaration"

## File ownership → generic www-data (Ian, 2026-05-31)
At cut, collapse all strangler-app file ownership to generic `www-data` on loothgroup.com (kill chown reacharounds). DEPENDENCY: apps peer-auth to postgres via distinct OS users → switch to password-auth DSNs (LG_*_DSN already supports) or one role before flipping pool users. Also drops per-app pool isolation (acceptable single-host).
