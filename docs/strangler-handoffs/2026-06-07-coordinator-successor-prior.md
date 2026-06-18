# Briefing — successor coordinator (2026-06-05)

You're taking over coordination. The prior session is retired (context full). **The active project right
now is the Hub unification on the archive-poc/Postgres stack** — get moving on that first; the rest is
context. System is stable, work is in flight, every decision is in a doc.

## Spin up fast — read in order
1. This file.
2. **`docs/DB-STATE-AUDIT-2026-06-05.md`** — physical DB state (the project's ground truth).
3. **`docs/STRANGLER-COORDINATION.md` §4** — the cutover model (REWRITTEN 2026-06-05 to **in-place
   promotion: dev BECOMES live**, not blue-green). Plus §0/§2 for the contract.
4. **`docs/LANE-LEDGER.md`** — live board.
5. **`docs/hub-filter-nav-spec.md`** + the mockup at `https://dev.loothgroup.com/mockups/hub-filters.html`
   — the filter/nav design (decided, not yet built).

Memory auto-loads via `MEMORY.md`. Relevant: `feedback_relay_link_format`, `feedback_chat_report_back_format`,
`project_activity_stream_launch`, `project_managed_cpt_render_routing`, `project_discovery_pg_migration`,
`feedback_gate_posting_on_wp_cookie_not_whoami`.

## THE PROJECT — Hub unification (do this first)
Goal: **The Hub** (`/hub/`, bb-mirror) becomes the one unified surface — forum threads + content
(articles/videos/loothprints) in one feed, with rich filtering. `/stream/` is retired (301→/hub/, live).
Archive may fold into the Hub later. No data duplication — `forums` + `discovery` are two schemas in the
same `looth` PG DB, so the feed is one cross-schema query.

**Where it stands:**
- ✅ **archive-poc reads cut over to Postgres** — browser-proven, *faster* (search 31ms). `content_item`
  = 708 content rows (discussions DROPPED on purpose — they stay in `forums.*`; "kind=discussion → 0" is
  the proof). SQLite intact as one-line-revert. **Repoint edits NOT yet committed** (working-tree, since
  `23be507`) — they need to be staged by pathspec + reviewed + pushed.
- 🔜 **poc lane's next task: port `_sync.php` (incremental WP→index writer) to write Postgres.** This is
  the GATE for retiring SQLite — reads are on PG but the writer still hits SQLite, so edits don't reach
  the front page without a full re-backfill. **Do NOT greenlight SQLite retirement until `_sync.php`
  targets PG and it's soaked.**
- ⏸️ **hub lane PARKED** (Ian: wait for poc to fully settle). When unblocked: UNION `discovery.content_item`
  + `forums.*` in `bb-mirror/web/forums/_feed.php` (one query, New/Old/Hot across both), content-card
  variant in the renderer, verify gating. THEN the filter/nav layer per `hub-filter-nav-spec.md`.
  Briefing: `briefing-hub-fold-cpts.md`. Naming decided: stays **"The Hub" at /hub/**.

**Filter/nav design (decided, spec'd, mockup'd — not built):** AND across Type∩Category∩Author;
Type+Category have sticky mute toggles (**mute is type/category only — NO person/author mute**);
**Authors are search-first** (byline-clickable, author header pulled from profile-app, filter-only);
per-user prefs persist in profile-app. All in `hub-filter-nav-spec.md`.

## Coordinator-owned open items
- **The push** — ~9 commits committed-not-pushed (user-lifecycle delete, login fixes, comments DB,
  bb-mirror whoami+hub-UI, lg-shell nav, conversions) PLUS the uncommitted archive-poc repoint. Ian
  reviews + signs off → git-tsar pushes. **No silent pushes.** This is the gating item for landing it all.
- **nginx repo reconcile** — deployed `strangler-archive-poc.conf` is ahead of the repo copy (I did
  `/stream/`→`/hub/` + dropped dead `stream-more` routing). Sync the repo copy for cutover-prep.
- **DSN-quote cut-day gotcha** — an unquoted `;` in a DSN took all 8 FPM pools down ~1 min during the
  PG flip. Log it into the cutover runbook's secret-swap checklist (the in-place cut does a dev→live
  DSN/secret swap — quoting matters).
- **Bridge is ENABLED on dev** (`profile_hook_secret`) — new users auto-bridge. (Was off pre-launch.)

## Lanes / roles (briefings in docs/)
- **archive-poc** (`briefing-archive-poc-pg.md`) — read-cutover DONE, on `_sync.php` port next.
- **hub** (`briefing-hub-fold-cpts.md`) — parked on poc.
- **git-tsar** (`briefing-git-tsar.md`) — sole merge/push gateway. Worktree isolation is SHELVED (it
  broke live dev twice — dev serves from the working tree, so reverting main 404s live pages). Lanes
  work in `main`; tsar merges by pathspec.
- **Buck sub-coordinator** (`briefing-buck-subcoord.md`) — owns all Buck branches.
- **login** (`briefing-login-poller.md` + `briefing-login-identity.md`) — running. G1 (Patreon auto-login)
  PROVEN by a real connect; G4/G7 identity-stability shipped.
- **stripe-pages toggle** (`briefing-stripe-pages-toggle.md`) — admin on/off for the purchase pages; ready.

## How Ian works
Terse, plain-English, lead-with-the-answer; pushes back hard, revise loudly; runs code-server, copy-paste
broken → use the canonical relay format; cautious about anything irreversible ("are we deleting anything?")
— keep reversibility explicit; token-conscious (fewer/leaner chats). You're also box sysadmin `ubuntu` —
you wire dev nginx/FPM/secrets yourself.

## When in doubt
Read `STRANGLER-COORDINATION.md` end to end. Then push the project: get the push signed off, get
`_sync.php` ported, unblock the hub lane, build the unified feed + filter layer. That's the line.
