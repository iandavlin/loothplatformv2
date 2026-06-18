# Strangler Chats — Menu

Quick-scan of active chats. Coordinator owns this file — **keep it current.**

**To switch chats:** Claude Code panel on the left of code-server (native session picker). Outliner
titles are auto-generated from each chat's opening turn — when the opener is a file path, the title
echoes that file's topic (the "Find it by" column tells you what to look for). Session IDs are for
coordinator bookkeeping + lineage logging.

> **Refreshed 2026-06-05 PM.** The May-31 table was stale; rebuilt around the **Hub-unification
> project** (archive-poc→Postgres, unified /hub/ feed, comments/reactions). Older lanes from the
> profile/social/cutover era are in [CHAT-LINEAGE.md](CHAT-LINEAGE.md).

## Active lanes

| Lane | Find it by (outliner title) | Session ID | Owns / now |
|---|---|---|---|
| **coordinator** *(this chat)* | *I need a new coord* | `570f18a3-5649-4c1a-8063-d28c5839f7b5` | cross-cutting contract, routing, dev sysadmin. Spawned 6/12 ~12:00, successor to the fable coord (visibility-refactor handoff author). Prior: `9ed18876` / `ecafaa30` / `de317117`. |
| **archive-poc (PG)** | *Briefing archive-poc-pg* | `05b7f8d2-9c86-473f-aa7f-b66ca5f35738` | PG read-cutover + `_sync` PG writer + indexer taxonomy populate (a55871e/d97e63d). SQLite retirement HELD. |
| ~~hub~~ → **folded into hub-COORD** | *(was Briefing hub-fold-cpts)* | `9645be99` *(retired)* | unified /hub/ feed work absorbed by hub-COORD — one Hub-desktop owner. |
| **comments + reactions (ENGINE)** *(edit+delete)* | *comments+reactions ENGINE (edit+delete)* | *pending — Ian confirm (cand. `6c51fab9` / `d0ba32af`)* | backend provider: store + endpoints + palette + gating. Successor to `1c86c753`. Shipped comment **edit + soft-delete** (`5b262c0`) + modal UI wiring (`a652b1c`) — local, awaiting push. New contract: modal emits `{lgCommentsCount:n}` postMessage for SURFACE's badge to listen. |
| **hub-COORD** *(re-split from buck-COORD 6/10; NEW chat 6/12)* | *Briefing hub-coord* | *pending — Ian spawn 6/12* | drives all desktop Hub on the **bespoke-cutover worktree** (`~/worktrees/bespoke-cutover`, dev serves it). Charter REFRESHED 6/12: `briefing-hub-coord.md`. Queue: Buck's _feed.php asks → hub-polish fold-back → greenlit builds (loothprint popup, member create-flow, members-geo) → moderation. Predecessor (6/10 chat) context-full. |
| ~~hub-COORD (6/9 fold)~~ | *(was Hub Desktop Surface)* | `a5a33224` *(retired)* | Hub-desktop ownership moved to buck-COORD. Was: owned all `bb-mirror/web/` solo = the ONE Hub-desktop owner + single source of flat-`fc-*`-contract changes (announce to buck-coord). Folds in: old hub (`9645be99`), reactions-SURFACE (`0ad40ab7`), **card-surface chat** (*Hub cooler-card composer finishing* — composer avatar+pencil+placeholder landed `f7666c4`). Now: filters chrome, reply reactions, inline video facade, gated teasers, ntm desktop native picker. Charter: `briefing-hub-coord.md`. |
| **login / poller** | *Briefing lifecycle-poller* | `3035fd3f-b46f-428c-bd1f-9f40a54f7277` | Patreon auto-login, tier truth, identity stability (G1/G4/G7). |
| **lifecycle / profile-app** | *Briefing lifecycle-profile-app* | `098c8f85-846d-4530-b756-39dc7aa502f2` | profile-app identity stores, bridge, erase-user. |
| **membership-pages** | *membership-pages / Stripe-standalone lane* | `633f14c7-a66e-4529-9753-8797094c69a0` | 15 membership/purchase pages on the shared shell. |
| **stripe-pages toggle** | *Briefing stripe-pages-toggle* | `825a2c1e-a322-44d5-a876-bfe9eaf65d32` | admin on/off for the purchase pages (prelaunch gate). |
| **git-tsar** | *Briefing git-tsar* | `f14788c1-50f4-474f-8626-f42ce32a17cc` | sole merge/push gateway; pathspec sweeps. Worktree isolation SHELVED. |
| **buck-COORD** *(all Buck + ALL desktop now)* | *Briefing buck-coord* | *pending — Ian spawn* (succeeds `b1b940d4`) | dedicated Buck handler: lands his diffs (**guard APP_ROOT flip**), mints tokens + drives CDP. **⭐ 6/9: absorbed hub-COORD + profile-page + map-desktop — now owns desktop+mobile for the Hub feed (all `bb-mirror/web/`), profile page (`u.php`/`_render_blocks.php`), and directory/map (both layers).** `fc-*` announce now internal; still relays the BACKEND contract boundary. Also: app-shell, practice-catalog, mobile chips→radio. Charter: `briefing-buck-coord.md` + absorb-briefing `handoff-desktop-to-buck.md`. |
| **perf-czar** | *Briefing perf-czar* | `221bd8d5-44fd-48e2-8921-676fc01bcfca` | perf baseline + regression watch across surfaces. |
| **lg-shell** | *Briefing lg-shell-nav-active* | `dc066cf4-361b-4c36-8f70-efd3e305359e` | canonical site-header + nav active-state. |
| **bb-mirror whoami fastpath** | *Briefing bb-mirror-whoami-fastpath* | `e22ff194-cc3b-4e64-922c-51eec6901b97` | bb-mirror JWT fast `/whoami` path. |
| **conversion** | *Briefing conversion-coord* | `5020d57f-46b6-4bff-873d-17e539fff4fe` | legacy video → v2 → standalone render. |
| **live-deploy / cutover** | *live-deploy / cutover* | `62cc0edc-0a0d-4718-8f7b-bd916f3d1a44` | owns the cut to live: git-native monorepo+symlink-farm runbook (DDL/grants/nginx/secrets/snippets/whoami-rearm). Writes bash, Ian runs on live. Mandate: `git-native-deploy-mandate.md` (fc161ab). Charter: `briefing-live-deploy.md`. |
| **sponsor-pages v2** *(3 lanes)* | *Briefing sponsor-pages-v2* | Lane A: *pending — Ian* | rebuild sponsor pages+posts as v2 JSON, brand data ACF→profile-app PG, LLM spin-up skill. **Lane A DONE** (`a769bb3`+`1bfd85b`: `sponsor` table + read API + ACF migration, 5 sponsors round-trip, ACF #33147 retired). **Lane B (lg-layout-v2 blocks) ready to spin** — consumes `GET /profile-api/v0/sponsor/<slug>`, brand-var contract `--brand-primary/secondary/header`. C=`write-sponsor-v2` skill. Charter: `briefing-sponsor-pages-v2.md`. |

## Feature task-lanes (in flight this session — held for push)
| Lane (outliner title) | Session | Commit / state |
|---|---|---|
| **discussion_visibility — profile-app backend** | `098c8f85` | `e8e44c7` — column + PUT/GET `/me/discussion-visibility` + surfaced in `/whoami`+`/users`. ✅ |
| **discussion_visibility — profile-page toggle** | profile-page | `94f52e8` — owner Public/Member toggle, info_schema-guarded. ✅ |
| **bb-mirror person-sync — discussion_visibility → forums.person** | `2b5dd978-7e21-4d1a-86b6-766abff4efd8` | `9046513` — `forums.person.discussion_visibility` + profile-api loopback sync + backfill. ✅ (⚠️ `forums.person` is bb-mirror's, NOT archive-poc's `discovery.person` — earlier relay misrouted) |
| **discussion_visibility — profile-app staleness poke** | `098c8f85` | `a8dfce7` — PUT pokes bb-mirror `_sync` on write. ✅ |
| **bb-mirror person-sync — person/upsert receiver case** | *pending — Ian paste* | `8fca5f4` — receiver closes the poke loop; round-trip verified. ✅ |
| **discussion_visibility — Hub render mask** | `a5a33224` (hub-coord) | `36d868a` — leak-safe mask (logged-out+member→"Private member", identity scrubbed server-side). Coord-reviewed ✅ PASS. |
| **anon-posting rebuild — Phase 1 (native "Post anonymously")** | *pending — Ian paste* | `6ae1c90` — per-post toggle → `_lg_anon`→`is_anon`, leak-safe mask + admin reveal, 11 files. Buck announced. Legacy #93/95/96 kept as fallback. ✅ Cut-day infra (doesn't ride git): `ALTER TABLE forums.topic/reply ADD is_anon BOOLEAN` on cut DB; redeploy `bb-mirror/deploy/bb-mirror-sync.php` mu-plugin (captures `_lg_anon` from POST body on `bbp_new_topic`/`bbp_new_reply`). |

## Coordinator-held / awaiting Ian
- **The push** — ~27 commits committed-not-pushed on `main`; Ian review → git-tsar pushes. No silent pushes.
- **SQLite retirement** — held until the Hub content-filter is verified on the new taxonomy labels + soaked.
- **Buck pro-gate** — APPROVED 6/5; Buck to re-rebase `0842006`→`d6ba1fb` + merge, report final SHA.
- **profile-app re-rot fix** — provision sets gravatar-placeholder default; needs the prefer-BB-avatar fix or the 496-avatar backfill re-rots (briefing owed to profile-app lane).
- **cutover grant list** — `GRANT SELECT ON discovery.comments TO "bb-mirror"` (committed dd248c5, applied dev) must be re-applied at cut. Same pattern as the `content_item` grant.
- **cutover: comment edit/delete (5b262c0)** — INFRA bits don't ride the git push, re-apply at cut: (1) the two `location =` route blocks + clean-URL rewrites for `comment-delete`/`comment-edit` in `/etc/nginx/snippets/strangler-archive-poc.conf`; (2) `ALTER TABLE discovery.comments ADD COLUMN IF NOT EXISTS edited_at` DDL on the cut DB; (3) confirm the cut WP-pool role has UPDATE on `comments`.
- **cutover: discussion-visibility (e8e44c7 profile-app · 94f52e8 profile-page · hub-coord render)** — re-apply at cut: (1) `sql/2026-06-08-discussion-visibility.sql` (adds `users.discussion_visibility text NOT NULL DEFAULT 'member' CHECK IN ('public','member')`) on the profile-app DB — **must apply WITH the code push, whoami/users hard-reference the column**; (2) profile-app nginx rewrite + the `/me` authed regex add in `/etc/nginx/snippets/strangler-profile-app.conf`; (3) `ALTER TABLE forums.person ADD COLUMN discussion_visibility text NOT NULL DEFAULT 'member' CHECK IN ('public','member')` on the cut DB (synced by **bb-mirror**, NOT archive-poc — `forums.person` is bb-mirror-owned; archive-poc's `discovery.person` is a different table the feed doesn't read), commit `9046513` + one-time `bb-mirror/bin/backfill-discussion-visibility.php`. ⚠️ Field value is singular `member` (2-state), NOT `members` — load-bearing across column/endpoint/mask. ✅ STALENESS GAP CLOSED (path-a): profile-app PUT pokes bb-mirror `_sync` on write (`a8dfce7`) → bb-mirror `person/upsert` receiver case (`8fca5f4`) refreshes `forums.person` instantly. Round-trip verified (wp_id 1796 flip → poke → column refreshed, zero posts).

- **cutover: sponsor brand-store** — re-apply on cut DB (doesn't ride git): `profile-app/sql/2026-06-09-sponsor-brand-store.sql` (`sponsor` table + trigger) on profile_app DB; the `/profile-api/v0/sponsor/` route in `strangler-profile-app.conf`; populate by CARRYING THE ROWS (preferred) — a fresh `migrate-sponsors.php` run from ACF would REVERT the 6/12 forum_url fix (sponsor links now /hub/<slug>/, not the BB forum archive; 81e3423). Lane A captured this in `profile-app/CUTOVER-CHECKLIST.md`. ACF #33147 stays deactivated on the cut DB.
- **cutover: error pages (7e73573)** — lg-shared/errors/ + the lg-shared.conf snippet ride git; the `lg-error-pages.php` mu-plugin does NOT — cp `platform/mu-plugins/lg-error-pages.php` → live `wp-content/mu-plugins/` at cut.
- **cutover: fuzzy search (pg_trgm)** — re-apply on the cut DB (doesn't ride git): `CREATE EXTENSION pg_trgm` + 4 GIN trigram indexes (`forums.topic.title`/`author_name`, `discovery.content_item.title`/`author_name`). Applied dev 6/9. The `_suggest.php` query change (fuzzy fallback) DOES ride git (Buck's lane).

## ✅ Transfer DONE — Hub/profile/map desktop → buck-COORD (Ian 6/9)
Executed after the 13-commit push (clean tree). hub-COORD `a5a33224` + profile-page + map-desktop retired;
buck-COORD is sole owner of desktop+mobile for the Hub feed, profile page, and directory/map. `fc-*`
announce-dance is now internal to buck-COORD; only the backend contract boundary still crosses lanes.
Absorb-briefing: [handoff-desktop-to-buck.md](handoff-desktop-to-buck.md). Lineage logged.

## ⛓️ DEPLOY MODEL — LOCKED (live-deploy lane, 6/9) — EVERY LANE READ
- **Cut shape:** clone live → a NEW self-contained box (own local MariaDB + Postgres) → flip traffic →
  old box frozen = instant rollback. loothtool stays on the old box.
- **Deploy model:** ONE monorepo (`looth-platform`) + symlink farm on the box. Edit in the REPO → push →
  `git pull` on live → live. Config zones (nginx/FPM) need one `reload` after pull.
- **THE MANDATE (all lanes, now):** (1) STOP editing live-serving copies in place (`wp-content/`, `/srv/`,
  `/etc/`, webroot) — in-place edits = drift = **silently lost at cut**. Edit the repo copy + deploy.
  (2) Everything your lane added that drives a live feature MUST be committed to the monorepo, staged **by
  pathspec** (never `git add -A`). (3) Cut-eligible = dev-proven **AND git-complete** (every relied-on file
  in the repo at its canonical path; live-deploy publishes the path-map).
- **Does NOT ride git** (→ live-deploy runbook): secrets (`/etc/lg-*`, JWT/VAPID), DB-stored state
  (code-snippets, active-plugin set, active theme). NEW secret or DB-state dep → flag coordinator.
- **Known drift** to reconcile (per owning lane): /etc nginx snippets (archive-poc 73 vs 309 live!,
  profile-app, bb-mirror, lg-shared, membership); ~18 loose webroot JS/CSS (buck-COORD); missing mu-plugins
  (lg-article-materializer, lg-comments-frame; drifted archive-poc-sync/bb-mirror-sync/profile-sync);
  missing custom plugins (lg-apps, lg-anonymous-authors, lg-recent-posts-widget, lg-weekly-digest,
  event-reminder-and-cleaner).

## Discipline
At every chat spawn/resume, capture the session ID and pass it to coordinator. Coordinator updates this
menu + appends to [CHAT-LINEAGE.md](CHAT-LINEAGE.md) on replacements. **Don't let this drift.**

## Pointers
- Contract: [STRANGLER-COORDINATION.md](STRANGLER-COORDINATION.md)
- Live board: [LANE-LEDGER.md](LANE-LEDGER.md)
- DB ground truth: [DB-STATE-AUDIT-2026-06-05.md](DB-STATE-AUDIT-2026-06-05.md)
- Coordinator briefing: [briefing-coordinator-successor.md](briefing-coordinator-successor.md)
- Chat lineage log: [CHAT-LINEAGE.md](CHAT-LINEAGE.md)
