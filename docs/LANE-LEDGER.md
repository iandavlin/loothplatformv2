# Lane Ledger — coordinator view (2026-06-03)

> ⚠️ **STALE SNAPSHOT (last real update 2026-06-05) — current lane/cut state =
> `cutover/lanes/INDEX.md` + `cutover/lanes/HANDOFF.md`.** Many 🟡/🔵 / "uncommitted" /
> "awaiting push" markers below have since landed (e.g. coral/slate tokens promoted `2f28fae`;
> archive-poc→PG read-cutover done; the ~9-commit push cleared). Trust INDEX/HANDOFF, not this ledger.

Status of every dispatched lane. Coordinator chat owns this file; lanes report back, coordinator updates.

Legend: 🟢 green/done · 🟡 in-flight · ⚪ dispatched, not started · 🔵 queued/blocked

## Lanes

| Lane | Scope | Status | Notes |
|------|-------|--------|-------|
| **conversion** | legacy videos → v2 → standalone render | 🟡 in-flight | briefing-conversion-coord.md; one-at-a-time; 3D Club + 70899 done |
| **whoami/gating** | /whoami SSOT + poller tier + gating reads | 🟢 keystone live | Poller ACTIVE; tier endpoint returns real computation (200, provenance, caps) — no stub, no regression from the nginx lockdown (whoami sets Host so allow 127.0.0.1 passes). Bridge mapping clean (1612). NOTE: admin (user 1) computes tier=public + admin-caps (no paid-tier role) — correct; PRO-pill verify needs a real looth3/4 member. OPEN: suspect #5 scattered-gating-reads inventory. **PRIORITY #1 now: fast-identity fix (SHORTINIT endpoint + looth_id JWT front) — spec docs/relay-whoami-fast-identity.md. Kills the ~1s tax, fixes anon-on-standalone, unblocks stream authed likes + the PRO-pill verify. Measure before/after w/ perf-czar.** |
| **header Step 1 — bb-mirror** | `$ctx` from /whoami in bb-mirror/_chrome.php | 🟢 done | 05e36b6 pushed. JWT→anchor only; dropped lg_tier path + d=mp avatar rewrite. Tier *value* correctness pends poller lane. |
| **header Step 1 — archive-poc** | `$ctx` from /whoami; drop LG_VIEWER_TIER | 🟢 done | _chrome.php now byte-parallel w/ profile-app ref; LG_VIEWER_TIER gone. Tier *value* pends poller. **Committed a9e130c, awaiting push sign-off.** |
| **archive-poc — events pulled from archive** | keep events in index, hide from /archive/ search | 🟢 done | search.php `ci.kind!='event'`; suggest `NOT IN(discussion,event)`; 9 events back on front rail. **Committed b9b61bf (+breakout 5b39a09), awaiting push sign-off.** |
| **header Step 2 — lg-layout-v2** | retire lg-site-header onto shared render | 🟢 done | SiteHeader.php delegates to lg_shared_render_site_header; lg-site-header.css/js/partial git-rm'd; vendored archive-poc engine copy synced. WP render path now lg-chrome. Uncommitted. |
| **profile_url parity** | account chip → same dest everywhere | 🟢 decided + half-done | Ian ruled `/u/<slug>` (fallback /profile/edit). membership-chrome FIXED+deployed (coordinator). lg-layout-v2 lane to thread user_nicename. archive-poc/bb-mirror/contract already correct. |
| **token promotion — lg-layout-v2** | coral/slate → src/theme/tokens.json | 🟢 done | committed 2f28fae; auto-surface in dash panel + colorpicker swatches (category:color). Coral also added to dash per Ian. |
| **activity-stream prototype** | unified inline-functional `/stream/` over archive index | 🟢 milestone-1 / going wide | `/stream/` live (archive-poc, zero WP boot). Likes built (discovery.likes PG + POST /archive-api/v0/like, IDOR-proof + HMAC CSRF). **Anon gated test PASSED** (absence, not CSS). BLOCKED on authed end-to-end by the whoami-anon issue = priority-1 whoami/gating fix. **WIDE PHASE SHIPPED**: inline video play (~324/341), comment counts (backfilled), signed in-stream download (server-side entitlement → X-Accel, proven secure), polish. Image seam ($imgAttrs) ready for perf-czar variants. **AUTHED E2E GREEN** (pilot_pro: likes persist/toggle/survive, CSRF holds, gated→ungated for pro, header logged-in chrome). Functionally complete. SHIP-TRACK PENDING: (1) 🔴 confirm whoami lane closed the lg_tier pagination bypass; (2) commit/review/push the stream+likes+whoami stack; (3) cutover = full user-bridge backfill + deploy to live; (4) perf-czar before/afters logged. Fast-follow: bbPress inline-reply (bb-mirror), ~5% video fallback (accepted). |
| **performance czar** | own perf across surfaces; baseline + regressions | 🟢 baseline logged | PERF-BASELINE.md done. whoami 590→5ms (cited+corroborated). Image fix −82% (4051→709KB) — CODE done, effective-on-dev pending content_item re-resolve (deferred, dev-fixtures). CLS 0.151 was a cold outlier → actually 0 (no false win). Next: comments-lean before/after + regression-watch. /lgjoin parked (low-traffic, rides migration). |
| **nginx: rows-more + cursor** (coordinator) | wire rows-more, fix args/alias drop | 🟡 partial | rows-more source-disclosure CLOSED (added to exec regex; .php executes now). OPEN (deferred, dev-gated): clean-URL/rail 404 + stream-more ?cursor ignored = same args-under-alias tangle. Not urgent; close before cutover. |
| **header Step 3 — BB theme** | site-header--bb long tail | 🔵 tracked | no discrete task; dies as strangler finishes |

| **v2 lightbox regression** | image-block lightbox stopped opening | 🟢 fixed (dev) / signed off | NOT 2f28fae — the standalone renderer never shipped lg-front.js (exposed when CPTs moved to standalone). Fix: vendored lg-front.js → archive-poc/standalone/engine/assets/ + render.php emits it (external w/ inline fallback). Live-effective on dev (serves from repo). CUTOVER MUST-DOs: (1) deploy carries engine/assets/lg-front.js to /srv/archive-poc; (2) vendor-sync doc must include assets/. Arch follow-up: consider single shared artifact vs vendored. |

| **comments-lean** | speed up the `?lg_comments=1` modal | ⚪ briefed | docs/briefing-comments-lean.md. 1.2s TTFB/3.3s total — lean template already exists (lg-comments-frame); fix = dequeue BB-theme+Elementor assets that wp_head still prints (Isolate-style). ~1.2s PHP-boot floor = harder lean-WP, escalate if it remains. perf-czar verifies. |

| **Buck branches** | profile-app merges | mixed | **Policy (Ian 6/3): auto-merge trivial/clobber-clean + report; HOLD policy/privacy/FINAL-model.** MERGED: dropoffs-map (8f28bcd), memberview (c43d0ee), guitar-icon (eb2d19a). 🛑 HELD for Ian: public-pro-gate (FINAL model → testing, fail-closed rec); **dropoff-clusters 298dc46 (exposes members' exact storefront pins on directory map — privacy call)**. |

## Cross-lane / coordinator items
- 🟢 **CPT standalone-header identity** — `render.php` called /whoami but hardcoded `avatar_url=null`/`capabilities=[]`, so ALL CPT headers showed initial-avatar + no admin (vs /archive/). Now sources avatar/caps/profile_url from /whoami (mirrors _chrome.php). Live (FPM reloaded). **Uncommitted — fold into archive-poc commits.** Was the 4th consumer Step 1 missed.
- 🟢 **siteurl fix** — dev WP DB had live URLs (CF reload); search-replace + cache flush. Logout/login fixed.
- 🟢 **internal tier channel lockdown** — nginx `/wp-json/looth-internal/` now localhost-only (allow 127.0.0.1/::1; deny all) atop PHP shared-secret.
- 🔵 **reconciler → DB-reload runbook** (not cron); enhancement (re-validate email per wp_user_id) flagged to cutover lane.
- 🔵 **coral #c66845 + slate #6f8fa6** — bb-mirror local brand extension; pending decision to promote into lg-layout-v2 tokens.json (Ian's brand call).
- 🔴 **secrets to rotate** — CF creds (pasted in chat) + plaintext AWS key (AKIA…) in /var/www/dev/wp-config.php.

## Pushed this session (on main)
- bb-mirror: 05e36b6 (Step 1), 1c4f0b8 (pill borders/suggestion-box/sticky offset), 7c561f2 (9-color palette), c7262f0 (palette unification).
- Coordinator: siteurl (DB, not git), nginx lockdown (conf + .bak).

## 2026-06-04 — user-lifecycle + git-lane restructure (coordinator)
- **user-lifecycle DELETE** 🟢 done + integration-proven. Canonical `UserLifecycle::teardown(wpId,mode,dryRun)` (nuke|tombstone), WP Users-screen row+bulk actions, `deleted_user` safety net, legacy doNuke/TestChecklist folded to wrappers; profile-app `internal/erase-user` endpoint. Coordinator wired the nginx route + ran the joint nuke on a full-footprint throwaway (1893): WP user/posts/comments + bridge + 6 profile rows erased over the route; media-delete masked by a root-owned fixture file (artifact, not a bug); discovery skipped (DSN). Commits 91718f5/1c2ad4e/3b0acf9/2db2258 — committed, push handed to git-tsar.
- **user-lifecycle LOGIN** ⚪ dispatched. poller → `briefing-login-poller.md` (G1 onboard auto-login+JWT, G2 cache purge, G3 dash-role capture, Phase-3 canonical provision). shim+profile-app → `briefing-login-identity.md` (G4 uuid-stable mint/email-change, G7 reliable bridge-create). Full audit+plan: `docs/USER-LIFECYCLE-AUDIT.md`.
- **git tsar** 🆕 role created (`briefing-git-tsar.md`) — sole merge/push gateway + per-lane worktrees; running the off-main migration now. Worktree transfer mechanism coordinator-tested.
- **Buck sub-coordinator** 🆕 role created (`briefing-buck-subcoord.md`) — owns all Buck branches per the standing merge policy; frees main coord.
- **Coordinator follow-ups (2026-06-04 resolved):**
  - **Orphan sweep DONE.** `wp_user_bridge` already clean (0 dead-WP-id rows, 0 email mismatches). `lg_role_sources` had **35 ghost rows across 30 recycled test-user IDs** → deleted; `lg_patreon_members` 1 orphan → deleted. 0 remaining. `provision()` prevents new ones.
  - **Discovery DSN — REVERSED, do NOT provision.** Poller's `handleDiscovery` queries PG `discovery.person`/`content_item`, but on dev those live in **archive-poc's SQLite** (`index.sqlite`); PG `looth.discovery` only has `likes`+`article_blobs`. A PG DSN would make teardown *error*. Real fix = poller code change (target SQLite, drop `discovery.` prefix) tied to [[project_discovery_pg_migration]]. Relayed to poller; honest "skipped" marker stays correct.
  - **Dev bridge ENABLED** (`profile_hook_secret`) — new users auto-bridge; unblocked both login lanes. Reversible.

## 2026-06-05 — Hub unification + archive-poc→Postgres (coordinator)
- **archive-poc → Postgres READ-CUTOVER** 🟢 done + browser-proven (front-page/archive/search/sponsors/calendar on PG, faster; content_item=708, discussions dropped by design). SQLite intact as one-line revert. Repoint edits UNCOMMITTED (working-tree since 23be507) — stage/review/push pending. NEXT: `_sync.php`→PG port (gate for SQLite retirement); HOLDING retirement.
- **Hub unification** ⏸️ hub lane parked on poc. Plan: UNION discovery.content_item + forums.* into the Hub feed (one query), content cards, then the filter/nav layer (`hub-filter-nav-spec.md`: AND filters, search-first authors, profile-sourced author header, type/category mute only — NO person mute, per-user persistence). Naming: stays "The Hub" at /hub/. `/stream/` retired → 301 /hub/ (live).
- **Cutover model CHANGED** → in-place promotion (dev BECOMES live), §4 rewritten; cutover lane to rewrite CUTOVER-PLAN.md. DSN-quote = cut-day gotcha (took FPM down ~1min).
- **Coordinator open:** the push (~9 commits, Ian sign-off); repo nginx-snippet reconcile; mobile read-API layer (future).
