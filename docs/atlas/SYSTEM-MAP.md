# SYSTEM-MAP.md — How the Looth platform actually works, end to end

**Status:** net-new audit, written from live inspection of **dev2** (the CANONICAL box
that becomes live), 2026-06-19. Verified against the running system, **not** copied from
older docs. Where this disagrees with an older doc, this wins — re-verify before trusting
any prose elsewhere.

**Audit method:** SSH to dev2 `54.146.118.131`, read `/etc/looth/env`, nginx confs,
FPM pools, mu-plugins, the repo clone, Postgres/MySQL catalogs, mounts, and symlinks
directly. Secrets redacted; box-local secrets are not in git.

> Keeper note: treat THIS file as the index of truth for "how it runs." When you change
> infrastructure, update the matching section here in the same task.

---

## Table of contents

1. [Boxes & roles](#1-boxes--roles)
2. [The single knob: `/etc/looth/env`](#2-the-single-knob-etcloothenv)
3. [Env-driven config (no per-box pins)](#3-env-driven-config-no-per-box-pins)
4. [Request path & nginx](#4-request-path--nginx)
5. [The cookie gate](#5-the-cookie-gate)
6. [WordPress](#6-wordpress)
7. [mu-plugins (every one)](#7-mu-plugins-every-one)
8. [The apps](#8-the-apps)
9. [PHP-FPM pools](#9-php-fpm-pools)
10. [Databases](#10-databases)
11. [R2 object storage](#11-r2-object-storage)
12. [Identity & /whoami](#12-identity--whoami)
13. [Serve-from-repo / symlink farm status](#13-serve-from-repo--symlink-farm-status)
14. [Deploy model](#14-deploy-model)
15. [Open gaps & traps](#15-open-gaps--traps)

---

## 1. Boxes & roles

| box  | IP             | role | env file `LG_ENV` / `LG_PUBLIC_HOST` |
|------|----------------|------|--------------------------------------|
| dev1 | 50.19.198.38   | legacy/fouled — **orchestrate FROM**, don't build on it | dev / dev.loothgroup.com |
| **dev2** | **54.146.118.131** | **CANONICAL** — current data, logged-in works, **becomes live** | dev2 / dev2.loothgroup.com |
| dev3 | 52.1.50.54     | deploy-test box (fresher AMI of live; neutered) | dev3 / dev3.loothgroup.com |

SSH from dev1: `ssh -i /home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem ubuntu@<ip>`

Repo: `loothplatformv2`, remote `github-hub:iandavlin/loothplatformv2.git`, branch `main`.
Canonical clone = `dev2:~/loothplatformv2`. dev3 has `~/loothplatformv2-clone`.

---

## 2. The single knob: `/etc/looth/env`

Two human-set lines + an extended block, read by every box. North star: a fresh box
comes up correct from this file alone — no per-host DB search-replace, no code pins.

```
LG_ENV=dev2
LG_PUBLIC_HOST=dev2.loothgroup.com
LG_WP_PATH=/var/www/dev
LG_WP_USER=looth-dev
LG_MYSQL_DB=looth_import
LG_MYSQL_BILLING_DB=lg_membership
LG_PG_DB=looth
LG_PG_DB_PROFILE=profile_app
LG_GATE_COOKIE=loothdev_auth
```

- File is `root:root 644`, box-local (NOT in git). Backups kept (`env.bak-*`).
- Apps read it indirectly via FPM pool `env[LG_*]` lines (see §9) and/or
  `/srv/lg-shared/lg-env.php` (the `lg_env()` helper).
- The two lines that matter at standup are `LG_ENV` and `LG_PUBLIC_HOST`; the rest name
  the DBs/paths so nothing is hardcoded.

---

## 3. Env-driven config (no per-box pins)

What is driven off `LG_PUBLIC_HOST` instead of being stored per-box:

- **WP `siteurl` + `home`** — mu-plugin `lg-siteurl-from-env.php` filters
  `pre_option_siteurl` / `pre_option_home` to `https://$LG_PUBLIC_HOST`. (This mu-plugin
  is the ONE file currently symlinked from the repo — see §13.)
- **Patreon OAuth redirect** — same plugin filters `pre_option_lgpo_redirect_uri` to
  `https://$LG_PUBLIC_HOST/patreon-callback`.
- **App public host** — every app FPM pool sets `env[LG_<APP>_PUBLIC_HOST]=dev2.loothgroup.com`
  and `env[LG_<APP>_ENV]=dev`; apps build absolute URLs / cookie-domain / JWT-iss from it.
- Fallback: if the env host is absent, the plugin leaves the stored DB option untouched.

**Still box-local by nature (cannot be env-driven):** nginx `server_name` + TLS cert path,
`/etc/looth/*` secrets, the gate token. These are re-provisioned per box at standup/cut.

---

## 4. Request path & nginx

One server block: `/etc/nginx/sites-available/dev2.loothgroup.com.conf` (symlinked into
`sites-enabled/`). `server_name loothgroup.com www.loothgroup.com dev2.loothgroup.com`.
TLS via `/etc/letsencrypt/live/dev2.loothgroup.com/` (DNS-01 through Cloudflare).

The main conf handles WP + static + security denials, then **includes the app routers**:

```
include /etc/nginx/snippets/strangler-profile-app.conf;   # profile-app
include /etc/nginx/snippets/strangler-archive-poc.conf;    # archive-poc (front/, hub feed, search)
include /etc/nginx/snippets/strangler-bb-mirror.conf;      # /hub/, forum API
include /etc/nginx/snippets/strangler-events.conf;         # /events/
include /etc/nginx/snippets/strangler-membership.conf;     # membership pages
include /etc/nginx/snippets/lg-shared.conf;                # shared header assets
```
Plus `/etc/nginx/conf.d/`: `loothdev-auth.conf` (gate maps), `loothdev-ratelimit.conf`
(limit zones: `loothdev_whoami`, `loothdev_me`, …), `99-lg-tuning.conf`.

**Routing highlights (all verified in the snippets):**
- `/` → archive-poc front feed (home page = the new front; `location = /` lives in
  `strangler-archive-poc.conf`, not the main conf).
- `/hub/` → bb-mirror (`/hub/index.php` via the bb-mirror FPM sock). `/stream` + `/stream/`
  301 → `/hub/`.
- `/u/<slug>` and `/u/<slug>/edit` → profile-app `web/` pages.
- `/directory/members` → profile-app SSR map page.
- `/event(s)/` → events app.
- `/archive-api/v0/*`, `/bb-mirror-api/v0/*`, `/profile-api/v0/*` → each app's FPM sock,
  per-endpoint `location =` blocks with explicit `SCRIPT_FILENAME`.
- `/thumb/` → thumb-app; `/billing*` → lg-stripe-billing (gate-exempt).
- Security denials: dotfiles (except `.well-known`), `wp-config*.php`, `xmlrpc.php`,
  `*.bak/.save/.old/.swp/.tmp`.

**X-Accel / protected media** (`strangler-profile-app.conf`):
- `/profile-media/<path>` → internal rewrite to `/profile-media-auth` → profile-app
  `web/media.php`. PHP decides visibility (`Visibility::fileVisible`: avatars/banners
  public; gallery/resumes per owner's slider + master switch), then returns
  **X-Accel-Redirect** into the internal alias `/profile-media-internal/` →
  `/srv/profile-app-media/`. Bytes are served by nginx, the auth decision by PHP.

---

## 5. The cookie gate

- Mechanism (carried in the main conf): `/claim?t=<TOKEN>` sets cookie `loothdev_auth`
  (1yr). Tokens: `$loothdev_token` (dev) + `$loothdev_tester_token`. Exempt paths include
  `/billing/*`, `/wp-json/lg-member-sync/*`, `/wp-json/loothdev/*`, `/.well-known/`,
  `/thumb/`, `/claim`, `/robots.txt`.
- **dev2 reality: the gate is OFF (Ian 6/17).** `conf.d/loothdev-auth.conf` maps
  `$loothdev_is_authorized`, `$loothdev_dev_ok`, `$loothdev_tester_ok` all to a constant
  `1` regardless of cookie. So dev2 serves everything ungated; `/claim` still exists but
  is moot. `/gatetest` echoes the three vars for debugging.
- ⚠️ Carryover bug: the `/claim` `Set-Cookie` `Domain=` is still `.dev.loothgroup.com`
  (not dev2). Harmless while the gate is off; fix at cut if the gate is ever re-armed.
- **At cut:** gate goes fully off for production loothgroup.com (public site). The token
  plumbing is a dev artifact.

---

## 6. WordPress

- **Path:** `/var/www/dev` — **owner `looth-dev:loothdevs`** (NOT www-data). WP DB =
  MySQL `looth_import` (per env). Redis object-cache active (`redis-cache` plugin) → direct
  DB option changes need `wp cache flush`.
- **Active plugins (verified `wp plugin list`):** BuddyBoss Platform + Pro (the legacy
  social stack being strangled), ACF Pro + acf-quickedit, FluentForms (+Pro), FluentCRM +
  FluentCampaign, FluentSMTP (→ SES), Relevanssi, Redis Cache, classic-editor,
  code-snippets, EWWW, user-role-editor, wp-ulike, wp-sheet-editor, and the BuddyPress
  add-ons (bp-maps-for-members, bp-xprofile-location, bp-messages-tool, etc.).
- **LG first-party plugins (symlinked from `~/projects`, see §13):**
  `lg-layout-v2` (0.1.67 — the layout engine), `lg-legacy-import`, `lg-snippets`,
  `lg-layout` (old). Plus non-symlink LG plugins installed normally: `lg-apps`,
  `lg-anonymous-authors`, `lg-patreon-stripe-poller` (2.0.0), `lg-recent-posts-widget`,
  `lg-weekly-digest`.

---

## 7. mu-plugins (every one)

In `/var/www/dev/wp-content/mu-plugins/` (mode 640, owned by looth-dev). Purpose verified
from each file's header:

| file | what it does |
|------|--------------|
| `lg-siteurl-from-env.php` | **The env plugin.** Drives siteurl/home/Patreon-redirect from `/etc/looth/env`. **Only repo-symlinked mu-plugin.** |
| `archive-poc-sync.php` | On managed-CPT publish/update/delete, POSTs `{post_id, action}` to archive-poc `/archive-api/v0/_sync` (feeds the discovery index). |
| `bb-mirror-sync.php` | POSTs `{kind, id, action}` to bb-mirror `/bb-mirror-api/v0/_sync` (mirrors bbPress topics/replies into PG `forums`). |
| `lg-article-materializer.php` | On managed-CPT change, POSTs `{post_id}` to materialize the standalone render blob (archive-poc renderer). |
| `profile-auth.php` | **JWT minter.** On WP login mints an RS256 JWT and drops it as the `looth_id` cookie. Reads the user's profile-app `slug` via the internal slug resolver to populate the claim. |
| `profile-whoami-shim.php` | WP-shim proxying `GET /wp-json/looth/v1/whoami` → profile-app `/profile-api/v0/whoami` (same shape). The SLOW path; consumers should hit profile-app directly. |
| `profile-sync.php` | On `user_register`, non-blocking POST to profile-app `/profile-api/v0/hooks/user-created` (provisions the profile row). |
| `looth-auth-issue.php` | Mints a `looth_id` at a PLAIN (non-REST) endpoint for the logged-in user. |
| `lg-viewer-tier.php` | Mints the `lg_tier` cookie (public\|lite\|pro) each page load — **display cache only**, never the gate (tier truth = WP roles). |
| `lg-membership-chrome.php` (+ `lg-membership-chrome/`) | Renders WP membership pages on the shared `/srv/lg-shared/` header instead of BuddyBoss chrome. |
| `lg-comments-frame.php` | `?lg_comments=1` on a singular post → renders ONLY the comments thread (for the standalone comments modal). |
| `lg-admin-tools.php` | Adds a top-level "Looth" admin menu page. |
| `lg-error-pages.php` | WP permalink misses render the standalone branded 404. |
| `lg-event-reminders.php` | One-click signup adds the member to a FluentCRM event-reminder sequence. |
| `lg-weekly-email-bridge.php` | Loopback target that renders the weekly_email HTML (events/weekly lane). |
| `lgms-admin-view-as-toggle.php` | Floating admin "View As" pill on every front-end page. |
| `lgpo-set-password.php` | Inline set/change-password page at `/patreon-password/`. |
| `bb-forum-author-delete.php` | Lets a member delete their OWN bbPress topics/replies. |
| `buddyboss-performance-api.php` | BuddyBoss MU perf shim. |
| `burst_rest_api_optimizer.php` | REST API optimizer. |
| `loothdev-sheets-bridge.php` (+ `.gs.txt`) | REST endpoints for the Showrunner Google Sheet → `event` CPT bridge. |
| `looth-vendor/` | shared vendored libs (JWT etc.). |

Inactive/removed (leave as-is): `lg-user-audit.php.removed-20260609`, `*.bak-*`.

---

## 8. The apps

All first-party apps are PHP, each on its **own FPM pool + unix user** (see §9), served
from `/srv/<app>` symlinks. Storage verified live.

| app | serves | served from | storage |
|-----|--------|-------------|---------|
| **profile-app** | identity/profile/map: `/u/*`, `/profile-api/v0/*`, `/profile-media/*`, `/directory/members` | `/srv/profile-app` → `~/projects/profile-app` | **PG `profile_app`** (31 tables) + media on **local disk** `/srv/profile-app-media/{avatars,banners,gallery,resumes}` (served via X-Accel) |
| **archive-poc** | the new front `/`, hub feed/search, comments/reactions, guitardle, saved posts; standalone CPT renderer | `/srv/archive-poc` → `~/projects/archive-poc` | **PG `looth.discovery`** (12 tables) — primary reads+writes. **`index.sqlite` (11 MB) still present** as the legacy/revert mirror (dual-write era; retirement held pending soak). `config.json` = sponsors + app config. |
| **bb-mirror** | `/hub/`, forum read/write API, SEO redirects | `/srv/bb-mirror` → **`~/worktrees/bespoke-cutover/bb-mirror`** | **PG `looth.forums`** (9 tables) |
| **events** | `/events/` | `/srv/events` → `~/projects/events` | reads WP `event` CPT / shared PG |
| **lg-stripe-billing** | `/billing*` (gate-exempt) | `/srv/lg-stripe-billing` (real dir, `www-data`) | **MySQL `lg_membership`** |
| **membership-pages** | membership WP pages | `/srv/membership` (membership user) | WP |
| **thumb-app** | `/thumb/` image resizer/thumbnailer | `/srv/thumb-app` (real dir, uid 1004) | local `config.json` + assets |
| **lg-shell / lg-shared** | the ONE canonical site header `/srv/lg-shared/site-header.php` + `lg-env.php` helper; all consumers populate `$ctx` from `/whoami` | `/srv/lg-shared` → `~/projects/lg-shared` | n/a (template lib) |

⚠️ **bb-mirror serves from a worktree** (`bespoke-cutover` branch), not `~/projects` and
not the repo. This is the hub/bespoke fork (single-coordinator). Folding it into the repo
is outstanding serve-topology work.

---

## 9. PHP-FPM pools

PHP **8.3**. Per-app pool in `/etc/php/8.3/fpm/pool.d/`, each its own unix user, listening
on a dedicated socket `listen.owner/group = www-data` mode 0660:

| pool | user | socket | key env |
|------|------|--------|---------|
| `looth-dev` | looth-dev | `php8.3-fpm-looth-dev.sock` | the WP pool; carries `LG_ARCHIVE_POC_*`, `LG_BB_MIRROR_*` env for loopback syncs |
| `profile-app` | profile-app | `…-profile-app.sock` | (reads `/etc/looth/profile-r2`, jwt keys) |
| `archive-poc` | archive-poc | `…-archive-poc.sock` | `LG_ARCHIVE_POC_DSN=pgsql:host=/var/run/postgresql;dbname=looth`, `LG_ARCHIVE_POC_ENV=dev`, `LG_ARCHIVE_POC_PUBLIC_HOST=dev2.loothgroup.com` |
| `bb-mirror` | bb-mirror | `…-bb-mirror.sock` | `LG_BB_MIRROR_ENV=dev`, `LG_BB_MIRROR_PUBLIC_HOST` |
| `events` | events | `…-events.sock` | `LG_EVENTS_ENV`, `LG_EVENTS_PUBLIC_HOST` |
| `membership` | membership | `…-membership.sock` | `LG_MEMBERSHIP_ENV`, `LG_MEMBERSHIP_PUBLIC_HOST` |
| `lg-billing-dev` | www-data | `…-lg-billing-dev.sock` | — |
| `tool-dev` | tool-dev | `…-tool-dev.sock` | — |
| `www` | www-data | `php8.3-fpm.sock` | default |

**Per-user-access class (cut-critical, in NO config):** these pools need shared resources →
`chmod o+x /home/ubuntu`; ACLs `setfacl u:profile-app:r` on `/etc/lg-internal-secret` +
`/etc/looth/jwt-private.pem`; `usermod -aG looth-dev www-data`; uploads MUST be a symlink
(not a real dir). Re-apply ALL on any fresh/cut box.

---

## 10. Databases

**MySQL** (127.0.0.1):
- `looth_import` — the live WP database (per `LG_MYSQL_DB`). **This is the one WP uses.**
- `lg_membership` — lg-stripe-billing (user `lg_membership`).
- `looth_dev` — **legacy/stale** WP DB, still present. ⚠️ One lg-stripe-billing config
  references `WP_DB_NAME=looth_dev` while others use `looth_import` — drift to reconcile
  at cut (point all at `looth_import`).

**Postgres** (peer-auth: roles = OS users; DSN = `host=/var/run/postgresql`):
- **`looth`** — two app schemas:
  - `discovery` (owner archive-poc): `article_blobs, content_item, content_tag, tag,
    person, comments, comment_reactions, card_reactions, likes, saved_posts,
    guitardle_results, sync_state`.
  - `forums` (owner bb-mirror): `forum, topic, reply, attachment, person, bp_group,
    forum_read_state, forum_subscription, sync_state`.
- **`profile_app`** (owner profile-app, public schema, 31 tables): `users, profiles,
  wp_user_bridge, profile_sections, profile_{instruments,skills,scenes,highlights,
  credentials,genres,services,socials}, *_catalog, connections, messages,
  message_threads, message_recipients, notifications, user_mutes, practices,
  practice_{members,services,instruments}, reports, sponsor, email_aliases, scene_tags`.
  ⚠️ A few tables (`practice_instruments`, `practice_services`, `user_mutes`) are owned by
  `postgres` not `profile-app` — ownership drift; fix so a `pg_restore` WITH ownership
  round-trips cleanly.

PG restore rule: dump/restore **WITH ownership** (never `--no-owner`); fresh PG needs the
CONNECT+USAGE grants (`tools/cut/sitemap-grants.sql`).

---

## 11. R2 object storage

Cloudflare R2 (S3 API), endpoint `…2b34fc01….r2.cloudflarestorage.com`.

| purpose | DEV bucket | LIVE bucket (at cut) |
|---------|-----------|----------------------|
| profile media (avatars etc.) | `loothgroup-2-0-profile-dev` | `loothgroup2-0-profile-bucket` |
| WP/forum uploads | `loothgroup-uploads-dev` (R2 clone of live uploads) | (live uploads bucket) |

- **Working dev token = the rclone `r2up` remote** (write on both dev buckets, correctly
  403s the live bucket). On dev2, `/etc/looth/profile-r2` is now
  `bucket=loothgroup-2-0-profile-dev` + r2up key/secret (fixed 2026-06-19, backups kept).
  Box-local secret (gitignored) → re-provision per box; swap to live name + live token at cut.
- **WP uploads** are mounted: `wp-content/uploads` → `/mnt/loothgroup-uploads-dev`, an
  rclone FUSE mount of the R2 clone (rw, `--allow-other`).
- **THE 403 TRAP (the #1 mistake):** a scoped token hitting the WRONG bucket NAME returns
  `403 AccessDenied` (not NoSuchBucket) — looks like a dead token but isn't. The live name
  in `/etc/looth/profile-r2` + a dead token was exactly the 6/18 false alarm. Verify with
  object put/get/delete to the EXACT name, NEVER by listing (scoped tokens 403 on list by
  design). Tokens are IP-locked to the dev boxes.
- ⚠️ **Profile media is currently served from LOCAL disk** (`/srv/profile-app-media`, see
  §4/§8), NOT from R2. The avatar consolidation (6/19, branch `avatar-consolidation`,
  commit 4f39f41) re-derived every avatar (BB upload → real gravatar via d=404 probe →
  colored-letter floor) and wrote the single-source set **into the R2 bucket**, but the
  **reader-repoint is still PENDING** (hub `forums.person.avatar_url` + WP `get_avatar`
  must point at the profile-app/R2 URL). Until repointed, the bucket is the staged future
  source, not the live path.

---

## 12. Identity & /whoami

- **Auth = WP login cookie** (BuddyBoss/WP). Gating posting etc. keys on the WP cookie /
  server 401, NOT on /whoami (an unbridged member resolves to anon in whoami but is still
  logged in).
- **`looth_id` JWT:** `profile-auth.php` mints an RS256 JWT on WP login (key
  `/etc/looth/jwt-private.pem`, pub `jwt-public.pem`), claim includes the profile-app
  `slug` (fetched via the internal slug resolver — WP can't reach Postgres directly).
- **`/whoami` — two paths:**
  - FAST (canonical): profile-app `/profile-api/v0/whoami` — JWT-verified, ~5 ms. Consumers
    should hit THIS. Dedicated nginx `location =` with its own rate-limit zone (high freq,
    every page load).
  - SLOW (shim): `/wp-json/looth/v1/whoami` via `profile-whoami-shim.php` — same shape but
    goes through WP/REST bootstrap (~1 s). Repoint any consumer still on this.
- **Tier:** lives in **WP roles** (looth1–4 taxonomy, `docs/TIER-TAXONOMY.md`). `lg_tier`
  cookie + whoami payload are **display caches** — never trust the cookie for enforcement;
  pagination/feeds reconcile tier server-side.
- **The WP↔profile-app bridge:** PG `profile_app.wp_user_bridge` maps WP user → profile
  uuid. `profile-sync.php` provisions on register; unbridged members exist (Patreon-onboard
  and legacy gaps) and surface as whoami-anon despite a valid login — a known cut concern.
- **Profile/identity data source:** custom profile-app (PG `profile_app`, keyed on
  user_uuid), read via `/profile-api/v0/whoami` (self) + `/users` (others) — NOT
  WP/BuddyBoss xprofile meta. Auth + tier remain WP.

---

## 13. Serve-from-repo / symlink farm status

**Mostly DONE on dev2 as of 2026-06-19** (serve-from-repo flip, Phases A+B). dev2 now
serves these out of the repo `~/loothplatformv2` (verified byte-identical to the prior
`~/projects` source — only stray `.bak`/cruft differed):

- `/srv/{profile-app,events,lg-shared,archive-poc}` → `~/loothplatformv2/*`
- WP plugins `lg-layout-v2, lg-legacy-import, lg-snippets` → `~/loothplatformv2/*`
- mu-plugin `lg-siteurl-from-env.php` → repo (was already)

archive-poc runtime: the box-local `index.sqlite` is **seeded into the repo tree**
(gitignored, owned `archive-poc:www-data`) and the repo `archive-poc/` dir carries an ACL
`u:archive-poc:rwx` so the app can write the sqlite + WAL sidecars while git ownership
stays `ubuntu`. `config.json` is tracked + ACL-writable.

**Still on legacy trees (NOT yet repo-served):**
- `/srv/bb-mirror` → `~/worktrees/bespoke-cutover/bb-mirror` — **HELD.** Source is
  identical to the repo, but it's served from the `bespoke-cutover` *branch/worktree* where
  the hub chat actively commits. De-fork = merge `bespoke-cutover` → `main` + move the hub
  chat onto the repo, THEN flip. Coordinated step, not done.
- WP plugin `lg-layout` (old) → `~/projects/lg-layout` — not in the repo; left as-is.

**Backout:** `~/loothplatformv2-serve-rollback.sh` (on dev2) re-points every flipped
symlink to its pre-flip `~/projects` target + reloads php-fpm/nginx (~5 s).

**Served branch caveat:** the clone is currently checked out on `sacred-docs` (app source
== `main`; only `docs/atlas` + a gitignore line differ). After merge, `git checkout main`.

> ⚠️ The older memory `project_dev2_build` claimed A.3 served everything from
> `~/git/looth-platform` on a single checkout — that path/topology never existed on dev2.
> The real flip is the one above (into `~/loothplatformv2`). Trust this section.

---

## 14. Deploy model

- **Intent:** monorepo `loothplatformv2` is the single source of truth; deploy to a box =
  `git pull`. Secrets / data / WP-core / uploads are gitignored and box-local.
- **What IS in the repo:** app source (profile-app, archive-poc, bb-mirror, events,
  lg-stripe-billing, thumbnails), WP plugins source, mu-plugins (`platform/mu-plugins`),
  webroot static layer, infra/tools/docs.
- **Repo-served on dev2 (since 6/19):** profile-app, events, lg-shared, archive-poc + the
  lg-layout-v2/lg-legacy-import/lg-snippets plugins now serve from the repo (see §13), so
  `git pull` deploys them. **Still NOT repo-served:** bb-mirror (worktree fork, held) and
  the old `lg-layout` plugin.
- **Cut model (Ian 6/16):** dev2 IS the box we flip `loothgroup.com` → ; no ground-up
  rebuild. Cut = apply Phase-11 swaps IN PLACE (live salts + JWT keypair, gate off, URL
  rewrite, webhook re-point, R2 live names/token) + flip DNS. The serve-from-repo
  consolidation folds the already-wired box into git so future deploys are `git pull`.

---

## 15. Open gaps & traps

- **Avatar reader-repoint PENDING** (§11): bucket has the consolidated set; hub +
  WP get_avatar still read the old source.
- **Serve-from-repo: bb-mirror still pending** (§13): 7 apps/plugins flipped to repo 6/19;
  bb-mirror held (worktree fork `bespoke-cutover` — de-fork + merge to main first).
- **MySQL `looth_dev` vs `looth_import` drift** (§10) — one billing config still names
  `looth_dev`.
- **PG ownership drift** (§10) — `practice_*`/`user_mutes` owned by `postgres`.
- **Gate cookie Domain bug** (§5) — `.dev.loothgroup.com` hardcoded in `/claim`.
- **Unbridged members → whoami-anon** (§12) — Patreon-onboard + legacy gaps.
- **R2 403 = wrong bucket NAME, not a dead token** (§11) — the recurring false alarm.
- **At cut re-provision checklist:** `/etc/looth/env` (LG_ENV/host), TLS cert,
  `/etc/looth/*` secrets + ACLs, R2 live names/token, `chmod o+x /home/ubuntu`,
  re-arm iptables SMTP cap after reboot, `wp cache flush` after any direct DB change.

---

*Keeper: when you touch infra, update the matching section + the gaps list in the same
task. This file is audited truth as of 2026-06-19 — re-verify a section before trusting it
if the box has changed since.*
