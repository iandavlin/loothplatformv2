# LIVE DEPLOY — audit + plan (drafted 2026-06-12, visibility-refactor session)

> **CANONICAL CUT DOCUMENT (re-confirmed 6/12 pm after Ian answered the
> decision list).** Plot history: the doc audit found cutover/cut-day-runbook.md
> (blue-green/new-box, 6/9) and briefly made it canonical — then Ian
> confirmed ip-172-31-45-223 IS old live (no second box exists) and ruled
> IN-PLACE / ONE DB. The cutover lane's batches + LIVE-INVENTORY remain
> reference; its blue-green model is not pursued.
>
> ## THE SIX RULINGS (Ian, 6/12 pm — do not relitigate)
> 1. ip-172-31-45-223 = old live itself (54.157.13.77). No second box.
> 2. Deploy IN PLACE on live, apps wired to the ONE real WP DB; revert =
>    remove nginx includes (old site stays current — one DB, no divergence).
>    RAM check (free -h) before committing the window.
> 3. Postgres REBUILT on live from live's CURRENT WP data (not carried from
>    dev). Re-apply after: Ian+Buck finder opt-ins, karriker fix
>    (bin/fix-divergent-locations.php --apply), QA fixtures.
> 4. Live `/` serves the NEW FRONT PAGE (the bento), both audiences.
> 5. Old BB paths: ONE generic redirect → /hub/ (plus the existing
>    /members/<slug> → /u/<slug> per-profile redirect). Nav already
>    doesn't link them.
> 6. F1 CLOSED on principle: where the member has a dial, THE DIAL DECIDES
>    (public street = public street, no fuzzing). Only the 2 dial-less
>    legacy practice-section locations stay coarse-for-anon until /p/
>    wakes up and they grow a dial. Nothing to build.

Ian 6/12: "we are just about ready to start deploying on the live server…
audit and test." This is that audit + the sequenced plan. Companions it does
NOT duplicate: `profile-app/CUTOVER-CHECKLIST.md` (slice-3 data migration
detail, sponsor store re-apply), `docs/STRANGLER-COORDINATION.md` (contracts),
`docs/OWNERSHIP-CUTOVER-AUDIT.md` (file-ownership collapse).

## 0. Decisions Ian owns before a date is set

1. **Cut window + freeze** — the data plan needs ~1–2 quiet hours.
2. **Live routing for `/`** — dev currently 302s `/` → `/hub/`; the bento
   front page is `/front-page/`. What does live's `/` serve at launch?
3. **BB surface retirement order** — which BuddyBoss pages 302 to the new
   surfaces on day one vs linger.
4. **F1 location-section clamp** — still marked "pending Ian" in the at-cut
   security list; rule or explicitly drop.
5. Confirmed staying OUT of the cut: lg-stripe (PARKED 6/11), guitardle
   (decommissioned, fast-follow), practices `/p/` (dormant).

## 1. What ships (and what doesn't)

Ships: profile-app, archive-poc (front/hub/search), bb-mirror (forums),
events, lg-shared (canonical header), the WP mu-plugins they pair with,
poller lane, nginx snippets (repo: `platform/nginx/`), PG databases
`profile_app` + `looth`, media store `/srv/profile-app-media`, thumb-app.
Total footprint measured on dev today: **< 200 MB** (+~400 MB Postgres
packages). **Live disk VERIFIED 6/12 (Ian ran df): 15.3 GB free of 29 GB
(48% used), R2 uploads mounted off-disk → ~15 GB free post-deploy. Disk is
a settled question — no cleanup needed for the cut.**

Does NOT ship: dev cookie gate (but see 2.4!), mailpit (live mail must go to
real SMTP — verify), chrome-dev, code-servers, team accounts, mint-DEV
conveniences (the jwt signing key itself DOES ship — new pair).

## 2. Audit findings (2026-06-12 sweep)

1. **Configs already env-branch** ✓ — profile-app / archive-poc / bb-mirror /
   events all pick `loothgroup.com` hosts automatically off HTTP_HOST.
2. **Hardcoded-dev bugs found + FIXED in this audit**: the profile-edit
   login interstitial pointed at dev absolutely (would have bounced live
   users to the dev box); reports.php mailed From: noreply@dev. Both now
   derive from LG_PROFILE_APP_HOST.
3. **VERIFY before cut** (couldn't fully resolve from dev):
   - `_materialize.php` / `_sync.php` wp-load path→host maps include the
     LIVE WP path (`/var/www/looth-live`?) — confirm the live entry.
   - **Scheduling — SHORED UP (Ian 6/12 "do we need to shore that up?" →
     yes, done):** dev already ran `bb-mirror-reconcile.timer` (10 min,
     posts-changed sync — my first sweep missed it by name). The gap it
     left (stale person identity/visibility when a member renames or flips
     a dial without posting) is now covered by
     `platform/systemd/lg-person-vis-refresh.{service,timer}` — 15 min,
     installed + proven on dev (journal: 502 resolved). Live install =
     copy both unit pairs + `systemctl enable --now`; no gate env file on
     live. Remaining for live only: weekly geoipupdate (example conf in
     profile-app/deploy/). Context: the request path IS direct WP↔app API +
     push hooks — timers only converge the pull-based caches; Ian runs the
     full backfills at cut.
   - Live SMTP path for profile-app `@mail()` (reports) + sudo-queue pings.
   - Poller lane standing gaps (memory): profile-app nginx route, discovery
     ownership, audit_log key.
4. **nginx snippets reference `$loothdev_is_authorized`** (the dev cookie
   gate) in dozens of `if` guards. Live's server block must define
   `map … $loothdev_is_authorized { default 1; }` (or the includes 403
   everything). Deliberate choice: keep the variable, neutralize it live —
   zero snippet drift between dev and live.
5. **Secrets to CREATE on live (F6 — never copy from dev, dev's are exposed
   to every team chat):** `/etc/looth/jwt-{private,public}.pem` (new pair),
   `/etc/lg-internal-secret`, `/etc/lg-archive-poc-secret`,
   `/etc/lg-profile-app-secret`, WP `profile_hook_secret` option, R2 token
   (live's own, already exists). **PLUS (found 6/12 pm): rotate the Amazon
   SES credentials — dev's FluentSMTP held LIVE SES creds (only the dev
   mail-cap firewall prevented real sends). Dev now defaults to a mailpit
   connection (wp option fluentmail-settings); live keeps SES — verify the
   default connection on live is SES at cut.** Rate-limit conf for /profile-api +
   location-search (checklist item, still unapplied even on dev).
   **These are NOT WordPress salts (Ian 6/12).** wp-config, WP salts, the WP
   DB and the domain are untouched — every member's existing
   `wordpress_logged_in` cookie stays valid through the cut; nobody re-logs
   in. The new apps mint their `looth_id` fast-path token silently off the
   existing WP cookie on first touch (the bounce already live on dev) —
   which is why reconcile-bridge is STEP ONE of the top-off order.
   **No search-replace pass** (asked 6/12): the WP DB doesn't move, and the
   remaining dev-hostname strings in app code are the dev halves of
   env-branches that must stay; the grep audit (clean as of today) is the
   pre-cut check, not a replace.
6. **PG on live**: install PG16, create roles per pool user, apply the two
   databases by RESTORE (see §3), then the ownership question — at cut,
   collapse file+role ownership to www-data and switch peer-auth DSNs to
   password DSNs (OWNERSHIP-CUTOVER-AUDIT.md owns the detail).
7. **GeoLite2-City.mmdb** + geoipupdate cron on live (free MaxMind key).
8. Repo hygiene ✓: nginx snippets, sql/ migrations, all app code tracked;
   buck overlay JS (`/var/www/dev/*.js`) is LIVE-truth on dev — inventory
   which overlays are product (privacy-sheet, directory-desktop, fp pieces,
   app-mobile-fixes) and ship them to live's web root with the pages.

## 3. Data plan — REBUILD Postgres on live from live's WP (RULED 6/12 pm)

(Supersedes this section's earlier carry-dev-PG draft. Rationale: the 6/12
refactor moved every ruling into CODE + COLUMN DEFAULTS — members-only
starting state, one-dial, precision rules — so a fresh build lands them
automatically, with zero dev test residue and maximally fresh member data.)

Build order on live (the same pipeline that built dev), inside the freeze:
1. Apply schema: profile-app sql/ migrations in order; bb-mirror schema;
   archive-poc discovery schema.
2. profile-app: provision/xprofile migrate (`migrate-from-xprofile.php
   --commit`), `snapshot-location-from-bb.php`, geocode pass,
   `reconcile-bridge.php`, `backfill-avatars.php` (live HAS the real BB
   avatar files), social migrate w/ the DM strip-HTML fixes folded FIRST.
3. bb-mirror: forum sync + FULL person resync +
   `backfill-profile-visibility.php`.
4. archive-poc: indexer/backfill (content), comments migrate.
5. RE-APPLIES (the only data the rebuild can't derive): Ian + Buck public
   finder opt-ins (2 UPDATEs), karriker repair
   (`bin/fix-divergent-locations.php --apply`), hand-jigger the 6
   never-geocoded users, QA fixtures (matrix self-provisions).
6. `/srv/profile-app-media` rsync dev → live (15 MB — galleries/QA media;
   avatars regenerate from live's own files).

## 3b. WP-side delta — RULED (Ian 6/12): wire to the EXISTING live WP DB

Clarified 6/12 pm: Ian's question was a duplicate copy OF LIVE's WP DB for
the new apps vs wiring them to the real one ("I trust you" → wired to
existing). A dupe goes stale the moment a member posts; auth cookies, WP
hooks, and the apps' few writes (display-name/author-bio mirrors,
member-sync) must all see the REAL DB — a month on dev proves the wiring.
Dev's WP DB still never ships anywhere. Code + config carry deliberately:

1. **Plugin code**: lg-layout-v2 via the established zip deploy (build in
   /var/www/dev/.well-known/, curl on live, unzip + chown looth-live +
   bundle regen + epoch bump). lg-legacy-import only if conversions run
   from live.
2. **mu-plugins**: from `platform/mu-plugins/` (profile-auth, profile-sync,
   whoami-shim retirement decision rides the consumer-repoint item) + the
   archive-poc feed mu-plugin + showrunner bridge (its own cutover doc).
3. **Converted posts (managed CPTs)**: re-import on LIVE via the conversion
   pipeline (designed for live; dev's duplicate-post quirk doesn't carry).
   Inventory at posts/conversions/.
4. **Options/settings**: scripted `wp option update` list — gate-CTA copy,
   lgms_* member-sync creds (fresh secrets!), sponsor ACF group disable
   (`wp post update 33147 --post_status=acf-disabled`), menus/pages diff.
5. **Form 38 hub-anon snippet is LIVE-only already** — do not port from
   dev; verify it survives plugin updates.

## 3c. Conversion carry — patch by ID, never reconvert (Ian 6/12 pm)

All 674 layout-converted posts are live-origin (IDs predate the snapshot;
conversion added META in place). Carry = `tools/export-v2-layouts.php` on
dev (one JSON bundle, raw meta bytes, content-md5 per post) →
`tools/apply-v2-layouts.php` on live (dry-run default; slug+type guard per
row, byte-exact $wpdb write, drift report for posts edited on live since).
Round-trip proven on dev: 674/674 ok. Regenerate the bundle AT the cut —
do not use a stale one. After applying: archive-poc materializer re-render
+ lg-layout-v2 epoch bump.

GAPS (= content created on live after the snapshot — Ian: "discussions and
maybe a couple videos and an article"): discussions need NO conversion
(forum rebuild carries them). The few videos/articles go through THE SAME
GUIDED CONVERSION PROCESS as the corpus (Ian corrected 6/12: conversion was
a hand-steered lane — imgcap rules, figure numbering, chapter work — NOT a
script): a conversion-lane session on dev, pre-cut, sources pulled per-post
(no DB reload — a full reload re-breaks the 6/11 casualty list), output
QA'd then folded into the bundle. Budget a session for it, not minutes.

GAP WORKLIST (live inventory run 6/12, snapshot ~5/28 — Ian was right that
dev is ~2 weeks old; the '6/11 reload' note in tooling docs was wrong):
  CONVERT (6): videos 71302 (3d-scanning demo), 71434 (council-of-elders
  2.0 apr), 71529 (lfd multiple-shop); imgcap article 71368 (f5-l mandolin
  binding); sponsor-posts 71320 (guitartek), 71540 (total-vise).
  NO WORK: events 71243/71248/71627 (events bridge), weekly_email
  71337/71571 (type never converted), wp_global_styles 71339 (WP internal).
  Sources: wp export --post__in= on live → posts/_inbox/gap-posts.xml.
Gap inventory line (rerun near cut to catch newer content):
  wp db query "SELECT ID,post_type,post_date,post_name FROM wp_posts
    WHERE post_date>'2026-06-10' AND post_status='publish'
    AND post_type NOT IN ('revision','attachment','topic','reply','forum')"

## 4. Cut-day sequence

- **Phase A — prep (days before, zero user impact):** PG + roles + restore
  drill, secrets minted, FPM pools, code deployed from git, media synced,
  GeoLite2, timers installed-but-disabled, nginx snippets staged
  not-included, the `$loothdev_is_authorized` map added, rate limits in.
- **Phase B — freeze + data:** §3 in order; count checks after each step
  (users, bridge rows, person rows, avatar non-gravatar count).
- **Phase C — flip:** include the snippets, apply the `/` routing decision,
  `nginx -t && reload`. CDN/cache purge if fronted.
- **Phase D — TEST GATE (the "test" half of Ian's ask):**
  - `tools/gates/run-all.sh` — ALL gates, one entry (visibility matrix +
    web-craft gate; docs/CRAFT-STANDARD.md). The craft gate needs its PAGES
    host param pointed at live (same LG_MATRIX_HOST pattern).
  - `LG_MATRIX_HOST=https://loothgroup.com php profile-app/bin/visibility-matrix.php`
    — the same 66-assert gate that guards dev, against live, with a live QA
    fixture user. **GREEN or roll back.**
  - `bin/walk-onboarding.sh` against live (fresh-user flow).
  - CUTOVER-CHECKLIST post-cutover smokes (directory anon/authed, private
    profile leak check) + sponsor-store smoke (5 slugs round-trip).
  - Whoami latency (profile-api path ~5 ms, not the WP shim), hub feed,
    finder anon = named opt-ins + dots, front-page tile density, search
    anon-mask spot check, one real report email arrives.
- **Phase E — post:** watch FPM/nginx error logs + [admin-edit] audit lines,
  re-run matrix next morning, then schedule the BB-surface retirements.

**Rollback (Ian's design goal, 6/12: "leave old site hooked up and ready
to nginx conf it back"):** that is EXACTLY what one-DB wiring buys. The BB
surfaces stay hooked up to the same live DB the entire time — un-include
the nginx snippets, reload, and the old site is instantly back AND current
(zero divergence; there was only ever one DB). The apps' few WP writes
(display-name/author-bio mirrors) are BB-compatible. PG keeps running warm
for a re-flip; ACF sponsor re-enable is one wp-cli command. A dupe DB would
have broken this exact property — two diverging copies, no clean revert.

## 4b. RUNBOOK v1 (Ian asked for the step list, 6/12 pm)

Owner tags: [IAN] = on live (dev can't SSH there), [DEV] = prepared here and
handed over (live pulls from a gated URL on dev, or Ian scp's via his
machine), [GATE] = stop unless green.

**Phase A — prep, zero member impact, any day before the window**
 1. [IAN] `php -v` on live — apps need PHP 8.3 + php8.3-{fpm,pgsql,curl,gd,xml,mbstring}; install alongside WP's PHP if it differs.
 2. [IAN] `apt install postgresql` (16), `mysql/psql` sanity.
 3. [IAN] mint secrets fresh (deploy/profile-app-live-bootstrap.sh covers the profile-app set): jwt pair, lg-internal, archive-poc, profile-app, WP profile_hook_secret.
 4. [IAN] GeoLite2 + geoipupdate (examples in profile-app/deploy/).
 5. [DEV] deploy bundle built: app code (4 apps + lg-shared), platform/{fpm,nginx,systemd,mu-plugins}, buck overlay JS inventory, lg-layout-v2 zip, wp-option script, conversions bundle.
 6. [IAN] unpack code to /srv/*, FPM pools in, systemd units staged (disabled), nginx: `map → $loothdev_is_authorized 1`, rate-limit conf, snippets staged NOT included.
 7. [IAN] verify R2: mount present ✓ (df 6/12) — confirm wp-uploads base + thumb-app paths point at it.
 8. [BOTH] restore drill: yesterday's dev PG dumps restored on live, PHP CLI smoke (`php -r 'require config.php;'` per app), then leave in place (refreshed at cut).
 9. [DEV][GATE] dev green: matrix 66/66, walk-onboarding, dev-hostname grep clean.

**Phase B — cut window (freeze, ~1–2h)**
10. [IAN] freeze: announce; optional WP maintenance banner (site can stay up — WP itself isn't changing).
11. [DEV] final `pg_dump` profile_app + looth → hand over; final media rsync bundle.
12. [IAN] restore both DBs over the drill copies; grants per role plan.
13. [IAN] WP-side delta: mu-plugins in, lg-layout-v2 zip deploy + bundle regen + epoch bump, wp-option script, conversions import, sponsor ACF disable.
14. [IAN] BACKFILL RUN (Ian runs, §3 order): reconcile-bridge → xprofile top-off → FULL person resync + profile-visibility backfill → DM fixes then social top-off → avatar backfill (real BB files!) → comments/likes top-off. Count checks after each.
15. [IAN] enable timers (reconcile + person-vis-refresh).

**Phase C — flip**
16. [IAN] include nginx snippets + the `/` routing decision, `nginx -t && systemctl reload nginx`.

**Phase D — TEST GATE**
17. [IAN][GATE] `LG_MATRIX_HOST=https://loothgroup.com php profile-app/bin/visibility-matrix.php` → GREEN or roll back (66 asserts; provisions its own QA fixture).
18. [IAN] smokes: walk-onboarding, sponsor 5-slug round-trip, finder anon (named opt-ins + dots), front-page tile density, hub search anon-mask, whoami ~5ms, one report email arrives via real SMTP.
19. [BOTH] watch FPM/nginx logs ~1h; morning-after matrix re-run.

**Phase E addition (Ian 6/12: "tune the old system down"):** after ~1 week
of soak, RIGHT-SIZE the WP FPM pool (page traffic moves to the new pools;
WP keeps auth/webhooks/admin/forum-engine duty) + prune old-UI-only WP cron.
DO-NOT-DISABLE list — load-bearing for the NEW stack: WP core + login,
membership/Patreon plugins (LGPO + webhooks), BuddyBoss forum machinery
(the Hub posts into it), events bridge mu-plugins, the profile-sync hooks.
The old UI's cost already ≈0 post-redirects (nginx answers before PHP).

**Rollback at ANY point ≥ Phase C:** un-include snippets, reload nginx —
old site is back and CURRENT (one DB, zero divergence). Nothing destructive
touched WP.

## 5. Standing at-cut items folded in (from the memory ledger)

F6 secret rotation (§2.5) · profile-api rate-limit (§2.5) · renderLocation
2-decimal patch (verified present in `Block::locationDisplay`) · www-data
ownership collapse + password DSNs (§2.6) · poller gaps (§2.3) · matrix as
acceptance gate (§4D) · mail cap/iptables rules are DEV-only, don't port.
