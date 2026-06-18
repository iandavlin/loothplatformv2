# Live Inventory — what we think live looks like

> v0 draft, 2026-05-27 (cutover chat, opening pass).
> Sources are local artifacts and memory entries that pre-date this
> session. **Everything tagged `[VERIFY]` is inferred, not observed.**
> Ian will run read-only commands against live (54.157.13.77) and paste
> output; we'll fold confirmations + corrections back in.

## Host facts (mostly known)

| Fact | Value | Source |
|---|---|---|
| Live IP | 54.157.13.77 | memory `reference_ccdev_server.md` |
| Live WP root | `/var/www/html` | archive-poc `LIVE-DEPLOY.md`, `reference_lg_layout_v2_deploy.md` |
| Live WP owner | `looth-live:looth-live` | same |
| PHP-FPM | 8.3 | same |
| Live FPM pool socket | `php8.3-fpm-looth-live.sock` ✓ | BATCH-01 ✓ |
| `wp_` table prefix | yes `[VERIFY]` | archive-poc `LIVE-DEPLOY.md` |
| Edge | Cloudflare (CF-IPCountry header forwarded) | `PROD-CUTOVER.md` Cloudflare section |
| Object cache | Redis, multi-DB layout: db0=loothgroup, db2=loothtool | archive-poc `LIVE-DEPLOY.md` |

## What we already shipped on live

Live is NOT a clean slate — there's a partial strangler footprint:

| Artifact on live | Where | Confidence |
|---|---|---|
| archive-poc app | `/srv/archive-poc/` | known — followed the deploy doc; `https://loothgroup.com/archive-poc/` is the canonical example URL throughout |
| `archive-poc` system user + FPM pool | `php8.3-fpm-archive-poc.sock` | known |
| archive-poc sync mu-plugin | `/var/www/html/wp-content/mu-plugins/archive-poc-sync.php` | known |
| `/etc/lg-archive-poc-secret` | exists, readable by `looth-live` + `archive-poc` (setfacl) | known (archive-poc handoff) |
| nginx route `/archive-poc/` + `/archive-api/v0/*` | added to live nginx | known |
| `lg-viewer-tier.php` mu-plugin (mints `lg_tier` cookie) | `/var/www/html/wp-content/mu-plugins/` | known (archive-poc handoff) |
| `lg-patreon-stripe-poller` plugin | `/var/www/html/wp-content/plugins/lg-patreon-stripe-poller/` | known direction |
| `lg-stripe-billing` Slim app | `/var/www/billing/lg-stripe-billing/` `[VERIFY]` — path from PROD-CUTOVER step 1 | `PROD-CUTOVER.md` |
| `lg-layout-v2` plugin | `/var/www/html/wp-content/plugins/lg-layout-v2/` | known (`reference_lg_layout_v2_deploy.md`) |
| Cookie-mint mu-plugin (`lg-viewer-tier`) | sets `lg_tier` from role | known |

Implication: live already understands "strangler app gets its own FPM pool + its own nginx prefix." The cutover-day pattern for profile-app, bb-mirror, and the `/whoami` route can mirror what archive-poc did.

## What dev has that live doesn't (cutover deltas to ship)

Each row is a thing the cutover plan must move from dev → live.

| Surface | On dev | On live | Move how |
|---|---|---|---|
| `/etc/lg-internal-secret` | exists (looth-dev session 2026-05-27 23:20) | absent `[VERIFY]` | generate fresh secret on live; mirror file mode `root:www-data 0640`; symmetrical key both directions |
| `LG_INTERNAL_SECRET` PHP constant | defined in dev wp-config | absent `[VERIFY]` | add to live wp-config loader |
| `/wp-json/looth-internal/v1/user-context/{id}` endpoint | shipped in poller (`InternalRestController`) | absent — needs poller plugin update on live | poller deploy w/ tested code |
| `PurgeNotifier` (fires on `looth_tier_changed`) | shipped in poller | absent on live | poller deploy |
| `UserProvisioner` fires `looth_tier_changed` on signup | shipped | absent | poller deploy |
| `Arbiter` fires `looth_tier_changed` on transition | shipped | absent | poller deploy |
| `LG_PROFILE_APP_URL` config | hardcoded `dev.loothgroup.com` in PurgeNotifier `[VERIFY]` | n/a | introduce as constant/wp-option BEFORE live deploy (coord doc §3g already flagged) |
| profile-app code at `/home/ubuntu/projects/profile-app/` | shipped, slice 3 live on dev | absent | new system user, new FPM pool, new app dir under `/srv/profile-app/`, postgres DB |
| profile-app Postgres DB | running on dev | absent `[VERIFY]` — stand up postgres on live? Or reuse a managed instance? — **open question** | TBD |
| profile-app nginx routes (`/profile/edit`, `/u/<slug>`, `/p/<slug>`, `/profile-api/v0/*`, `/directory/members`, `/members/<slug>` 302 hijack) | live in `strangler-profile-app.conf` (cookie-gated) | absent — and on live the `if ($loothdev_is_authorized != 1)` guards must be **stripped** | port snippet w/ guards removed |
| bb-mirror app + FPM pool + SQLite + `/forums-poc/` route + mu-plugin | shipped on dev (mu-plugin held pending Ian) | absent | new system user, new pool, etc. (held until step 5 of cutover sequence per coord §4) |
| WP `users.business_name` mirror (slice 2.75 cutover ran on dev) | applied | absent | `bin/migrate-from-xprofile.php` run on prod (slice 4 — gated on whoami + archive-poc switchover + shared header) |
| BB Starter Profile Type post + UserProvisioner tagging + Arbiter sync | applied on dev | absent | poller deploy + creating Starter profile-type post (id reused or recreated) |
| code-snippets snippet #90 ("Log Out Looth 1 Users") | disabled on dev | likely still active `[VERIFY]` — disabling it is in `PROD-CUTOVER.md` looth1-rework | wp db query during cutover |
| Shared header partial across BB-replacement / lg-layout-v2 / archive-poc | not built yet on either side | n/a | not yet built — cutover §4 step 3 |
| `/whoami` endpoint at `/profile-api/v0/whoami` and WP shim at `/wp-json/looth/v1/whoami` | not yet built on dev (slice 3.5 in flight) | n/a | profile-app ships on dev first |

## Out-of-scope for v0 inventory (defer)

Per briefing §"Scope — narrow first":

- SSL certs / DNS / monitoring / backups
- Full nginx site map (only the routes strangler-apps will own)
- Non-strangler WP plugins
- Themes (unless one of them is layering BB chrome that fragmentation §3d cares about — likely needs a separate look but not in v0)

## Open questions cutover will need answered before it can plan

1. **profile-app on live — Postgres home?** Same box as WP? Separate instance? Managed RDS? Coordinator/profile-app chats haven't said. Affects: connection string, network ACLs, backup strategy, cutover rollback.
2. **Are `/profile/edit`, `/u/<slug>`, `/p/<slug>` already in use on live by BB or anything else?** dev's BB hijack assumes those paths are free. Need a `curl -I` sweep to confirm live currently 404s on them.
3. **What does live's `wp_options` say about active plugins and theme?** The list determines what we have to leave alone vs touch.
4. **Cloudflare cache rules currently configured?** What pages are aggressively cached. We'll want a list of CF rules that may need bypasses or purges at cutover.
5. **Live cron landscape — what's running, on what cadence?** WP cron via OS cron at >5min for orphan recovery (per `PROD-CUTOVER.md` §"Pending-session reconciliation"). Verify.
6. **BB data scale on live** — group count, membership count, xprofile field count, forum post count, activity-feed depth. Drives sizing on profile-app migration + bb-mirror backfill.
7. **Live nginx organization** — does live use the same `snippets/strangler-*.conf` extraction pattern that dev now uses? Or is everything still in `loothgroup.com.conf`?

---

## BATCH-01 findings (nginx, 2026-05-27)

### Confirmed ✓

- **Live FPM socket** is `php8.3-fpm-looth-live.sock` (pool `looth-live.conf`).
- **archive-poc fully wired:** pool `archive-poc.conf` → socket `php8.3-fpm-archive-poc.sock`; nginx has `^~ /archive-poc/` + `/archive-api/v0/{search,item,_config,rows-more}` + `/archive-api/v0/_sync` blocks (see `loothgroup.com.conf` lines 79, 98, 108).
- **No collision** on any strangler URL we plan to introduce (`/profile/edit`, `/u/`, `/p/`, `/profile-api`, `/whoami`, `/forums-poc`, `/bb-mirror`, `/looth-internal`). Grep against the main conf returned nothing.
- **nginx 1.24.0** — matches dev. All directives we use will work.
- **Main conf is short (137 lines)** and well-organized; adding strangler routes is a clean prepend before `location /`.
- Other live sites: `loothtool.com.conf` (138 lines static), `merch.loothgroup.com.conf` — separate FPM pools `tool-live`, `merch-live`. Not in our cutover scope.

### New findings — operational landmines

- **No `/etc/nginx/snippets/` extraction pattern on live.** Only stock `fastcgi-php.conf` + `snakeoil.conf`. Dev's `snippets/strangler-*.conf` pattern doesn't exist yet on live; we either:
  (a) Inline the strangler blocks directly into `loothgroup.com.conf` (simpler, single-file diff, easy rollback);
  (b) Adopt the snippets pattern on live too (mirrors dev exactly; needs `sudo mkdir /etc/nginx/snippets/strangler` + per-app .conf files).
  Recommend (b) — keeps source-of-truth from project repos working identically dev↔live (matches coord doc §3g). One-time setup, then every future strangler is "cp snippet + reload."
- **`sites-enabled/loothtool.com.conf` and `merch.loothgroup.com.conf` are real files, NOT symlinks** (only `loothgroup.com.conf` is symlinked). Edits to `sites-available/loothtool.com.conf` won't take effect. Not our problem at cutover, but flag it.
- **Stale configs in `sites-available/`** from pre-migration days: `dev.loothgroup.com` (no `.conf` extension), `dev.loothgroup.com.bak.thumb`, `dev.loothtool.com.conf`. None enabled. Cleanup task (post-cutover); leave alone for now.
- **No Cloudflare `real_ip` / `set_real_ip_from` / `CF-Connecting-IP` configuration** in either `nginx.conf` or `loothgroup.com.conf`. If CF is in front (PROD-CUTOVER.md implies it is — rate-limit rules, CF-IPCountry forwarding), nginx is logging CF edge IPs as client IPs and any rate-limit zone we add will key off the wrong address. **Confirm with `dig` whether CF actually fronts loothgroup.com** before we add a rate-limit zone for the looth-internal endpoint or any other strangler route.
- **Disabled FPM pools** present: `lg-billing-dev.conf.disabled`, `looth-dev.conf.disabled`, `tool-dev.conf.disabled`, `www.conf.disabled`. Leftover from dev migration. Cleanup, not cutover-blocking.

### Updated delta list (writes the cutover plan must contain)

| Delta | Where applied | Risk |
|---|---|---|
| Create `/etc/nginx/snippets/` strangler files (profile-app, bb-mirror, looth-internal exempt) — mirroring dev | live nginx config | low — additive, gated by reload test |
| Strip `if ($loothdev_is_authorized != 1)` cookie-gate guards from every snippet before applying to live | each snippet | medium — easy to miss one; **must grep the live snippets after copy** |
| Stand up `php8.3-fpm-profile-app` pool | `/etc/php/8.3/fpm/pool.d/` | low — additive, isolated |
| Stand up `php8.3-fpm-bb-mirror` pool | same | low — additive |
| Add `/etc/nginx/snippets/strangler-internal.conf` (or inline) for `/wp-json/looth-internal/` exempt block — dev has it, live doesn't | live nginx | low — additive |
| If CF fronts the site, add `real_ip` block to `nginx.conf` ahead of any rate-limit work | global nginx | medium — affects logging + access control |

---

---

## BATCH-02 findings (WP + cron + secrets, 2026-05-27)

### Confirmed ✓

- **Theme:** `buddyboss-theme` parent + `buddyboss-theme-child-1.0.0` child.
- **BuddyBoss core:** `buddyboss-platform` + `buddyboss-platform-pro` 3.0.0 active. (Heavy — many BB cron hooks visible.)
- **lg-layout-v2** active on live at **0.1.62**. (Dev memory referenced older versions — live may be ahead/behind dev; need to reconcile before any layout shipping.)
- **Cloudflare confirmed in front** — `dig loothgroup.com` returns 104.26.x / 172.67.x (CF Anycast); response headers include `server: cloudflare`, `cf-ray`, `cf-mitigated: challenge`. So:
  - All external curl-from-anywhere route checks are intercepted by CF — the 403s on `/profile/edit`, `/u/*`, `/p/*`, `/forums-poc/`, `/wp-json/looth-internal/`, `/directory/members` are CF challenge responses, **not WP saying "404 free route."** Re-test in BATCH-03 via loopback (`curl -H "Host: loothgroup.com" http://127.0.0.1/...`) for the true picture.
  - **nginx `real_ip`/`set_real_ip_from` is unconfigured** — every request logs the CF edge IP, not the visitor. Any rate-limit zone we add (e.g. for `/wp-json/looth-internal/`) will key off the wrong IP.
- **archive-poc serves 200** on `/archive-poc/`; `/srv/archive-poc/` present, owned `archive-poc:archive-poc`. Includes its own `SESSION-HANDOFF.md` + config.json + backups.
- **Secrets:** `/etc/lg-archive-poc-secret` present (root:www-data 0640 + ACL). `/etc/lg-internal-secret` absent (as expected — created at cutover).
- **Postgres is installed** but inactive on the live box (`systemctl is-active postgresql` → "inactive", `ss -tln :5432` → nothing). **This is the answer to open question #1**: profile-app's DB can live on the same box. Just needs start + DB create + migrations. Need follow-up to confirm package version + data-dir state.
- **mu-plugins on live:** archive-poc-sync.php ✓, lg-viewer-tier.php ✓, plus LG-Admin-Profile-Link, buddyboss-performance-api, lg-admin-tools, lg-events-shortcode, lg-fatal-catcher, lg-save-probe, looth-roles. Many archive-poc-sync.php.bak.* — disciplined rotation. Good.

### Critical findings — cutover blockers

1. **`lg-patreon-stripe-poller` is NOT installed on live.** Not in the plugin list at all. This means:
   - No Arbiter writing roles on live (so what's writing looth* today? need to check)
   - No `looth_tier_changed` action firing
   - No `PurgeNotifier`
   - No `/wp-json/looth-internal/v1/user-context/{id}` endpoint
   - No internal-channel secret consumer

   `lg-patreon-onboard 1.1.0` and `lg-looth4-expiry 1.0.0` are active — they're related but distinct from the poller. **BATCH-03 needs to determine what currently provisions / promotes / demotes looth1-4 on live**, because the whole `/whoami` design assumes the poller is the writer.

2. **code-snippets #90 ("Log Out Looth 1 Users Immediately") is ACTIVE on live.** Plus #53 "Force Log Out" is also active. PROD-CUTOVER calls out #90 as a must-disable before looth1 is repurposed from "lapsed" to "starter / public-equivalent."
   ```
   id  name                                  active
   53  Force Log Out                         1   ← what does this do? check
   88  Block Looth1 Users from Login         -1  (inactive)
   89  Logout Looth1                         -1  (inactive)
   90  Log Out Looth 1 Users Immediately     1   ← MUST disable at cutover
   ```

3. **OS cron firing wp-cron AT 5-minute cadence — two ways:**
   ```
   # ubuntu:
   */5 * * * * curl -s https://loothgroup.com/wp-cron.php?doing_wp_cron >/dev/null
   # looth-live:
   */5 * * * * /usr/local/bin/wp cron event run --due-now --path=/var/www/html --quiet
   ```
   Both fire every 5 minutes. The curl path goes through CF → nginx → wp-cron.php (normal HTTP path); the WP-CLI path runs in-process under looth-live. They're likely both working, with some redundancy. Not a blocker, but worth knowing two paths exist.

4. **Cloudflare in front + nginx not configured for real-IP.** Combined effect: any rate-limit zone we add at cutover will rate-limit the CF edges (104.26.x / 172.67.x), not visitors. Bug waiting to happen. Add `set_real_ip_from <CF ranges>; real_ip_header CF-Connecting-IP;` to nginx before introducing any zone.

5. **CF cache warmer cron:** `0 * * * * /home/ubuntu/cache_warm.sh` runs hourly. Need to read that script — if it pre-warms strangler-route URLs (e.g. some homepage that includes `/u/<slug>` links), the cutover-day warmup behavior changes when the routes flip.

6. **Stale dev cron firing from live:** `*/5 * * * * curl -s https://dev.loothtool.com/wp-cron.php?doing_wp_cron` — dev.loothtool migrated off this box on 2026-05-15. This curl is now hitting a different box every 5 min. Cleanup task, not cutover-blocking.

### Other observations

- **`rank-math 1.0.269` is active on live.** The SEO-strategy memory (4 days stale) said "Rank Math removed for perf" — that was either a dev-only decision or hasn't been actioned. Worth flagging to coordinator that the SEO memo is wrong about prod state.
- **Plugin landscape is heavy:** 50+ active plugins, lots of cron hooks (95+). Average WP-cron tick has ~5 minute-frequency tasks running. Action Scheduler queue active. Not a cutover problem, but noise to be aware of when debugging.
- **`lg-stripe-billing` Slim app location not yet verified** — PROD-CUTOVER assumed `/var/www/billing/lg-stripe-billing/`, but BATCH-02 didn't check. BATCH-03 includes.
- **wp-config LG_* define grep returned empty** (no output visible) — confirms no `LG_INTERNAL_SECRET`, `LG_PROFILE_APP_URL`, or any other LG constant yet defined in wp-config.

### Updated open questions

| # | Question | Status |
|---|---|---|
| 1 | profile-app Postgres home? | **Answered:** postgres installed on the live box, inactive. Decision: use it. |
| 2 | Strangler URLs free on live? | **Inconclusive** — CF challenged the test. Re-test via loopback in BATCH-03. |
| 3 | Active plugins/theme on live? | **Answered:** see above. |
| 4 | Cloudflare cache rules? | **Partially:** CF in front confirmed. Specific cache rules TBD (need Ian to list from CF dashboard). |
| 5 | Live cron landscape? | **Answered:** see above. |
| 6 | BB data scale? | **Not yet** — BATCH-03. |
| 7 | nginx snippet pattern on live? | **Answered:** no snippets/strangler-*.conf — needs introducing. |
| **NEW** | What's writing looth1-4 roles on live today, given no `lg-patreon-stripe-poller`? | **BATCH-03** |
| **NEW** | `lg-stripe-billing` Slim app location/status on live? | **BATCH-03** |
| **NEW** | Does code-snippets #53 ("Force Log Out") interact with cutover too? | **BATCH-03** (read snippet body) |
| **NEW** | What does `cache_warm.sh` warm? | **BATCH-03** |
| **NEW** | wp_users schema state — `business_name` column present yet? (slice 2.75 migration tracking) | **BATCH-03** |

---

---

## BATCH-03 findings (2026-05-27) — the picture changes substantially

### 🚨 Headline: live runs Patreon, not Stripe

Dev's whole strangler design (Arbiter, PurgeNotifier, `looth_tier_changed`,
`user-context` endpoint) is built around `lg-patreon-stripe-poller`. **None
of that exists on live.** Confirmed:

- `/var/www/billing/` — does not exist
- `/srv/lg-stripe-billing/` — does not exist
- `/var/www/html/wp-content/plugins/lg-patreon-stripe-poller/` — does not exist

What IS writing looth* roles on live:

- **`lg-patreon-onboard 1.1.0` plugin** — Patreon OAuth + Sync Cron + Sync Engine
- **`lg-looth4-expiry 1.0.0` plugin** — manages looth4 admin/comp expiry
- **`mu-plugins/looth-roles.php`** — only file that grep flagged for role writes
- **code-snippets #44 "Patreon Tier Toggler"** — handles content gating by Patreon tier (defines `LOOTH_CPT_LIST` of 9 CPTs, taxonomy-based gating)
- **code-snippets #35 "Force Remember Me On Login"** — Patreon-specific "stay logged in" workaround
- **code-snippets #86 "WP Login Branding"** — three-card login UI specifically referencing Patreon Connect

**Implication for cutover sequence (coord doc §4):** the step "poller's
`user-context` endpoint" prereq for /whoami doesn't apply — that plugin
isn't on live. Live cutover must either:

- **Path A:** Ship `lg-patreon-stripe-poller` + `lg-stripe-billing` to
  live first (whole new system, plus a Stripe-vs-Patreon billing decision
  Ian hasn't made yet — Patreon is still the active payment processor).
- **Path B:** Adapt `/whoami` to read from `lg-patreon-onboard`'s data
  model on live, with the same response shape. Smaller change but
  diverges dev↔live behavior.
- **Path C:** Stop the cutover here, finish the Stripe migration on
  live (which is a separate years-deep workstream), then proceed.

**This is a coordinator-level decision and needs Ian.** It's not a
cutover-chat call.

### 🚨 No Postgres on live → resolved: one shared instance, three schemas

BATCH-03 confirmed postgres isn't installed at all on live. Coordinator
ruled on this 2026-05-28 (briefing-cutover-storage-update.md →
STRANGLER-COORDINATION.md §3i):

**One postgres server, three schemas:**
- `profile_app` (already exists on dev)
- `forums` (BB-mirror migrates from SQLite at cutover)
- `discovery` (archive-poc migrates from SQLite at cutover)

Implications for the cutover plan:
- **Postgres install becomes step 1** (was a TBD prereq). Ian's lean is
  on-box `apt install` — matches dev. RDS still defensible if HA
  becomes a concern; doesn't block planning.
- **Schema provisioning** is part of step 1 — three schemas, three
  postgres roles, each app sees only its own schema.
- **pgloader migration steps** added to the cutover sequence between
  profile-app schema provisioning and the first strangler write:
  - **Step 3a:** pgloader archive-poc SQLite → `discovery` schema. Swap
    DSN, restart FPM pool. Rollback = revert DSN, reload FPM. SQLite
    file stays on disk through soak.
  - **Step 3b:** pgloader BB-mirror SQLite → `forums` schema. Same pattern.
- **Backup story simplifies** — one `pg_dump` cron covers all three
  schemas. The existing `backup-sites.sh` mysqldump cron is unchanged.
- Both migrations run in seconds (SQLite datasets are tiny: archive-poc
  is editorial index, BB-mirror is ~6k forum rows).

### ✓ Confirmed clean: strangler URL space (loopback re-test)

Bypassing CF, hitting nginx directly:

```
301  /profile/edit         ← WP redirect (wp-login?), not a content collision
302  /u/some-slug          ← WP redirect (probably /members/<slug> rewrite)
302  /p/some-slug          ← WP redirect
302  /forums-poc/          ← WP redirect (probably to /forums/)
404  /wp-json/looth-internal/v1/foo   ← clean ✓
301  /directory/members    ← BB renders this (will be replaced)
302  /whoami               ← WP redirect
```

Not 404s, but **not content collisions either**. nginx `location ^~`
prefix matches before WP's catch-all, so strangler routes will short-
circuit before WP gets a chance to redirect. We're clear to take them.
What we should NOT do: depend on the redirects continuing after cutover
— if anything in WP-side code (caches, sitemaps, schema.org structured
data) hardcodes those paths, it'll break. Worth a follow-up grep.

### ✓ User + BB data scale

| Metric | Count |
|---|---|
| Total WP users | **1,795** |
| Total BB groups | 20 |
| BB group memberships | 12,217 — **of which ~8,975 are vestigial** (5 auto-enroll groups at ~1,795 each; coord §3d confirmed) |
| Real regional memberships | ~3,073 (across the 9 Local Looths) |
| BB xprofile fields | 121 |
| BB xprofile data rows | 8,529 |
| Forums (CPT) | 45 |
| Topics (CPT) | 1,254 |
| Replies (CPT) | 4,847 |
| BB activity rows | 4,323 |

**Looth role distribution (in order asked):**

```
looth1: 440   ← starter (per dev's repurposed meaning)
looth2: 471   ← lite paying (Patreon equivalents)
looth3: 678   ← pro paying
looth4: 15    ← comp / admin
```

**Implications:**

- profile-app migration (slice 4 `bin/migrate-from-xprofile.php`):
  ~1,795 user rows to migrate. Single-digit minutes, not hours.
- bb-mirror backfill: ~6,146 forum rows (forums + topics + replies). Trivial.
- BB group cleanup post-cutover: dropping the 5 vestigial groups
  releases ~9k membership rows. Matches dev coord §3d's "~9000 junk
  memberships" prediction exactly.
- 1,164 paid users (looth2+3+4) vs 440 starters. Significant paying
  base; cutover impact has to be planned with that population in mind.

### ✓ wp_users schema confirmed bare

```
ID, user_login, user_pass, user_nicename, user_email, user_url,
user_registered, user_activation_key, user_status, display_name
```

No `business_name` column. Slice 2.75 migration hasn't been run yet on
live (as expected; was a dev-only schema change).

### ✓ Other artifacts

- **`cache_warm.sh`** hourly only warms `/archive/?e-page-f126e06=N`
  pages 1–10. Doesn't touch any strangler URL. No interaction.
- **`backup-sites.sh`** mysqldumps `wp_loothgroup` + `loothtool_prod`
  twice daily to R2 (`r2:loothgroup-backups/db/`). Sundays additionally
  tar `wp-content/` (excluding uploads). **DB name on live = `wp_loothgroup`**.
  Solid backup story.
- **lg-layout-v2 version drift on live:** header `0.1.62`, constant
  `0.1.61`. The `reference_lg_layout_v2_deploy.md` memory flagged this
  exact problem ("keep them in sync going forward"). Cosmetic; flag.
- **code-snippet #53 "Force Log Out"** — destroys all user sessions
  for all users. Looks like an emergency / manual-trigger function (no
  hook attached). Safe — doesn't auto-fire. Confirmed not a cutover blocker.
- **code-snippet #90 confirmed** to log out anyone with `looth1` role on
  login. Per PROD-CUTOVER, must disable before cutover.
- **code-snippet #104 "BuddyBoss Reach Around for Classic Editor"** —
  patches a BB 3.0.0 regression (`bp_get_invite_post_type()`). Live
  has a BB-platform bug stub. Note for upgrade compatibility.

### Updated open questions

| # | Question | Status |
|---|---|---|
| 1 | profile-app Postgres home? | **RE-OPENED** — not installed on live. Decision: install on box vs RDS. |
| 2 | Strangler URLs collision-free? | ✓ — 30x redirects but `^~` will short-circuit. |
| 6 | BB data scale? | ✓ — answered. Small data set; migration is fast. |
| **A** | **Path A/B/C for the Patreon-vs-Stripe gap?** | ✅ **CLOSED 2026-05-28: B-now / A-later / Stripe dormant.** Poller plugin ships with the strangler but with no Stripe creds, so Arbiter sees only Patreon-source data via the adapter. Stripe-enable later is a config change, not a deploy. See `briefing-cutover-refocus.md` + coord §2/§3h/§4. |
| **B** | Read full body of code-snippet #44 + mu-plugins/looth-roles.php to understand live's role-writing flow before designing `/whoami`'s data source. | BATCH-04. |
| **C** | Any WP cache / sitemap / structured-data plugin hardcoding `/u/`, `/profile/edit`, `/directory/members`? | BATCH-04 grep. |

---

## BATCH-04 findings (2026-05-28) — Patreon adapter spec inputs

### Role-writer identified

The Patreon → WP role flow on live:

1. **`mu-plugins/looth-roles.php`** (just 23 lines) — only *defines* `looth1`–`looth4` as WP roles with `read => true`. Survives any plugin deactivation. **NOT a role-writer.**
2. **`lg-patreon-onboard` plugin** — 3 files:
   - `lg-patreon-onboard.php` (main)
   - `includes/class-lgpo-sync-cron.php` — owns the `lgpo_patreon_auto_sync` cron hook (hourly per BATCH-02)
   - `includes/class-lgpo-sync-engine.php` — **the role-writer.** Grep flagged it for `add_role | wp_update_user | set_role | ->roles | update_user_meta.*looth`. This is the crown jewel for the Patreon adapter spec.
3. **`lg-looth4-expiry` plugin** — separate concern. Adds `looth4_expires_at` user meta; demotes to looth1 on expiry via cron `lg_looth4_expiry_check`. Provenance-relevant for `/whoami` (looth4 + future expiry = comp).
4. **code-snippet #44 "Patreon Tier Toggler"** — content gating only (writes `patreon-level` post meta on `set_object_terms` for tier-taxonomy). **NOT user-role-writer.** Defines `LOOTH_CPT_LIST` (9 CPTs) + `LOOTH_TERM_TIER_MAP` (`free=0`, `looth-lite=1`, `looth-pro=7`, `patron-saint=7`). Out of `/whoami`'s scope; affects how the Patreon plugin gates content reads, which is orthogonal to user-tier identity.

**Implication for poller chat's Patreon adapter (P2):**

BATCH-04B (full Sync Engine source) revealed the coexistence
primitive that makes B-now / A-later mechanically clean:

- **`payment_source` usermeta is the source-of-truth flag** (set by
  Sync Engine on every write):
  - `'patreon'` → user's active tier is Patreon-sourced
  - `'stripe'` → user's active tier is Stripe-sourced (LGPO skips)
  - (absent) → looth1 / no active paid source
- **LGPO already enforces the coexistence guard** in
  `compare_member()`:
  - `payment_source='stripe'` + looth2/3 → skip (preserves Stripe role)
  - looth4 → skip always (managed by lg-looth4-expiry)
  - administrator → skip always
- **Adapter read pattern** (no Patreon API calls; LGPO already did
  that work):
  - `WP_User->roles` → current looth tier (already mapped by LGPO)
  - `payment_source` usermeta → source provenance
  - `lgpo_patreon_user_id` / `lgpo_patreon_tier_id` → if specific
    tier metadata needed
- **Provenance derivation** (refined):
  - `looth4` → `comp` (managed by lg-looth4-expiry; cron demotes to
    looth1 on `looth4_expires_at`)
  - `looth2` / `looth3` + `payment_source='patreon'` → `paid`
  - `looth1` + `lgpo_patreon_user_id` set (had a prior Patreon link) +
    no current `payment_source` → `lapsed`
  - `looth1` + no `lgpo_patreon_user_id` → `new`
- **B-now / A-later is mechanically free:** with no Stripe creds,
  no users get `payment_source='stripe'` written. LGPO's skip-stripe
  guard is a no-op. When Stripe is enabled later, Stripe-poller writes
  `payment_source='stripe'` to those users and LGPO automatically
  preserves their role without any code change. The coexistence isn't
  something we have to build — it's already there.

**Operational bonus:** LGPO has a revertable change log
(`lgpo_sync_changelog` option, 3-day TTL, batched, with
`revert_batch()` capability). If cutover triggers any Patreon-side
weirdness, recent role changes can be rolled back batch-wise. Useful
rollback primitive for step 9/10.

**WP options LGPO reads** (for completeness):
- `lgpo_creator_access_token` (Patreon API token)
- `lgpo_campaign_id` (Patreon campaign)
- `lgpo_tier_map` ([patreon_tier_id => wp_role_slug])
- `lgpo_auto_sync_enabled` + `lgpo_sync_frequency` (cron control)

The adapter does NOT need any of these — it reads downstream of LGPO,
not parallel to it.

**Formal batch results captured at:**
- [batch-output/BATCH-04-results.md](batch-output/BATCH-04-results.md)
- [batch-output/BATCH-04B-sync-engine-body.md](batch-output/BATCH-04B-sync-engine-body.md)

### Collision grep — clean ✓

`grep -rln /profile/edit | /directory/members | /u/<var> | /p/<var>` across `plugins/lg-*`, `mu-plugins/`, `themes/buddyboss-theme-child-1.0.0`: **no matches**. Strangler URLs are not hardcoded in any code we control. The 30x redirects come from generic WP/BB redirect-canonical guesses; `^~` nginx prefixes will preempt all of them.

### Strangler-URL redirect trace (cosmetic, informational)

- `/profile/edit` → 301 → `/edit-efficiency-consultant/` (WP redirect_canonical guessing at the closest known page slug — there must be a page or CPT entry with a slug starting `edit-`)
- `/u/some-slug` → 302 → `/wp-login.php?bp-auth=1&action=bpnoaccess` (BB's private-network gate firing when it can't resolve `some-slug` as a member nicename)
- `/directory/members` → 301 → `/members/` (WP canonical to the existing BB members directory)

All three preempted by nginx `^~` prefixes at cutover step 10. Nothing to fix beforehand.

### Patreon plugin landscape

Only `lg-patreon-onboard` matched the patreon-* grep. There is no Patreon SDK plugin or `patreon-connect` alongside. **All Patreon-integration code lives in this single custom plugin owned by Ian.** Implication: the Patreon adapter has exactly one upstream to coordinate with.
