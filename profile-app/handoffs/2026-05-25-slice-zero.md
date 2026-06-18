# profile-app — Session Handoff (2026-05-25, slice zero)

## What this project is

The new **member profile / directory** service. Designed to live OUTSIDE WordPress
and eventually back a native app. WP remains the legacy auth + write surface;
profile-app is a read/write service that mirrors a slim user shell.

Same architectural pattern as **archive-poc**: own FPM pool, own datastore, own
deploy story, mu-plugin bridge from WP.

**Status:** slice zero **complete on dev**. Identity backbone is curl-
testable; all dev validation counts recorded below. **Live deploy is
deferred** until slice one (editor + auth) is built and tested — no point
exposing a read-only identity API to live until there's something to hit.

**Code lives:**
- Source: `/home/ubuntu/projects/profile-app/` (this dir, on dev)
- DB: Postgres (`profile_app` database, owned by `profile-app` role, peer auth)
- Live deploy artifacts ARE staged (zip + bootstrap on `.well-known`,
  `deploy/LIVE-DEPLOY.md` written) but **deliberately not executed**. They
  may be stale by the time live happens; treat as a starting point, not a
  drop-in.
- Mu-plugin source: `/home/ubuntu/projects/profile-app/deploy/profile-sync.mu-plugin.php`
- Mu-plugin installed (dev): `/var/www/dev/wp-content/mu-plugins/profile-sync.php`

**Sockets / pools:**
- profile-app FPM pool: `/run/php/php8.3-fpm-profile-app.sock`
- pool conf: `/etc/php/8.3/fpm/pool.d/profile-app.conf`

**Secret:**
- `/etc/lg-profile-app-secret` (mode 640, root:profile-app)
- Same value lives in `wp_options.profile_hook_secret` on dev WP

## URL surface (dev)

| Path | Method | Auth | Pool |
|---|---|---|---|
| `/profile-api/v0/hooks/user-created` | POST | `X-Hook-Secret` header, loopback-only | profile-app |
| `/profile-api/v0/user/<uuid>`         | GET  | cookie-gate (`loothdev_auth`)         | profile-app |

Webhook payload: `{wp_user_id, email, display_name}`.

Read response: `{uuid, display_name, slug, avatar_url, emails:{primary,billing,contact}, location:{...}, tier, member_since, created_at}`.
Slug fallback is `u/<users.id>` (internal id, not wp_user_id).

## Key files

| Path | Role |
|---|---|
| `config.php`                    | env detection, constants, autoload. Defines `LOOTH_IDENTITY_NAMESPACE` (DO NOT CHANGE). |
| `src/Identity.php`              | `Identity::normalizeEmail()` + `Identity::computeUuid()` (UUIDv5 over normalized email). |
| `src/Db.php`                    | PDO singleton for Postgres. |
| `sql/0001_init.sql`             | `users`, `email_aliases`, `wp_user_bridge` tables. |
| `api/v0/_bootstrap.php`         | shared `profile_app_json()` + autoload include. |
| `api/v0/user-created.php`       | webhook target — upserts users / bridge / aliases. |
| `api/v0/user.php`               | public read by uuid. |
| `bin/test-identity.php`         | tiny test: case/whitespace email variants → same uuid; namespace stability assertion. |
| `bin/backfill.php`              | wp_users + wp_bp_xprofile_data(field_id=96) + lg_membership.customers → Postgres. |
| `deploy/profile-app-fpm-pool.conf`   | FPM pool template (installed at /etc/php/8.3/fpm/pool.d/profile-app.conf). |
| `deploy/profile-app.nginx-snippet.conf` | nginx snippet template (installed inline in dev.loothgroup.com.conf). |
| `deploy/profile-sync.mu-plugin.php`  | mu-plugin source (installed at /var/www/dev/wp-content/mu-plugins/profile-sync.php). |

## The identity contract (READ THIS)

```
uuid = UUIDv5( LOOTH_IDENTITY_NAMESPACE, lower(trim(email)) )
```

- **`LOOTH_IDENTITY_NAMESPACE` = `eaef23f7-9bc9-4a95-ac49-ffff632e6646`** (frozen forever).
- The namespace is the bootstrap: same email → same UUID across services without
  coordination. Once a `users` row exists, the UUID is **frozen** — it does NOT
  rotate when email changes. Email-alias rotation goes through `email_aliases`.
- lg-stripe-billing was supposed to share this namespace, but its existing
  `customers.uuid` values were generated as v4 (random). **They do not match
  the v5 we'd compute today.** See the "what surprised me" note below.

## Backfill results

### Dev (2026-05-25)

```
  wp_total             1694    (WP users with non-empty email)
  seeded               1694
  failed               0
  with_location        663     (had a non-empty wp_bp_xprofile_data field_id=96)
  reconciled_email     68      (of 70 lg_membership.customers — matched by email)
  reconciled_uuid      0       (NO lg-stripe customer has a v5(email) uuid)
```

### Live

Deferred. Will run when slice one ships.

- `wp_users` total is 1809; 115 rows have empty `user_email` and are skipped.
- 704 xprofile field_id=96 rows exist, but only 663 are attached to a real
  wp_user (41 orphan xprofile rows — `user_id` pointing to a deleted user).
- The 2 unreconciled lg-stripe customers are bad data:
  - id=42 `ian.davlin@gamm`  (truncated email — fix in lg-stripe)
  - id=39 `smoke@example.com` (test row, no WP user)

## What surprised me (the 5-liner)

1. **lg-stripe-billing's `customers.uuid` is v4, not v5(email).** The "automatic
   reconciliation by uuid" the design assumed *cannot happen today*. Reconciliation
   here was by email match (68/70). To realize the cross-service contract,
   lg-stripe-billing needs a one-time migration that rewrites `customers.uuid`
   to `UUIDv5(LOOTH_IDENTITY_NAMESPACE, lower(trim(email)))` and re-keys any
   external references. Worth scheduling before slice one.
2. **xprofile field is named "Your Location" (id=96, trailing space in label).**
   704 rows total — but 41 of those point to deleted wp_users, so the real
   "users with a location" count is 663. The strings look uniformly Google-Places
   formatted ("123 Foo St, City, ST 12345, USA"); geocoding will be clean.
3. **MySQL collations between databases differ.** `looth_dev` uses
   `utf8mb4_unicode_ci`; `lg_membership` uses `utf8mb4_unicode_520_ci`. Joining
   across DBs by email needs an explicit `COLLATE` or the planner errors out.
   The backfill sidesteps this by loading both into PHP arrays and comparing
   strings; any future direct cross-DB join will hit it.
4. **Some wp_users have empty `user_email`** (115 of 1809 — ~6%). Could be deleted
   ghost accounts, bbPress auto-import remnants, or legacy migrations. Skipping
   them is correct, but flag this for whoever does the next directory scrub.
5. **`pg_user "ubuntu"` does not exist.** I created the `profile-app` role and
   rely on peer auth. CLI access from this account needs `sudo -u profile-app
   psql -d profile_app`. Worth knowing before someone tries `psql` and gets
   "role ubuntu does not exist".

## Smoke tests (rerun anytime)

```bash
# Identity test
cd /home/ubuntu/projects/profile-app && php bin/test-identity.php

# Webhook (loopback only)
SECRET=$(sudo cat /etc/lg-profile-app-secret)
curl -sk -X POST \
  -H "Host: dev.loothgroup.com" \
  -H "X-Hook-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  --data '{"wp_user_id":99999,"email":"smoke@example.com","display_name":"Smoke"}' \
  https://127.0.0.1/profile-api/v0/hooks/user-created

# Read
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'
UUID=$(php -r 'require "/home/ubuntu/projects/profile-app/config.php"; echo \Looth\ProfileApp\Identity::computeUuid("smoke@example.com");')
curl -sk -H "Host: dev.loothgroup.com" --cookie "loothdev_auth=$TOK" \
  https://127.0.0.1/profile-api/v0/user/$UUID
```

## What slice zero deliberately did NOT do

- No editor / rail / directory UI of any kind.
- No `profiles` table, no sections, no credentials.
- No avatar upload (just stores existing WP avatar URL string when fed).
- No JWT auth on the read endpoint (cookie-gated for now; auth comes when edit
  endpoints land in slice one).
- No geocoding of xprofile location strings. Just stored raw `location_text`.
- No live deploy. Slice zero is dev-only until reconciliation looks right.

## Live-deploy artifacts (staged on dev)

- Source zip: `/var/www/dev/.well-known/profile-app.zip`
  (URL: `https://dev.loothgroup.com/.well-known/profile-app.zip`)
- Bootstrap: `/var/www/dev/.well-known/profile-app-live-bootstrap.sh`
  (URL: `https://dev.loothgroup.com/.well-known/profile-app-live-bootstrap.sh`)
- `deploy/LIVE-DEPLOY.md` — one-shot run instructions + rollback steps.

The bootstrap script is idempotent. Step 7 pauses for a manual one-line
edit (`include snippets/profile-app.conf;` in the loothgroup.com vhost),
then re-run to complete steps 8–11 and the smoke tests.

## Quick-start for next session (slice one likely starts here)

1. Decide: do we rebase `lg_membership.customers.uuid` to v5 before going
   further, or do we keep two identifier spaces and bridge in profile-app
   via email? (The migration is one UPDATE plus rewriting any external
   refs that quote the customer uuid — worth a quick audit first.)
2. Geocoding pass: write `bin/geocode.php` that walks `users WHERE
   location_text IS NOT NULL AND lat IS NULL`, calls Places, fills
   `place_id`, `lat`, `lng`, country/region/city/postcode. Rate-limit hard.
3. Add JWT auth + first edit endpoint (e.g., PATCH /v0/user/<uuid>) so the
   read endpoint stops being public.
4. Create the `profiles` table (lazy — only exists when a user actually
   builds one). Sections-based, designed to back a native-app feed.
5. Live deploy plan: mirror archive-poc's stage-on-dev + curl-on-live pattern.
   `/srv/profile-app/` skeleton on live, Postgres install on live, secret
   provisioning, FPM pool, nginx vhost edit.

## Operator notes

- DB ops: `sudo -u profile-app psql -d profile_app`
- Tail webhook errors: `sudo tail -F /var/log/php-fpm/profile-app-error.log`
- Reload pool: `sudo systemctl reload php8.3-fpm`
- Reload nginx after editing the snippet: `sudo nginx -t && sudo systemctl reload nginx`
- Backfill (idempotent — re-run any time; ON CONFLICT DO UPDATE preserves
  prior data): `sudo -u profile-app php /home/ubuntu/projects/profile-app/bin/backfill.php`
