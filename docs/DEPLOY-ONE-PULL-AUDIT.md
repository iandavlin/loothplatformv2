# DEPLOY-ONE-PULL — Phase 1 Audit

Lane: `deploy-one-pull` (Fable, keeper-signed CRITICAL, 2026-07-26). Spec: keeper board posts
20:19 + 20:31. Mandate (docs/atlas/OPERATOR.md §3): **a deploy is a single push and a single
pull.** This file is the ground-truth inventory of everything served from outside the serving
checkout on both boxes, written as gathered — never held only in context.

Serving checkout (both boxes): `/home/ubuntu/loothplatformv2-clean`, branch `main`, never
leaves main, never `sudo git` in it. Dev2 audited hands-on. Live audited from keeper's
20:19 evidence + repo cutover docs; every live claim carries `[LIVE-VERIFY]` where the
Phase-4 bash must re-check at run time (no SSH from dev2 to live; board key is board-only).

Legend: `SYMLINK✓` = already deploys via pull. `COPY✗` = forces a manual cp. `BOX-LOCAL✓` =
legitimately box-local (secrets / per-env / WP-install / data / generated). `REV-DRIFT✗` =
served file that exists in NO repo — a pull can never produce it; must be captured INTO the
repo before any symlink conversion.

---

## 1. DEV2 — webroot docroot `/var/www/dev` (nginx `root`; FPM pool looth-dev)

Repo source of truth: `webroot/` (captured from live 2026-06-14; deployed by rsync-copy
`webroot/deploy.sh` — **this script is the root cause of the webroot copy layer**).

### 1a. Top-level served files that should ride the pull — all real COPIES today

All 32 currently md5-identical to `webroot/` (fresh deploy 20:10), so conversion is safe:

- `COPY✗` ×24 JS: app-mobile-fixes, app-settings, bottom-nav, directory-desktop,
  directory-mobile, events-live, events-mobile, gdle-side-art, guitardle-teaser,
  hub-infinite, hub-nojump, hub-polish, loothalong, messenger-sheet, mobile-hub,
  practice-sheet, privacy-sheet, profile-sheet, push, pwa, sponsor-cards, sponsor-sheet,
  sw (all `.js`)
- `COPY✗` mobile-hub.css
- `COPY✗` PHP endpoints: loothalong.php, push-subscribe.php, saved-posts.php
- `COPY✗` manifest.json, favicon.ico, robots.txt, offline.html
- `COPY✗` img.php — md5 == `bb-mirror/web/img.php` (git-homed there per webroot/README; deploy
  from there, not webroot/)
- Subdirs (real dirs, content == repo except REV-DRIFT below): icons/, lib/, push/,
  sponsors-deck/

### 1b. REV-DRIFT✗ — served, in NO repo (capture into `webroot/` first)

- `lib/quill/quill.js` + `lib/quill/quill.snow.css` — **composer-v2 (just shipped) loads
  these**; vendored onto the box only. A clean checkout cannot serve the composer today.
- `icons/badge-pick.png`, `icons/badge-pick-l.png`, `icons/notif-logo.png` — in docroot
  icons/ only (no JS refs found; likely PHP/notification payload refs — capture regardless,
  they are served).

### 1c. BOX-LOCAL✓ (correctly outside the repo)

- WP core (`wp-*.php`, index.php, xmlrpc.php, license.txt, readme.html) + `wp-config.php`
  (secrets) — WP install layer.
- `shop-feed.json`, `shop-vendors.json` — generated (gitignored by design).
- `object-cache.php` (wp-content) — W3TC drop-in, plugin-generated.
- uploads → `/mnt/loothgroup-uploads-dev` (data).

### 1d. Cruft in a public docroot (flag, not deploy-blocking)

- **47 `*.bak*` files at top level** — publicly fetchable stale JS (e.g.
  `hub-polish.js.bak-pre-composer-…`). Craft/security smell; recommend sweep to
  `~/projects/recycle/` in Phase 3 (separate commit, reversible).
- `pwa-test.html`, `tier-mockup.html`, `weekly-patreon-paste.html`, `index.nginx-debian.html`,
  `dev-environment.md`, `cdp_tab.py`, `cdp-launcher/`, `catapult-*` — dev flotsam, some
  publicly fetchable.
- `footer-mockups -> /home/ubuntu/projects/footer-mockups` — a serve path into a projects
  dir (dev-only mockups; note and leave, or retire with Ian's nod).

## 2. DEV2 — wp-content

- mu-plugins: **SYMLINK✓ ~28 files** into checkout. Real-file exceptions:
  - `COPY✗` buddyboss-performance-api.php, burst_rest_api_optimizer.php, lg-logout.php —
    md5 == repo `platform/mu-plugins/` copies → straight symlink conversions.
  - `COPY✗` lg-patreon-stripe-poller.php — md5 == repo top-level copy (loader for the
    symlinked `lg-patreon-stripe-poller/` dir) → symlink.
  - `COPY-DIFF✗` lg-dev-mail-containment.php — box (Jul 13) ≠ repo (Jun 30). Box is newer;
    capture box → repo, then symlink. Safe to track: hard-gated on `LG_ENV` internally
    (no-ops on live).
  - `BOX-LOCAL✓` looth-vendor/ (vendored libs dir — leave; not a deploy surface today).
- plugins: `SYMLINK✓` lg-layout-v2, lg-legacy-import, **lg-snippets (whole dir — so dev2's
  86.php rides the pull already; the 86.php copy is a LIVE-only defect)**, lg-weekly-digest.
  Non-lg plugins = WP-installed `BOX-LOCAL✓`. Deprecated `.bak`/`.deprecated` plugin dirs =
  cruft.

## 3. DEV2 — /srv

- `SYMLINK✓` archive-poc, bb-mirror, events, lg-layout-v2, **lg-shared (whole dir — dev2 has
  NO site-header.php copy problem; that too is LIVE-only)**, membership-pages, profile-app.
- `BOX-LOCAL✓` (data / out-of-repo apps, separate deploy paths — out of scope, documented):
  lg-push (runtime of repo lg-push? code also in repo — flag for a later lane),
  lg-stripe-billing (Slim app lives in /srv per docs), lg-sudo-queue, profile-app-media,
  profile-app-message-media, thumb-app.

## 4. DEV2 — nginx `/etc/nginx`

- sites-enabled: dev2.conf, buck-dev2.conf, default → sites-available.
- `COPY-DIFF✗ (THE drift)` sites-available/dev2.loothgroup.com.conf — **hand-edited real
  file**, was symlinked until 2026-07-04 (`.bak-gatearm-20260704` symlink still present).
  Divergence from tracked `platform/nginx/dev2.loothgroup.com.conf` is ~23 lines but only
  **three functional deltas, all PER-ENV**: (1) gate-arm `if ($loothdev_is_authorized = 0)
  return 403;` (2)+(3) cookie `Domain=.dev2.loothgroup.com` vs repo's `.dev.loothgroup.com`
  in /claim + /claim-tester. Rest is comment/ordering drift. **The repo copy's cookie domain
  `.dev.loothgroup.com` is stale for BOTH boxes** (dev.loothgroup.com is retired; live wants
  `.loothgroup.com` [LIVE-VERIFY]) — per-env include is the cure.
- `SYMLINK✓` buck-dev2.conf → repo (restored 20:12).
- `BOX-LOCAL✓` default, dev2-acme.conf (distro/ACME infra); `buck.dev2.loothgroup.com.conf`
  (dots) real file NOT enabled = parked cruft.
- conf.d: `SYMLINK✓` 00-lg-write-freeze-map, 99-lg-tuning, lg-microcache.
  `BOX-LOCAL✓ (per-env+SECRET)` loothdev-auth.conf — armed-gate variant carrying literal
  token values in maps (repo copy = gate-free live posture). Correct category, formalize in
  Phase 2 (dev2 gate ON / live gate OFF is exactly keeper's per-env example).
- snippets: `SYMLINK✓` lg-shared, lg-shared-buck, lg-write-freeze, all strangler-*-buck,
  strangler-archive-poc/bb-mirror/events/membership.
  - **`LANDMINE✗` strangler-profile-app.conf → `/home/ubuntu/worktrees/dmv-native/…`** — a
    serving conf symlinked into a LANE WORKTREE (since Jul 22), violating OPERATOR.md §3
    ("never leave a serve symlink pointed at a lane worktree"). Content currently
    md5-identical to main's copy (hand-synced; the worktree shows it locally modified), but
    a rebase/branch-switch/`rm -rf` of that worktree changes or breaks the serving config
    silently. **Highest-priority repoint.**
  - `BOX-LOCAL✓ (SECRET)` loothdev-tokens.conf (the blessed pattern, keep).
  - `BOX-LOCAL✓` fastcgi-php.conf, snakeoil.conf (distro); preview-buck-*.conf (buck preview
    machinery; only the parked buck.dev2 vhost includes it) = parked.
  - Cruft: assorted `.bak-*` files/symlinks in sites-available, conf.d, snippets.

## 5. DEV2 — FPM / systemd / sudoers / per-env plumbing

- FPM pool.d: **real files, diffs vs repo `platform/fpm/` are PURE PER-ENV** (pm.max_children
  sizing 2→8/10/12, `env[LG_*_ENV]=dev`, `env[LG_*_PUBLIC_HOST]=dev2…`, looth-dev HOME).
  Repo copies = live posture. Category: per-env `BOX-LOCAL✓` today; Phase 2 decides whether
  to track per-box posture files (they are not secret). Plus ~12 `.bak-cutflip/.dev-preflip/
  .live-posture` variants = cruft. `99-lg-tuning.ini` (opcache) is box-local, not in repo —
  capture.
- opcache (matters for "is a reload part of a deploy"): `validate_timestamps=1`,
  `revalidate_freq=2` → **a pull through STABLE symlinks propagates PHP in ≤2s with NO fpm
  reload**. The fpm-reload deploy step is only truly needed when a symlink is REPOINTED
  (realpath/opcache pinning — the known overlay gotcha) or a pool conf changes. Live opcache
  settings unknown `[LIVE-VERIFY]` — if live has validate_timestamps=0, that is the one
  honest reason live's deploy keeps an fpm reload.
- systemd: 8 units (bb-mirror-reconcile, lg-person-vis-refresh, lg-wp-cron,
  prune-notifications × .service/.timer) — `COPY-SAME` vs repo. Units change rarely and
  need daemon-reload anyway; Phase 2: keep as copies + add drift detection to the deploy
  path rather than symlink /etc/systemd into a home dir.
- sudoers: buck, lg-secrets (installed by tracked installer) — `BOX-LOCAL✓`.
- `/etc/looth/env` — **the established per-env mechanism** (LG_ENV, LG_PUBLIC_HOST,
  LG_WP_PATH, LG_WP_USER, DBs, LG_GATE_COOKIE…). PHP already reads it. nginx cannot —
  hence the nginx-side per-env include design in Phase 2.

## 6. Cache-busting (`?v=`) — current state

Standing rule (CLAUDE.md quality gates): every loader carries `?v=filemtime`. Reality:

- `lg-shared/site-header.php` → social-modals.js: **`?v=filemtime` ✓** (the pattern exists
  and works, 1y-immutable cache on /lg-shared/ per lg-shared.conf).
- nginx vhost `sub_filter` injects `<script src="/pwa.js?v=5">` — **hand-bumped in a conf**
  → every pwa.js change = conf edit + nginx reload. VIOLATION (this is keeper's "deploy
  edits a conf" step).
- `pwa.js` internally injects ~20 layers with hand-bumped `?v=N` (hub-polish `?v=227` is
  keeper's 226→227 bump today; tracked, so it rides the pull, but it is still a manual bump
  that people forget — and the docroot generic static block serves 1y-immutable, so a missed
  bump = stale JS for a year). VIOLATION of the standing rule.

## 7. Manual deploy steps today (the six-step live bash) — and why each exists

| # | Step | Why it exists | Killed by |
|---|------|---------------|-----------|
| 1 | `git pull` in checkout | the one true step | stays (THE deploy) |
| 2 | `systemctl reload php8.3-fpm` | belt-and-braces; on dev2 (validate_timestamps=1) only needed for symlink repoints / pool-conf changes, which a normal deploy no longer does. Live `[LIVE-VERIFY]` opcache | verify live opcache; if =1, drop from routine deploy |
| 3 | `cp`/rsync webroot files (webroot/deploy.sh) | 24+ docroot files are COPIES | §1a symlink conversion |
| 4 | nginx conf edit (`?v=` bump in sub_filter) | pwa.js?v= is hand-carried in the vhost | filemtime-derived versioning (Phase 2) |
| 5 | `nginx -t && reload` | consequence of 4 (and real conf changes) | only when confs genuinely change (rare, not per-deploy) |
| 6 | archive-poc re-index loop | today's deploy changed indexer.php — data migration, not a deploy step | out of scope; document as conditional post-deploy task |

## 8. LIVE — evidence (keeper 20:19/20:10/20:13) + repo docs; all `[LIVE-VERIFY]`

- `SYMLINK✓` mu-plugins → checkout (pull deploys them).
- `SYMLINK✓` main nginx conf symlinked into the checkout (keeper 20:13: same
  dangling-config trap as dev2 — never detach live's checkout). **BUT: no live vhost
  (`loothgroup.com.conf`) has EVER been tracked in this repo** (`git log --all` proves it).
  So live's symlink necessarily targets an UNTRACKED file inside live's checkout — meaning
  vhost changes do NOT ride a push+pull (keeper hand-edited live's conf today: step 4), and
  the file is exposed to `git clean -fd`. **Finding L-1, must fix in Phase 4: capture live's
  vhost into the repo (tracked) with per-env includes.**
- `COPY✗` **24 top-level webroot .js/.css real copies** (same shape as dev2 §1a; put there
  by webroot/deploy.sh).
- `COPY✗` `/srv/lg-shared/site-header.php` — while sibling site-header.css +
  social-modals.js are per-FILE symlinks (mixed dir). Dev2 solved this with a whole-DIR
  symlink (Jul 23) — live should match.
- `COPY✗` `wp-content/plugins/lg-snippets/snippets/86.php` — dev2 solved via whole-dir
  plugin symlink; live should match `[LIVE-VERIFY: whether live's lg-snippets is a real dir
  with per-file links or a copy]`.
- `BOX-LOCAL✓` expected: wp-config.php, uploads, gate OFF posture (no loothdev gate), FPM
  pools live posture, `/etc/looth/env` with live values, secrets.
- Unknowns for the Phase-4 bash to verify before acting: live docroot path; lg-shared dir
  shape; lg-snippets shape; opcache validate_timestamps; whether live vhost symlink target
  is tracked/untracked; REV-DRIFT files on live's docroot not in webroot/ (bash must
  md5-compare EVERY file it is about to replace and stop on any mismatch — live may carry
  newer bytes than the repo, the reverse of dev2).

## 9. Root causes, ranked

1. **webroot/deploy.sh is an rsync-copy deployer** — institutionalizes the copy layer (§1a).
2. **Hand-carried `?v=` in a conf + in pwa.js** — forces conf edit + reload per deploy (§6).
3. **Per-env deltas live as hand-edits to tracked files** (dev2 vhost) instead of behind
   includes — so the tracked file had to be forked (§4).
4. **No capture discipline for box-vendored assets** (quill, icons) — REV-DRIFT (§1b).
5. **Live vhost never tracked** (L-1, §8).
6. **One serve symlink into a lane worktree** (strangler-profile-app.conf, §4) — standing-rule
   violation, unrelated to deploy steps but a serve-integrity landmine this lane must clear.

---

## 10. Phase 3 log (as it happens)

- **Mutation 1 DONE 20:52** — strangler-profile-app.conf snippet repointed dmv-native
  worktree → serve checkout (bytes identical c6088138 both sides, recorded in
  ~/deploy-backups/deploy-one-pull-20260726/PRESTATE.txt). Proof: nginx -t OK, **full
  nginx restart**, smokes 200 (/, /hub/, /directory/members/, /u/mike,
  /profile-api/v0/whoami; /members/x 301 as designed; /billing/ 403 = Slim app's own
  token auth, pre-existing). Rollback stays: `sudo ln -sfn
  /home/ubuntu/worktrees/dmv-native/platform/nginx/strangler-profile-app.conf
  /etc/nginx/snippets/strangler-profile-app.conf && sudo systemctl restart nginx`.
- **Lane commits b1d7f84 (5)** pushed, validated pre-merge: new vhosts pass sandboxed
  `nginx -t` (only the pre-existing duplicate-MIME warn); pwa.js passes `node --check`;
  pwa-loader.php `php -l` + CLI smoke emits the LG_V map; installer dry-run against a
  fake webroot exercised all five cases (new-link / correct-link no-op / wrong-target
  repair / drift skip+exit 2 / identical convert+backup) correctly.
- **Gates**: `tools/gates/run-all.sh` RED for the pre-existing box-wide reason (gates
  2/3/5 hardcode the retired dev.loothgroup.com vhost path for token reads — broken
  since 6/30, tracked by the gate-env lane @e4f3e07 unmerged). Gate 4, the only live
  signal, is GREEN. Nothing in this lane touches what 2/3/5 would measure until the
  merge deploys it; re-run owed post-deploy regardless of their env failure.
- **Coordination**: hub-polish.js docroot currently carries the composer-link-insert
  overlay (f3c8a43b) for Ian's phone test — the installer will SKIP it by md5-gate;
  its conversion happens after that test resolves (or after the branch merges, when
  repo bytes == overlay bytes).

### Phase 3 completion (merge night, 21:09–21:30)

- Keeper merged @1144d81 (dev2 only; live gated behind the 5-rung ladder in keeper's
  21:09 post). Conversions executed: 32 webroot links + icons/push/sponsors-deck dirs +
  img.php; 7 FPM pools + 99-lg-tuning.ini; 5 mu-plugins; vhost flipped to tracked file.
  hub-polish.js SKIPPED (Ian's composer overlay f3c8a43b — protected by the gate as
  designed); lib/ held pending the quill-cruft decision.
- **Real-run installer finds, fixed @d83fac0**: (1) pipefail killed the dir-skip report
  pipeline mid-run; (2) the md5 gate needed the historic-match extension — "box is
  BEHIND repo" (safe: converting deploys main; on live that is EVERY file) vs "bytes
  main never had" (overlay/reverse-drift: skip). Re-verified on the rig: behind-repo
  converts, never-committed skips, dir-skip no longer aborts.
- **Restart proof**: full nginx AND php8.3-fpm restarts survived; smokes green; gate
  still armed; img.php resizes (200 webp; the 302 scare was a wrong test param — it is
  `?s=`, not `?src=`).
- **RUNG 1 — ACCEPTANCE PASSED**: probe commit → main (cc06477) → `git pull --ff-only`
  in the serve checkout was the ONLY command. mobile-hub.css wire == repo == HEAD
  (9523064f ×3, probe visible in served bytes); LG_V bumped 1785025695→1785100816 by
  itself; lg-logout.php through symlink == HEAD blob. No cp, no conf edit, no reload.
- **RUNG 3 — ROLLBACK REHEARSED FOR REAL**: banked vhost rollback executed (hand file
  restored, full restart) → old posture proven serving (static pwa.js?v=5 inject,
  overlay-nocache back, site 200s — and the v() fallback design carried the NEW pwa.js
  safely under the OLD vhost, proving the degradation path); re-flip + full restart →
  new posture re-proven (LG_V, bare inject, immutable). Overlay f3c8a43b intact
  throughout.
- **Open items**: rung 2 soak overnight (box left quiet); hub-polish.js conversion after
  Ian's test resolves; lib/ after the quill call; rungs 4–5 are live and keeper/Ian-run.

### Live audit results (keeper-run read-only pass, 2026-07-26 ~22:20)

- **Live's serving vhost is NAMED dev2.loothgroup.com.conf** (dev2→live promotion
  leftover), already symlinked sites-enabled → sites-available → the checkout — i.e.
  live serves the TRACKED dev2 vhost file, so §8's L-1 reads differently: the vhost
  IS tracked and pull-deployable; the risk was the FILENAME assumption in tooling +
  the fact that vhost content changes ride into live on its next pull. Both fixed:
  (1) live-one-pull.sh + install-config-symlinks.sh now resolve the serving vhost by
  server_name match on LG_PUBLIC_HOST and FATAL when not exactly one; (2) the
  /pwa.js location in the shared tracked vhost is now SELF-FALLBACK (try_files →
  @pwa_static) so a live pull BEFORE install-symlinks.sh links pwa-loader.php serves
  static pwa.js on v() fallbacks instead of 404ing the bootstrap site-wide.
- **Live opcache: validate_timestamps=On, revalidate_freq=2** (keeper-confirmed) →
  a routine live deploy is LITERALLY the pull; no fpm reload. Baked into
  live-one-pull.sh apply output; lg-deploy was already conditional.
- **Zero drift on live**: all 24 webroot copies + /srv/lg-shared/site-header.php +
  lg-snippets/86.php are byte-identical to their commits; 3 files change content at
  cutover, the rest are pure conversions. Ian has taken a machine snapshot of live
  (the level-below-ROLLBACK.sh revert).
- dev2: hub-polish.js converted after the composer merge (repo==docroot f3c8a43b);
  docroot top level now has ZERO real js/css files; lib/ still pending the quill call.
