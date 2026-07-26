# DEPLOY-ONE-PULL — Phase 2 Design: the single-pull end state

Companion to DEPLOY-ONE-PULL-AUDIT.md. Goal state: **a routine deploy is `git push` (dev
side) + `git pull` in the serving checkout (box side). Nothing else.** nginx/FPM are touched
only when nginx/FPM *config* genuinely changes — never for app or asset deploys.

## Principles

1. **If it serves, it symlinks into the checkout.** (Ian's deliberate coupling, keeper
   20:13.) The only exceptions are keeper's two blessed categories:
   - **Secrets** — box-local, never tracked (gate tokens snippet, wp-config, /etc/looth/env).
   - **Per-env values** — handled by *per-box tracked files* where the file is inherently
     per-box (each box's vhost, each box's FPM posture), and by *box-local includes* only
     where a value is secret or genuinely untrackable. Per-env ≠ secret: a cookie domain
     may be tracked in a dev2-named file. This satisfies the intent (deploy=pull, not
     one-identical-file-everywhere) with strictly less machinery.
2. **Versions derive from the filesystem, not from humans.** `?v=filemtime`, computed at
   request time by PHP. No deploy-time generation, no git hooks, no conf edits.
3. **Never repoint a symlink as part of a deploy.** Symlinks are installed once (bootstrap);
   deploys only change file *content* behind stable links. This is what makes opcache
   (validate_timestamps=1, freq=2s) propagate PHP in ≤2s with no FPM reload — and removes
   the realpath-pinning trap entirely from the deploy path.

## D1. Webroot static layer: copies → symlinks

Every repo-managed file in `webroot/` gets a same-name symlink in the docroot:
`/var/www/dev/<f> -> /home/ubuntu/loothplatformv2-clean/webroot/<f>` (top-level files
individually; `icons/`, `lib/`, `push/`, `sponsors-deck/` as whole-dir symlinks, matching the
lg-shared / lg-snippets precedent). Same for `img.php -> …/bb-mirror/web/img.php` and the 4
mu-plugin copies → `platform/mu-plugins/`. Ownership: symlink owner is irrelevant; targets
are world-readable and the box already serves checkout files via nginx and FPM (lg-shared,
mu-plugins) — proven traversal.

`webroot/deploy.sh` (the rsync-copier that institutionalized the copies) is **replaced** by
`webroot/install-symlinks.sh`: idempotent bootstrap, run once per box (and once per new
file). Per file: md5(docroot) vs md5(repo) — **on mismatch it does NOT convert; it reports
and stops** (protects against reverse-drift, esp. on live where the box may carry newer
bytes than the repo). Originals are moved to a timestamped backup dir under
`/home/ubuntu/deploy-backups/` (NOT under the docroot — no publicly fetchable backups; that
is how we got 47 public .bak files). The script prints the exact rollback line per file:
`mv <backup>/<f> /var/www/dev/<f>`.

Pre-capture into the repo (lane commits, keeper merges BEFORE conversion):
- `webroot/icons/badge-pick.png, badge-pick-l.png, notif-logo.png` (box-only; unreferenced
  in any greppable code but possibly referenced from DB-stored push payloads — captured as
  insurance so the whole-dir icon symlink loses nothing).
- `platform/mu-plugins/lg-dev-mail-containment.php` — box copy (newer) wins; LG_ENV-gated.
- NOT captured: `lib/quill/` (unreferenced — composer loads Quill from CDN; goes to cruft),
  generated `shop-*.json`, WP core, `*.bak*`.

## D2. Cache-bust: `?v=` derives from filemtime (kills conf edits AND ~20 no-cache
revalidations per page)

Today: sub_filter carries `/pwa.js?v=5` (hand-bumped conf edit), pwa.js carries 20 hardcoded
`?v=N` (hand-bumped, forgettable — so dev2's vhost serves all 20 overlay files `no-cache` as
a workaround, ~20 conditional requests per page; live still trusts `?v=N`, which is why a
missed bump = year-stale JS and why keeper hand-bumped 226→227 today).

End state:
- **New `webroot/pwa-loader.php`** (tracked). nginx `location = /pwa.js` becomes a FastCGI
  pass to it (looth-dev pool). It emits `window.LG_V = { "<file>": <filemtime>, … }` for
  every `*.js/*.css` under `webroot/` (filemtime through the symlink resolves the checkout
  target — a pull updates it instantly), then streams `pwa.js` after it. Sends
  `Cache-Control: no-cache` + strong ETag = hash of the version map, answers
  `If-None-Match` with 304 → steady-state cost is ONE tiny conditional request per page
  (same as today's bare pwa.js), and it self-busts the moment any asset changes.
- **pwa.js**: each `inject('id', '/x.js?v=N')` becomes `inject('id', '/x.js?v=' + v('x.js', N))`
  where `v(f, fallback)` reads `window.LG_V`; the old hardcoded number stays as the fallback
  so a loader failure degrades to exactly today's behavior, never to `?v=0`-forever-stale.
- **sub_filter**: `/pwa.js?v=5` → bare `/pwa.js` (still no-cache at the nginx level for the
  304 path). **This is the last conf edit that URL ever needs.**
- **Overlay JS caching restored to 1y-immutable**: the `overlay-nocache` regex block is
  removed; versioned URLs make immutable safe again. Net perf: ~20 revalidation requests
  per page → 0 (dev2), and live gets auto-busting it never had.
- `site-header.php`'s `?v=filemtime` for social-modals.js already complies — untouched.
- sw.js stays version-free (service-worker update semantics are byte-diff on the registered
  URL; register keeps `/sw.js`), served no-cache via existing catch: verify at implement time;
  add `location = /sw.js { no-cache }` if the immutable catch-all would pin it.

## D3. nginx: dev2 vhost returns to a tracked symlink

The hand-edited real file's three functional deltas are all inherently *dev2* values, and
`dev2.loothgroup.com.conf` is inherently a dev2 file — so they get **committed into the
tracked vhost** (gate-arm `if`, cookie `Domain=.dev2.loothgroup.com` ×2), keeping the repo
copy's superior comments for the /billing and /directory blocks. Then
`sites-available/dev2.loothgroup.com.conf` becomes a symlink to the tracked file again
(hand file preserved as a dated .bak outside sites-available — nginx has no sites-available
include risk, but tidiness).

- Box-local (unchanged, documented): `conf.d/loothdev-auth.conf` (armed gate — carries
  literal token values = SECRET category; repo copy stays the gate-free live posture),
  `snippets/loothdev-tokens.conf` (secrets), distro files, ACME.
- **Landmine repoint**: `snippets/strangler-profile-app.conf` symlink moves from the
  dmv-native worktree back to the serve checkout (bytes identical today — zero behavior
  change, pure integrity fix).
- Same pwa/sub_filter changes applied to the tracked buck vhost (it carries its own
  `?v=4`).
- The dev2-gate `if` requires `$loothdev_is_authorized`, defined by the box-local
  loothdev-auth.conf — a fresh box needs that include before nginx -t passes (bootstrap
  step, documented; same class as the tokens include today).

## D4. FPM: dev2 posture becomes tracked, pools symlink

New `platform/fpm/dev2/` (pool confs with dev2 sizing/env — captured from the box) +
`platform/fpm/dev2/99-lg-tuning.ini`. `/etc/php/8.3/fpm/pool.d/{archive-poc,bb-mirror,
events,membership,profile-app,tool-dev,looth-dev}.conf` and
`conf.d/99-lg-tuning.ini` become symlinks into it. Existing `platform/fpm/*.conf` (live
posture) untouched — Phase 4 moves live to `platform/fpm/live/` the same way. Not secret,
inherently per-box → per-box tracked files, principle 1. www.conf (stock) and
lg-billing-dev.conf (billing app deploys separately, out of repo) stay box-local.

## D5. What stays manual, honestly

- **nginx conf changes**: `sudo nginx -t && sudo systemctl reload nginx` — only when
  `platform/nginx/` actually changed. Not part of a routine deploy.
- **FPM pool/ini changes**: `sudo systemctl reload php8.3-fpm` — only when `platform/fpm/`
  changed. Not part of a routine deploy. (Content changes to PHP *code* need nothing.)
- **systemd units**: stay real copies (not symlinking /etc/systemd into a home dir); rare,
  need daemon-reload anyway.
- **Data migrations** (e.g. today's archive re-index): never a deploy step; belongs to the
  change that needs it.

`platform/bin/lg-deploy` (tracked; `/usr/local/bin/lg-deploy` symlink installed once) wraps
this honestly: `git -C ~/loothplatformv2-clean pull --ff-only` (refuses on non-main or dirty
tree), then diffs `HEAD@{1}..HEAD`: if platform/nginx changed → nginx -t + reload; if
platform/fpm changed → fpm reload; if platform/systemd differs from /etc → WARN with diff;
prints served-surface md5 spot-checks. **Routine case: the pull is the only action taken.**
Ian's one-liner is `lg-deploy` (or literally the bare pull — acceptance is proven on the
bare pull).

## D6. Acceptance proof plan (Phase 3, in order, each with a stated rollback line)

1. Snapshot: md5 of every file to be touched + tar backup to ~/deploy-backups/.
2. Repoint strangler-profile-app snippet → checkout. `nginx -t` + **full nginx restart** +
   smokes (this restart also re-proves the 20:13 outage class is clear).
3. Lane commits: icon/mu-plugin captures, pwa-loader.php + pwa.js `v()`, vhost consolidation
   (dev2 + buck), fpm dev2 posture, install-symlinks.sh, lg-deploy. Present commits +
   diffstat to keeper → **keeper merges to main** → serve checkout pulls (itself a deploy).
4. Run install-symlinks.sh (webroot + mu-plugins + img.php + fpm links). `nginx -t`, then
   **full `systemctl restart nginx` AND `systemctl restart php8.3-fpm`**, then smokes
   (/, /hub/, /directory/members/, /billing/, /u/<slug>, /pwa.js 200+ETag, overlay JS 200).
5. THE TEST: land a trivial change in main (visible string/comment in a webroot asset + a
   PHP surface), **run ONLY `git pull` in the serving checkout**, then show docroot-served
   md5 == repo md5 for every affected surface + curl proof of new bytes + LG_V version
   changed — no cp, no conf edit, no reload.
6. Cruft sweep (separate, reversible commit-less box op): 47 docroot .bak files + lib/quill
   + parked confs → ~/projects/recycle/deploy-one-pull-<date>/ (keeper-visible manifest).

## D7. Live (Phase 4 preview — bash for Ian, keeper-reviewed, self-auditing)

Same end state via one bash that: (a) audits before acting (md5 gate per file, aborts on
reverse-drift and reports instead of clobbering); (b) fixes the audit's live-only defects
(site-header.php copy → whole-dir lg-shared symlink like dev2; lg-snippets 86.php → whole-dir
plugin symlink; 24 webroot copies → install-symlinks.sh); (c) captures live's untracked vhost
(L-1) to a file keeper commits as `platform/nginx/loothgroup.com.conf`, then flips the
symlink to the tracked path on a second short pass after the commit lands; (d) verifies live
opcache validate_timestamps (decides whether live's routine deploy keeps an fpm reload);
(e) emits a complete rollback script as it goes; (f) ends with nginx+fpm RESTART + smokes.
