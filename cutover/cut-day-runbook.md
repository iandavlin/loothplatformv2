# MASTER CUT-DAY RUNBOOK (live-deploy lane)

> **MODEL SUPERSEDED 6/12 pm (Ian's decision list): there is NO second box —
> ip-172-31-45-223 IS old live. Ruled: IN-PLACE / ONE-DB on the existing
> live box; canonical cut doc = docs/LIVE-DEPLOY-PLAN.md (six rulings at
> top).** This file's LIVE-INVENTORY, BATCH results, env-parameterize fixes
> and the 6/12 addendum items remain valid reference; the new-box/DNS-swing
> phases are not pursued.

End-to-end ordered playbook for the cut. Each step tagged **[DEV✓]** (verified on
dev) / **[LIVE]** (Ian runs on the new/old box) / **[OPEN]** (decision needed).
Model = **ADOPT live's DBs** onto a new self-contained box, then flip traffic.

> POSTGRES SOURCE — DECIDED (Ian leaning REBUILD, 2026-06-09): **Live has NO
> Postgres** (strangler PG is dev-built), so at cut Postgres is **REBUILT from the
> adopted live WP DB** via each lane's migration/sync (bb-mirror forum sync +
> person-resync, archive-poc indexer/backfill, profile-app xprofile + social
> backfill) — authoritative, matches live exactly. (Fallback if rebuild is too slow
> on cut day: carry dev's PG — but rebuild is the plan.) See Phase 5.

---

## PHASE 0 — Pre-cut readiness (done ahead, on dev/repo)
- **[DEV✓]** Everything-in-git: all 5 apps + confs single-source git-native;
  mu-plugins + FPM captured; symlink farm built + drift-guarded.
- **[LANE]** Env-parameterize the flagged lane code before cut (else breaks on live):
  `lg-article-materializer` LG_DASH_THEME_SNAPSHOT → /srv path; `bb-mirror-sync`
  BB_MIRROR_SYNC_HOST → env/site-derived. (Routed to archive-poc + bb-mirror lanes.)
- **[LANE]** Snippets keep/drop = DROP ALL (Ian); theme = DROP. Lane code env-clean.
- **[LIVE]** Run `cutover/live-recon-snippets-plugins.sh` read-only on live →
  results feed final plugin/theme keep-drop. (Coord-reviewed PASS; output SENSITIVE.)

## PHASE 1 — Provision the new box  **[LIVE]**
- Box size DECIDED BY THE TRIAL (see rehearsal): start on the **m7a.medium**
  loothtool box; the PG-rebuild peak-RAM measurement says whether the real cut
  stays m7a.medium or bumps to **m7a.large**. Add swap + small-tune regardless.
- Packages: `nginx php8.3-fpm php8.3-pgsql php8.3-mysql mariadb-server postgresql
  redis git wp-cli rclone` (⚠️ php8.3-pgsql is easy to forget — breaks every strangler).
- Create FPM pool OS users: archive-poc, bb-mirror, profile-app, events, membership,
  looth-dev. Create PG roles (same names, peer auth) + looth-dev write role.
- `git clone` the monorepo to /home/ubuntu/projects (or chosen root).
- **Separate clones (not in monorepo):**
  - **thumb-app** — `git clone iandavlin/thumbnail-gen-editor` → `/srv/thumb-app`,
    checkout branch `feature/per-user-namespacing` @ `e31f3b5` (pinned). Needs its
    own deploy key/remote on the box (dev uses SSH alias `github-thumbnails`).
- **composer install** for folded PHP apps that vendored deps: `lg-stripe-billing`,
  `lg-push` (vendor/ is gitignored — regenerate on the box, inside the repo dir so
  the symlink target is complete).

## PHASE 2 — Files via symlink farm  **[DEV✓ pattern]**
- Run `cutover/symlink-farm.sh --apply` on the new box. Order inside is correct:
  plugins → mu-plugins → **apps (/srv/<app>) → nginx** (so /srv paths resolve before
  nginx loads). Drift-guarded (won't deploy stale/mismatched).
- WP plugins symlink into wp-content/plugins; mu-plugins flat into wp-content/mu-plugins
  (excludes lg-user-audit/retired/3rd-party); webroot assets → docroot.
- `nginx -t && systemctl reload nginx`; `systemctl reload php8.3-fpm`.

## PHASE 3 — ⭐ ROLLBACK PREP (before touching anything live-facing)  **[LIVE]**
- Take the **deliberate, immediately-pre-cut snapshot of BOTH DBs**:
  `mysqldump` the live WP DB; `pg_dump` the PG DBs (once they exist on the new box).
- **Test-restore the snapshot ONCE** to a scratch DB to prove it's good. This snapshot
  **IS the rollback** (replaces the old frozen-box model). Restore = downtime +
  loss of writes-since-snapshot — acceptable for a planned cut.

## PHASE 4 — Adopt the WP database + wp-config  **[LIVE]**
- Import the live WP **MariaDB** dump into the new box's **local MariaDB**.
- Write the new box's **wp-config.php** = **live's wp-config with the AUTH salt block
  kept VERBATIM**, only the **DB-connection lines swapped** for the new local DB.
  (Salt ceremony collapsed per Ian: keep the block, don't regen, don't hand-extract 8 lines.)
  ⭐ This makes logged-in consistency AUTOMATIC: real salts + real session_tokens.
- **⚠️ DO NOT run a domain search-replace.** siteurl stays loothgroup.com untouched
  (COOKIEHASH = md5(siteurl); changing it = mass logout). **[DEV✓** mechanics].

## PHASE 5 — Build Postgres from adopted WP data  **[LIVE]** (REBUILD — Ian's lean)
- Apply schema + DDL/extensions/grants **[DEV✓ — exact defs verified]**:
  - `CREATE EXTENSION pg_trgm` + 4 GIN trgm indexes (forums.topic.title/author_name,
    discovery.content_item.title/author_name).
  - profile_app.users.discussion_visibility + forums.person.discussion_visibility
    (text NOT NULL DEFAULT 'member' CHECK public|member — singular 'member').
  - forums.topic/reply.is_anon BOOLEAN; discovery.comments.edited_at TIMESTAMPTZ.
  - GRANTs: bb-mirror SELECT on discovery.comments + content_item; looth-dev writes;
    archive-poc schema owner; profile-app SELECTs. (peer auth, unix socket.)
- Run each lane's migration/sync from the adopted WP data: bb-mirror forum sync +
  person-resync; archive-poc indexer/backfill; profile-app xprofile + social backfill.

## PHASE 6 — Secrets (provision on box; NEVER git)  **[LIVE]**
- wp-config AUTH salt block — carried in Phase 4 (kept from live's wp-config).
- /etc/lg-internal-secret, /etc/lg-archive-poc-secret, /etc/lg-profile-app-secret,
  /etc/lg-events-db, /etc/lg-membership-db, /etc/looth/jwt-*.pem, /etc/lg-vapid/*.
- `setfacl -m u:profile-app:r /etc/lg-internal-secret` (read gotcha).
- Stripe/Patreon creds — ship DORMANT (no creds) per coord §3h.
- **`/srv/lg-stripe-billing/.env`** — provisioned on box (gitignored), holds
  STRIPE_SECRET_KEY/STRIPE_WEBHOOK_SECRET. Cut ships SANDBOX/test keys; swap to
  `sk_live_`/`pk_live_` at Stripe-enable (PROD-CUTOVER.md), not at cut.
- rclone → LIVE bucket (dev token is dev-scoped/IP-locked).

### App-owned media DATA (not git, not secret — migrate as data)  **[LIVE]**
- `/srv/profile-app-media` (15M: avatars/banners/gallery/resumes) — app-owned user
  media store. **rsync the data dir to the new box** (NOT git). Migrate same as any
  user-data dir; verify avatars resolve post-cut.
- `lg-push` — CODE now in monorepo (deploys via pull); runtime needs: cron entry +
  `/etc/lg-vapid` (secrets list) + DB access. `lg-sudo-queue` = dev-only coordination
  infra (excluded, like chrome-dev.service) — does NOT carry to live.

## PHASE 7 — DB-state that doesn't ride the clone  **[LIVE]**
- Theme: `wp theme activate twentytwentyfive`; do not carry BB child/parent.
- Snippets: drop all wp_snippets; code-snippets plugin droppable. lg-snippets stays.
- Plugin active-state: set per keep-list on the cut DB (the import carries live's
  active set — drop Elementor/Woo/code-snippets/etc; keep the strangler + supporting).

## PHASE 8 — Re-arm /whoami (login ≠ tier)  **[LIVE]**
A DB import breaks tier 4 ways — re-arm so logged-in members resolve to correct tier:
- Reactivate the **poller** (import deactivates it).
- Restore `lgms_db_*` creds (wiped by import).
- BB REST gate re-arms → re-open; bridge gaps → re-bridge.
- Cache flush. (NOTE: in adopt model siteurl already correct — no siteurl rewrite.)
- Full **bb-mirror person-resync** (person keys on recyclable WP IDs → stale names).

## PHASE 9 — VERIFY GATES (hard gates before flip)  **[LIVE]**
- ⭐ **Logged-in consistency**: real existing live cookie → new box → `authenticated=true`
  AND correct tier. (false = salts/siteurl; authed-but-public = whoami re-arm incomplete.)
- Tiers: looth1-4 resolve correctly via /whoami.
- Sponsor routes: /sponsors/ 200, /sponsors/<slug>/ serves blob, /sponsor-page no-301. **[DEV✓]**
- /hub/ + /archive/ + /events/ + /u/<slug> 200. **[DEV✓ on dev]**
- nginx -t clean; all FPM pools up; redis up.

## PHASE 10 — Flip traffic  **[LIVE]**
- Point DNS / load balancer from old box → new box.
- Old box stays running (serves loothtool; was NOT the rollback — see Phase 3).
- Watch logs; if bad → restore the Phase 3 snapshot (accept downtime + lost writes).

---

## REHEARSAL-AS-TRIAL PLAN — on the m7a.MEDIUM loothtool box (constrained + measured)
⚠️ The trial server is **m7a.medium (1 vCPU / 4 GB)** and is **already hosting
loothtool (live)**. So this rehearsal is ALSO a sizing trial + a do-no-harm exercise:
prove the cut runs end-to-end AND measure whether m7a.medium can host it without
taking loothtool down. **Keep loothtool's own backup intact throughout.**

**Do-no-harm setup (BEFORE anything heavy):**
0a. **ADD SWAP FIRST** — OOM backstop so a PG-rebuild spike can't kill loothtool
    (e.g. 4–8 GB swapfile). Non-negotiable, step zero.
0b. **Tune everything SMALL** (co-resident budget): low FPM `pm.max_children`
    (per pool), small PG `shared_buffers`/`work_mem`, small MariaDB
    `innodb_buffer_pool_size`. Leave headroom for loothtool + the OS.
0c. Confirm loothtool's backup exists + is current before you start.

**Run:**
1. **Clone source**: fresh live WP MariaDB dump (+ wp-config) for the trial.
2. **Phases 1-2**: provision + symlink farm. Confirm every app serves.
3. **Phase 3**: take the pre-cut snapshot, test-restore it → prove the rollback
   artifact is valid before relying on it.
4. **Phases 4-8**: adopt WP DB, wp-config (salts kept), **PG rebuild SERIALIZED /
   THROTTLED as the ONLY heavy load** (don't run lane migrations in parallel — the
   rebuild is the RAM spike), secrets, DB-state, whoami re-arm. Time each step.
5. **Phase 9 gates**: run EVERY gate. Especially: real live cookie → trial box →
   stays authenticated + correct tier.
6. **Prove rollback**: break, restore the Phase 3 snapshot, measure restore time
   (= the cut-day downtime budget). Confirm loothtool untouched throughout.

**⭐ MEASURE (the whole point):**
- **Peak RAM during the PG rebuild** ← the GATING number.
- **Steady-state RAM** with loothtool co-resident + the strangler stack running.
- **Decision rule:** if the rebuild peak OOMs or swaps hard → m7a.medium is too
  small for the real cut. Signal = **bump the real cut to m7a.large**, OR run the
  one-time PG rebuild on a bigger box and resize down to medium for steady-state.
  If peak + steady-state fit comfortably under 4 GB (with swap as backstop, not
  load-bearing) → m7a.medium is viable for the real cut.

**A clean trial pass (cut works + loothtool unharmed + RAM fits) = cut is executable
AND right-sized. This is the payoff of all the prep.**

---
### Status summary
- DEV✓: app convergence, symlink farm, DDL/grants defs, login-consistency mechanics,
  sponsor/hub/archive route serving, central git capture, lg-push capture.
- NEEDS-IAN-ON-LIVE: box provision, recon run, DB adopt, secrets, whoami re-arm, gates, flip.
- DECIDED: Postgres = REBUILD from adopted WP (Ian's lean); lane env-fixes DONE
  (62e42ff/24bdac8); box size = trial-measured (m7a.medium vs large).
- OPEN: run the m7a.medium trial to get the peak-RAM number; lg-stripe-billing /
  thumb-app fold-vs-clone (HELD).

---

## ADDENDUM 2026-06-12 (visibility-refactor session, doc-audit merge)

This runbook is CANONICAL for the cut (doc audit, docs/DOC-AUDIT-2026-06-12.md);
docs/LIVE-DEPLOY-PLAN.md (written 6/12 before this lane's work was found)
carries a superseded banner. Net-new items from 6/12, fold into the phases:

1. **TEST GATE (add to the go/no-go):** the visibility matrix —
   `LG_MATRIX_HOST=https://<host> php profile-app/bin/visibility-matrix.php`
   — 66 asserts, 4 viewers × all surfaces over live HTTP, provisions its own
   QA fixture. GREEN required before flip; re-run morning after. Dev is the
   reference green.
2. **PG REBUILD ratified harder:** the 6/12 visibility refactor moved the
   rulings into CODE + COLUMN DEFAULTS (members-only starting state
   c8977cd, one-dial fold, precision rules in src/Visibility.php), so a
   rebuild from adopted live WP lands them automatically. Re-apply after
   rebuild: the 2 public-finder opt-ins (Ian, Buck — hand-set), the
   karriker location repair (`sudo -u profile-app php
   profile-app/bin/fix-divergent-locations.php --apply` — evidence-guarded,
   re-runnable), matrix fixture (self-provisions).
3. **Secrets (F6):** mint FRESH on the new box — dev's values are exposed
   to every team chat. Set: /etc/looth/jwt pair, lg-internal,
   lg-archive-poc, lg-profile-app, WP profile_hook_secret. NOT WP salts;
   adopted-DB sessions survive, looth_id auto-mints off the WP cookie
   (bridge reconcile must run before flip).
4. **Timers:** install `platform/systemd/bb-mirror-reconcile.*` AND
   `platform/systemd/lg-person-vis-refresh.*` (new 6/12 — identity/
   visibility cache convergence; proven on dev). No gate env file on live.
   Plus weekly geoipupdate.
5. **nginx:** snippets are repo-tracked (platform/nginx/); the new box
   needs `map → $loothdev_is_authorized = 1` (gate neutralization) +
   the still-unapplied rate-limit conf (profile-app/deploy example).
6. **Hardcoded-host bugs fixed 6/12** (login interstitial, reports From:)
   — ensure the deployed checkout is ≥ commit 94a688d.
7. **Buck overlay JS inventory** (/var/www/dev/*.js on dev = live-truth):
   privacy-sheet, directory-desktop, fp pieces, app-mobile-fixes,
   pwa/sw — ship with the pages; do NOT copy the stale bespoke fork copies.
8. **WP-side delta list** (plugins/mu-plugins/options/conversions/sponsor
   ACF disable): docs/LIVE-DEPLOY-PLAN.md §3b — still valid under adopt
   (the adopted DB is live's; dev's WP DB still never ships).
9. **Verifications already banked 6/12:** new-box disk 15.3 GB free ✓,
   r2:loothgroup mounted ✓, dev matrix 66/66 ✓, dev-hostname grep clean ✓.
10. **OPEN with Ian (6/12):** confirm ip-172-31-45-223 IS the new box;
    confirm PG rebuild (post-refactor) over carry; `/` routing at launch;
    BB retirement order; F1 clamp ruling.
