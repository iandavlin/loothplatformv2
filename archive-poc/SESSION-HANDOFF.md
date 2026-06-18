> ⚠️ **SNAPSHOT — verify every open/queued to-do against `git log` before working it (flagged 2026-06-15).** Items marked open/TODO/next here may already be shipped — a lane re-did a done task off a stale handoff. Source of truth = `git log` + gates, not these bullets.

<!-- ===================== COORDINATOR BANNER (2026-06-01) ===================== -->
> **Ownership consolidated 2026-06-01.** archive-poc/standalone had two chats colliding
> (the shared-tree problem). Resolved to **one owner**: the active standalone chat (Part A
> `5073a34` + the launch-batch data-source analysis). **The `61c058c` chat is RETIRED** — its
> work (render perf, video WP fallback, same-tab cards, comments frame, materializer, nginx
> routes) is committed + live and preserved in the state below; do not resume it.
>
> **Boundary:** the standalone lane owns archive-poc app code (`render.php`, `search.php`,
> `web/`, `api/`, materializer/indexer, `content_item`, `/weekly/`) **and its
> `nginx-snippet.conf` repo copy**. The **coordinator** owns deploy→`/etc`, the main-site conf,
> the cookie gate, FPM pools — and **relays** cross-cutting routing rather than editing lane
> files (re the `f6c9457` CPT catch-all + `render.php` overlap).
>
> **Open (2026-06-01):**
> - **Part B (WP-fallback): SETTLED** — outcome live + verified (covered→standalone,
>   uncovered→WP, bogus→404) via `61c058c` + `f6c9457`. Do not redo.
> - **Part C (/weekly/): APPROVED — build Option C.** Index `weekly_email` as kind `'digest'`
>   in `content_item`; add `'digest'` to the existing `kind NOT IN (…)` exclude in `search.php`
>   (+ feed) so it never pollutes feed/search; `/weekly/` selects `WHERE kind='digest'`. Read
>   side stays post-WP-pure; only the indexer repoints at cut.
> - `standalone/dash-theme.json` has been dirty (uncommitted) all session — confirm it's a dash
>   runtime write, then commit-or-discard.
<!-- ========================================================================== -->

> **GUITARDLE = LAUNCHED, front page only (Ian 2026-06-12 PM, reversing the AM
> decom).** Mounted stacked under the featured video for BOTH audiences via the
> `_render-main-row.php` video-promo include (no config row — the standalone
> `guitardle` row layout stays renderable but unused). Board card always shows
> FIVE slots; open slots = italic "Open spot / play to claim" placeholders.
> Game is REFRESH-PROOF: the forfeit rule is retired — every move snapshots to
> localStorage `guitardle_game` (keyed date+phraseId), reload restores the
> position; save clears on win/loss/already-played. beforeunload prompt, amber
> strip, and the How-to-Play forfeit line are gone. Server one-game-per-day
> lock unchanged. **Hub teaser + side-art stay OFF** (Ian: "do not rearm in the
> hub") — the two commented pwa.js lines are now the ONLY re-arm left.
> sequence.json untouched (startDate 6/11; launch day = day 2, fresh phrase).


# archive-poc — Session Handoff (2026-05-28)

## Where things stand

archive-poc has been pulled into cross-chat strangler coordination. The
next material work is the **SQLite → postgres migration** at cutover,
not the FE editor. FE editor work is sequenced *after* migration so we
don't rewrite the save path twice.

Prior handoff (FE editor design, 2026-05-26) rotated to
`handoffs/2026-05-26.md` — its FE-editor architecture sketch is still
the plan, just postponed.

## Coordination context

- **Briefing in:** `/home/ubuntu/projects/docs/briefing-archive-poc-postgres.md`
- **Coord doc:** `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
- **My reply back to coordinator:** delivered via Ian in this session

### What changed for us

1. **Storage:** SQLite → postgres `discovery` schema on the shared
   live postgres instance (§3i of coord doc). Was previously "stays
   SQLite — read-mostly editorial"; Ian overruled. Mobile is imminent
   and SQLite's writer-lock model + lack of cross-schema joins becomes
   the binding constraint under that load shape.
2. **Auth gating:** drop the planned `lg_edit_capable` cookie. Use
   `capabilities.edit_archive_poc` from `/whoami` instead (coord §2).
   Cookie `lg_tier` stays as first-paint hint only — `/whoami` is truth
   for any decision more sensitive than first paint.
3. **Header chrome:** at cutover, `web/index.php`'s bespoke chrome gets
   replaced by the shared header partial from `lg-shell` (coord §4 step 3).
   We are a **consumer** of that partial, not the owner. lg-shell will
   surface the include API; we wire it in mechanically. Mockup we built
   (`shared/mockups/site-header.html`) is available to lg-shell as a
   design artifact.

### Schema name: `discovery`

Picked over `archive_poc` because the "POC" suffix is a freshness
marker that should fall off at cutover; baking it into a permanent
schema name freezes it forever. `discovery` describes what the surface
does.

## Postgres migration plan

### Approach: skip pgloader, re-run backfill

The briefing suggested `pgloader sqlite://... postgresql:///looth?search_path=discovery`.
**Recommended against it.** The index is fully derived state — every
row in `content_item` / `tag` / `content_tag` / `person` comes from
`bin/backfill.php` reading WP. There's no authoritative data here that
doesn't exist upstream.

Cutover-day sequence:
1. Create `discovery` schema + DDL on live postgres
2. Re-point `bin/backfill.php` at postgres (PDO DSN swap)
3. Run the backfill against live WP, writing to postgres
4. Switch FPM pool to postgres DSN, reload nginx
5. Smoke-curl the feed; verify gated overlays + FTS

Smaller blast radius than pgloader's dialect translation step,
especially around FTS where the data shape changes.

### DDL port

`schema.sql` → `schema.pg.sql`. Mappings:

| SQLite | Postgres |
|---|---|
| `INTEGER PRIMARY KEY` (implicit autoinc) | `GENERATED ALWAYS AS IDENTITY` |
| `INTEGER` unix-epoch timestamps | `TIMESTAMPTZ` (coord-confirmed; do the upgrade) |
| `INTEGER DEFAULT 0` boolean-ish | `BOOLEAN DEFAULT FALSE` for `thumb_broken`, `has_download` |
| `CREATE VIRTUAL TABLE content_fts USING fts5(...)` | denormalized `tag_text TEXT` on `content_item` + `tsv tsvector GENERATED ALWAYS AS (...) STORED` + `CREATE INDEX ... USING GIN (tsv)` |
| Indexes `idx_content_*` | unchanged (postgres B-tree by default) |
| Partial index `WHERE last_activity IS NOT NULL` | unchanged (postgres supports partial indexes) |

**FTS detail:** SQLite `content_fts` indexed `title, body_text,
author_name, tag_text` with porter tokenizer. Postgres equivalent =
single `tsvector` over `to_tsvector('english', coalesce(title,'') || ' ' ||
coalesce(body_text,'') || ' ' || coalesce(author_name,'') || ' ' ||
coalesce(tag_text,''))`. The `tag_text` column gets denormalized in
the backfill (concat of tag labels joined to this content_item) — same
denormalization that today happens at FTS-insert time.

### App-code changes

Scope (files that need touching):

- `web/db.php` (or equivalent bootstrap) — PDO DSN swap to `pgsql:`
- `bin/backfill.php` — same DSN swap; FTS-insert step becomes
  tag_text-column update; the FTS index maintains itself via the
  STORED generated column
- `web/_render-main-row.php` — `static` row queries are mostly portable
  (PDO abstracts dialect). Audit any `LIKE` / `LIMIT` / `RANDOM()` —
  postgres has `RANDOM()`, syntax matches.
- Search queries (wherever they live) — `MATCH 'foo'` becomes `tsv @@
  websearch_to_tsquery('english', 'foo')`. `websearch_to_tsquery` is
  the right choice (user-facing query string, tolerates messy input)
  not `to_tsquery` (strict syntax). Briefing said `to_tsquery`; we
  want `websearch_to_tsquery`.
- `bin/sync.php` / mu-plugin webhook receiver — same DSN swap

### What stays the same

- nginx snippet (`nginx-snippet.conf` in repo, `/etc/nginx/snippets/strangler-archive-poc.conf` deployed)
- FPM pool config
- `/archive-api/v0/_config` endpoint contract
- All render templates' HTML output shape
- The cookie-gate exempt path for `/archive-poc/`

## Coordinator answers received (2026-05-28)

Reply doc: [`docs/reply-to-archive-poc-5-questions.md`](../docs/reply-to-archive-poc-5-questions.md).
All five answered, all green-light. Summary:

a. **`edit_archive_poc` cap — archive-poc owns it.** Register as a real
   WP capability via a small mu-plugin (or fold into existing deploy)
   on plugin activation, grant to `administrator` by default. Poller's
   user-context endpoint calls `user_can($wp_user_id, 'edit_archive_poc')`
   generically; it doesn't need to know about our specific cap. Future
   "grant to non-admin editor users" is ours to decide unilaterally.

b. **Postgres role + DSN pattern — confirmed as proposed**, now folded
   into coord §3i. Secret at `/etc/lg-archive-poc-db` mode 640
   root:archive-poc; FPM pool env `LG_ARCHIVE_POC_DSN`; DSN form
   `pgsql:host=/var/run/postgresql;dbname=looth;user=archive_poc;password=<from-file>`
   (Unix socket, no TCP). Postgres role `archive_poc` owns the
   `discovery` schema.

c. **`pdo_pgsql` — explicit cutover-checklist item**, added by cutover chat.

d. **Timestamps — `TIMESTAMPTZ`, not `BIGINT`.** One-shot upgrade
   opportunity. ~30min mechanical render-template audit
   (`date('Y-m-d', $row['published_at'])` → DateTime parse + format).

e. **`/whoami` stub-tier window — confirmed** (render public, no
   overlays, no redirects). Important caveat: that window should be
   **near-zero on live** — cutover sequence ships poller user-context
   (step 0.5/1) and our `/whoami` switchover (step 5) in the same
   window, so `tier_unavailable: true` should never actually fire.
   Guard for it anyway, defense-in-depth.

**Status:** green-light on all prep work below. The irreversible
cutover-day bits wait on the cutover-chat plan locking in.

## Prep work — completed 2026-05-28

- [x] **`schema.pg.sql` drafted.** TIMESTAMPTZ throughout, BOOLEAN where
      SQLite used 0/1, `tag_text` denormalized column, `tsv` GENERATED
      STORED tsvector with weighted setweight() (title A, tag_text B,
      author_name C, body_text D), GIN index. Real FK constraints
      (SQLite had none).
- [x] **`discovery` schema stood up on dev postgres.** Role `archive-poc`
      (hyphenated, peer-auth) owns it — matches existing precedent of
      `bb-mirror` and `profile-app` roles. **NB:** coord doc §3i still
      writes role names with underscores (`archive_poc`); the dev
      reality is hyphenated. Flagging for coord-doc fix.
- [x] **Shared sync-writer grants applied** per coord §3i: `looth-dev`
      gets RWD + sequence access; `profile-app` gets SELECT for future
      cross-schema joins. Plus matching `ALTER DEFAULT PRIVILEGES` so
      future tables inherit. Grants folded into `schema.pg.sql` so the
      cutover-day DDL run is self-contained.
- [x] **`bin/backfill-pg.php` written** as a sibling to `backfill.php`.
      Driver-aware via `lg_archive_poc_pdo()` helper in `config.php`
      (env-driven DSN). `INSERT OR REPLACE` → `ON CONFLICT DO UPDATE`,
      `INSERT OR IGNORE` → `ON CONFLICT DO NOTHING`, unix epochs →
      `gmdate('c', $ts)` ISO 8601 strings, content_fts rebuild step
      removed (tsv is GENERATED STORED). PDO helper does
      `SET search_path = discovery, public` on connect.
- [x] **Dry-run backfill against dev postgres** ran clean (1830
      content_items, 1810 persons, 1690 tags, 4524 content_tag).
      See findings below.

### Invocation pattern (for the record)

```bash
cd /var/www/dev && sudo -u looth-dev \
  LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
  wp eval-file /home/ubuntu/projects/archive-poc/bin/backfill-pg.php
```

`looth-dev` is the WP-side sync writer (peer-auths to matching pg role,
has WP file read access via the `looth-dev` group). Matches bb-mirror's
precedent. The `archive-poc` OS user can't read `/var/www/dev` and the
`www-data` OS user has no matching pg role; `looth-dev` is the only
account that has both.

### Findings vs SQLite baseline

| Table | SQLite | Postgres | Δ |
|---|---|---|---|
| content_item | 1830 | 1830 | 0 |
| person | 1810 | 1810 | 0 |
| tag | 1690 | 1690 | 0 |
| content_tag | 4644 | 4524 | **−120 (PG correct)** |

**Tier counts differ by 3** (lite +3, public −3): WP metadata changed
between the SQLite backfill (yesterday) and PG run (today) for posts
69452/69454/69457. Not a backfill bug.

**content_tag delta is a SQLite bug that PG fixes.** SQLite stored
~120 orphaned `content_tag` rows pointing at tag IDs that never made
it into the `tag` table — caused by `INSERT OR IGNORE INTO tag` silently
dropping slug-conflicting rows while `$ttid_to_tag_id` still mapped to
the dropped IDs. SQLite tolerated the orphans (no real FK on
content_tag). PG's `ON CONFLICT (slug) DO UPDATE … RETURNING id`
correctly maps colliding ttids to the *winning* tag.id, and the real
FK would refuse orphans anyway. Net: PG tag-based queries will see
~120 previously-broken associations correctly resolved.

**FTS smoke test:** `tsv @@ websearch_to_tsquery('english', 'guitar
repair')` returned 437 matches in 8.5ms (planner picked Seq Scan at
1830 rows; GIN will engage at live scale). Matches the briefing's 8ms
estimate.

### Files added/changed this prep pass

- `schema.pg.sql` (new) — postgres DDL + grants
- `config.php` — added `lg_archive_poc_pdo()` helper
- `bin/backfill-pg.php` (new) — postgres-side backfill

`schema.sql`, `bin/backfill.php`, web/ render templates unchanged.
SQLite backfill still works as a rollback path.

## Step 2 complete: /whoami-backed gating (2026-05-28)

`lg_archive_poc_whoami()` added to `config.php` — static-cached loopback curl
to `/wp-json/looth/v1/whoami` with HTTP/1.1 (avoids ALPN timeout), 5s timeout,
`tier_unavailable` → `public` fallback. CLI-safe (returns null for bin/ scripts).

`index.php` uses `/whoami` for tier + caps, with safe fallback semantics:
- Only promotes `$is_member`/`$viewer_tier` when `/whoami` says `authenticated:true`
- When `/whoami` returns anon (user has WP cookie but no `looth_id` JWT yet),
  WP cookie values are preserved — no regression for pre-full-cutover logins
- `$edit_capable` = `capabilities.edit_archive_poc` (false until JWT present)
- `?as=` preview override still works and takes precedence

`_chrome.php` updated:
- Display name from `/whoami` `display_name` field (WP cookie parse as fallback)
- Tier pill added to account cluster: sage Lite, amber Pro, charcoal Admin
- Edit → `/wp-admin/` (new tab) shown when `manage_options` is true
- "Join free" → "Join" (no free tier)

`archive.css`: `.lg-chrome__tier` + `.lg-chrome__edit` styles added.

`mu-plugins/archive-poc-sync.php`: `edit_archive_poc` cap registered on
`administrator` role via `init` hook. `user_can($user, 'edit_archive_poc')`
now returns true for admins — poller emits it in capabilities map.

**Important finding surfaced to coordinator:** profile-app `/whoami` uses
`looth_id` JWT cookie (not WP cookie) for auth. On dev, WP-logged-in users
without a `looth_id` token get `authenticated:false` from `/whoami`. Our
fallback handles this safely (WP cookie values preserved). Full tier/caps
from `/whoami` will only work once the WP→profile-app JWT bridge is live
(profile-app's login integration, presumably cutover-sequence step or
slice 3.5 item). Flag to coordinator.

**Files changed:** `config.php`, `web/index.php`, `web/_chrome.php`,
`web/archive.css`, `mu-plugins/archive-poc-sync.php`

**archive-poc → coordinator:** step 2 complete, /whoami-backed gating live.
Fallback semantics safe for pre-JWT state. `edit_archive_poc` cap registered.
Finding: `/whoami` needs `looth_id` JWT — WP cookie alone returns anon.
Is the WP→profile-app JWT bridge part of the cutover sequence, or slice 3.5?

## Loopback perf fix (2026-05-29)

`CURLOPT_HTTP_VERSION_1_1` added to activity strip curl in `index.php`.
`/whoami` call switched from WP shim to profile-app direct (`/profile-api/v0/whoami`)
— WP shim had ~6s cold boot cost; profile-app direct is ~100ms.

Measured after fix (4 sequential requests, cold FPM):
- req1: 3.1s (archive-poc + WP both cold — WP activity times out at 3s, page renders without strip)
- req2: 2.15s
- req3+: **0.85s warm** ✓

Before: 5.3s cold / 2.7s warm. After: 3.1s cold / 0.85s warm.
Warm is now under 1s. Cold floor is WP FPM boot for activity strip (~2.5s) — not reducible from our lane.

**Note on `/whoami` direct call:** once profile-app ships the WP-session bridge
(trusted `X-LG-WP-User-Id` header path), the call may need to route back through
the WP shim (which runs inside WP context and can call `get_current_user_id()`).
OR profile-app exposes the bridge directly so we can pass the resolved user ID
ourselves with the internal secret. Flag to coordinator.

**Files changed:** `config.php` (whoami URL), `web/index.php` (HTTP/1.1 on activity curl)

**archive-poc → coordinator:** loopback HTTP/1.1 fix shipped. TTFB 0.85s warm.
Cold floor is WP activity boot (~3s), not reducible from our lane. Note: /whoami
now calls profile-app direct — will need revisit when WP-session bridge lands.

## Outstanding for cutover day

- Live postgres DDL + grants (run `schema.pg.sql` against live)
- Live `archive-poc` + `looth-dev` pg roles created (peer-auth)
- `apt install php8.3-pgsql && systemctl reload php8.3-fpm` on live FPM
- Switch FPM pool env to set `LG_ARCHIVE_POC_DSN=pgsql:…`
- Render-template audit for `date('Y-m-d', $row['published_at'])` —
  drops to DateTime parse + format (~30min mechanical)
- `bin/sync.php` / mu-plugin webhook receiver: PDO swap, same
  ON CONFLICT translation as the backfill
- `_render-main-row.php`: audit query strings for any non-portable
  syntax (MATCH → @@). N+1 audit deferred to post-cutover.

## Coord-doc fixes to surface

- §3i writes role names with underscores (`archive_poc`); actual
  precedent and dev reality use hyphens (`archive-poc`, matches
  `bb-mirror`, `profile-app`). Update doc to match.

## FE editor work — postponed, still planned

The FE editor sketch in `handoffs/2026-05-26.md` is still the target.
Re-enters after the postgres migration so save-path changes happen
once, not twice. Open decision #1 (activation gate) is resolved by the
coord shift to `/whoami`. Other open decisions (#2 schema location,
#3 drag-reorder lib, #4 atomic vs partial save, #5 fallback flag, #6
non-inline sections) carry over unchanged.

## Mobile-concurrency note worth carrying forward

Postgres-over-socket adds ~6ms per FTS query vs. SQLite in-process.
Main feed render does 5–8 such lookups, so ~40ms net server-side delta
per page render. Absorbable, but means N+1 patterns I've been getting
away with on SQLite want auditing as we port. Specifically the
per-row `static` queries in `_render-main-row.php` — worth checking
if any of them can fold into a single query with `UNION ALL` or
similar once we have a real query planner under us.

## Pointers

- archive-poc code: `/home/ubuntu/projects/archive-poc/`
- Prior handoff (FE editor design): `handoffs/2026-05-26.md`
- Even earlier (live deploy details): `handoffs/2026-05-25.md`
- Briefing: `/home/ubuntu/projects/docs/briefing-archive-poc-postgres.md`
- Coord doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`

## P3 shared header — reversed off our plate (2026-05-28)

P3 moved to `lg-shell` workstream. We are a consumer, not the owner.
Mockup at `shared/mockups/site-header.html` available to lg-shell.

Design decisions confirmed during our mockup pass (hand to lg-shell):
- Tier pill inside account cluster: sage **Lite**, amber **Pro**, charcoal **Admin**
- Anonymous CTA: "Join" (no free tier — Looth membership is paid-only)
- Admin Edit button → `/wp-admin/` new tab, gated on `manage_options`
- Search behavior: in-page (no change)

**No free tier:** `/whoami` tier vocab is `public | lite | pro`. `public` =
unauthenticated. No free account offering. Any copy saying "join free" is wrong.

## Ian's UX requests — received 2026-05-28

From `docs/reply-to-archive-poc-ux-requests.md`. None block cutover.

**#1 — Bare `/archive-poc/` landing page: DONE, nothing to build.**
Verified 200 on bare URL. The page already SSR-renders discovery rows
from `rows.json` without requiring a search query.

**#2 — Search modal with author + post-type facet detection: post-cutover.**
Significant work: new facet-detection endpoint + JS modal component.
Sequenced after postgres migration, alongside or after FE editor resumes.

**#3 — Tag filter pills (removable chips): post-cutover UX pass.**
Contained UI change to existing tag-filter surface. Standalone pass
after cutover lands.

### Bridge confirmed — step 2 fully verified 2026-05-28

WP-session auth bridge now live in `profile-whoami-shim.php`. `/whoami` returns
`authenticated: true` for WP-logged-in users without requiring a `looth_id` JWT.

Mechanism: shim calls `wp_validate_auth_cookie('', 'logged_in')` as fallback when
`get_current_user_id()` returns 0 (no nonce), passes resolved `wp_user_id` via
`X-LG-WP-User-Id` + `X-LG-Internal-Auth` trusted headers; profile-app calls
`Whoami::buildForWpUserId()` on that path.

**archive-poc → coordinator:** step 2 complete, /whoami-backed gating live.
Bridge verified end-to-end. `edit_archive_poc` cap registered on administrator
role. Full tier + capabilities now flow through for WP-logged-in users.

## UX pass shipped (2026-05-29)

### Search modal — faceted suggest
`archive.js` modal now calls `/archive-api/v0/search-suggest` (new endpoint)
instead of the flat `/search`. Returns three sections:
- **People** — author name fuzzy match from `person` table, links to
  `/archive-poc/search/?author=<id>` (no q — show all their posts)
- **Posts** — FTS results excluding discussions, "See all N posts →"
  links to `/archive-poc/search/?q=...`
- **Discussions** — FTS discussion-only results, "See all N →" links to
  `/archive-poc/search/?q=...&kind=discussion`

Chrome search bar focus/keypress opens the modal instead of syncing inline.
Modal Enter key navigates to `/archive-poc/search/?q=...`.

New files: `api/v0/search-suggest.php`, nginx route added.

### Dedicated search page — `/archive-poc/search/`
New `web/search.php`. Hidden `#topbar` form keeps archive.js state sync
working. Grid layout (filter rail + results) visible from the start.
Empty state (`#discover`) shows prompt when no query. All URL params
(`?q=`, `?kind=`, `?author=`) pre-activate the correct filters on load.

nginx routes `/archive-poc/search/` → `search.php` via `location ^~` block.

### Discovery page unchanged
`/archive-poc/` keeps its SSR editorial rows. Chrome search opens modal;
search results live on the dedicated search page.

### Shell nav item flagged
Relayed to lg-shell: needs "Archive" nav item linking to `/archive-poc/`
in the shared header partial spec.

## URL rename + activity-strip perf cache (2026-05-29)

### URL rename
- `/archive-poc/`        → `/front-page/`  (discovery feed)
- `/archive-poc/search/` → `/archive/`     (search/browse)
- Legacy `/archive-poc/*` → 301 redirects to new URLs
- Static assets still served from `/archive-poc/` path; PHP files reference
  `/archive-poc/archive.css` + `/archive-poc/archive.js` as absolute paths
  (relative paths break since the URL path no longer matches the file tree).
- nginx: dedicated `location ^~ /front-page/` and `/archive/` blocks pin
  SCRIPT_FILENAME to index.php / search.php. `include fastcgi.conf` MUST come
  before the SCRIPT_FILENAME override (it sets its own otherwise).
- Nav "Archive" link + search form action in `/srv/lg-shared/site-header.php`
  → `/archive/`. Back-link in `_chrome.php` → `/front-page/`.

### Activity-strip fragment cache — the real perf fix
**Root cause of slowness:** WordPress REST bootstrap is ~0.8s per call on this
box (BuddyBoss + plugins), regardless of WP's own 30s transient (that caches
the SQL, not the boot). The front page made a *blocking* curl to
`/wp-json/looth/v1/activity` on every render → every page paid ~0.8-1s.

**Fix:** `archive_poc_run_activity_strip()` now caches the items array to
`sys_get_temp_dir()/lg_actstrip_{m|p}_{limit}.json`, 30s TTL, keyed by audience
(member/public — same granularity as WP's transient, so no new staleness).
Only non-empty fetches are cached (a timeout never pins an empty strip).

**Result:** warm renders dropped from ~1.1s to **0.11s** (10x). Cold render
(once per 30s window) ~1.6s. `/archive/` (SQLite only) ~0.07s.

NB at pg cutover: this cache is independent of the SQLite→pg swap — it caches
the WP activity fetch, not discovery data. Leave it.

### Activity strip — upgraded simple cache → stale-while-revalidate (2026-05-29)
The plain 30s cache still blanked the strip on the expiry render (live fetch
could time out / return empty). Replaced with proper SWR in index.php:
- `archive_poc_run_activity_strip()` ALWAYS serves the cached items instantly,
  even when stale — strip never blanks once seeded.
- When stale, it `touch()`es the cache file (claim, so concurrent requests don't
  all refresh = no thundering herd) and queues a refresh job.
- `archive_poc_flush_activity_refreshes()` runs at the very end of index.php,
  after `fastcgi_finish_request()` — the WP fetch happens off the critical path,
  for the *next* visitor. Failed fetch → `touch()` to back off a full TTL.
- Only the first-ever load (no cache file) fetches synchronously to seed.
- Helpers extracted: `archive_poc_activity_cookie()`, `_cache_file()`, `_fetch()`.

Measured: warm/stale renders ~0.1s, first-ever seed ~1.2s, 5-concurrent-on-expiry
0.35–0.5s with zero blanking. Lighthouse perf 100.

## Search-modal handover to lg-shell — ACK'd (2026-05-29)
lg-shell is taking the search modal into `/srv/lg-shared/` so the header
magnifier opens it on every strangler surface, not just our page.
- Relay in: `docs/relay-to-archive-poc-search-modal.md`
- Reply out: `docs/reply-from-archive-poc-search-modal.md` (ack + gotchas + suggest contract)
- They take: modal HTML/CSS + the `initSearchModal` IIFE JS. We keep: search +
  suggest API (untouched), sidebar CTA, our `/archive/` grid rendering.
- Migration not done yet — they sequence it. When they cut, EXPECT our
  `web/index.php` to lose `#search-modal` markup and `archive.js` to lose the
  modal IIFE + the chrome-search shims (3 spots: ~518 focus, ~685 legacy, ~800
  chromeSearchToModal). Don't be surprised when those vanish from our files.
- Contract we must hold stable for them: modal navigates to `/archive/?q=…`
  (+`&kind=`/`&author=`) and calls `/archive-api/v0/search-suggest`. Suggest is
  intentionally NOT tier-filtered (titles discoverable, bodies gated).

## Activity scroll polish + /archive/ search bar + front-page perf (2026-05-29)
- **Scroll-hint pager removed** — the charcoal chevron button (and its CSS +
  `lg-scroll-hint-pulse` keyframe) is gone. Kept only the load **wiggle**:
  a quick damped rAF tween (|sin| over 1.5 cycles, ~420ms) on the activity
  rail's scroll axis. Skipped under reduced-motion. (`wiggleOnLoad` in archive.js;
  `railAxis` helper retained.)
- **/archive/ plain search bar** — the dedicated search page now has a visible
  rounded search bar at the top (`.arc-searchbar`, wraps the `#q` input that was
  previously hidden). archive.js already binds `#q` for live debounced results.
  `#sort` stays hidden (rail's `#sort-rail` is the visible sort).
- **Front-page perf — deferred two ~4s client WP fetches OFF the load path:**
  - Like-button identity (`/wp-json/looth/v1/whoami` via WP shim) was fetched
    eagerly on load. Now lazy (`ensureMe()`): prefetched on first hover over the
    activity strip OR `requestIdleCallback`, awaited on click as fallback.
  - Chrome message/notif badge counts: first fetch moved from `setTimeout 200ms`
    → `requestIdleCallback`.
  Result: window load 3.6s → 2.9s; the page is interactive immediately instead of
  waiting on two cold WP-REST calls. Document TTFB was already fast (~0.13s warm,
  both audiences) — the regression was purely these client fetches under WP-FPM
  contention. Featured-video YouTube iframe (~1.3s) already has loading="lazy";
  left as-is (third-party, in-viewport).

## Activity feed showing only 1 item — over-fetch starvation fix (2026-05-29)
Symptom: front-page activity strip loaded with just 1 card (sometimes a handful).
Root cause: NOT the SWR cache (it faithfully stored whatever WP returned) and NOT
gating — the WP `lg_activity_route` over-fetched only `limit*3+5 = 50` recent
activity rows, then dropped any whose target post was deleted. Dev has heavy
test-post churn (HARNESS/DELTEST create+delete), so ~49 of the 50 most-recent
activities pointed at dead posts → 1 survivor. Proven: limit=15→1 item,
limit=40 (deeper over-fetch)→40 items.
Fix (`mu-plugins/archive-poc-sync.php`, `lg_activity_route`): over-fetch floor
raised to `max(limit*3+5, 120)`, capped at 250. Now returns a full 15.
Hydration is heavier per WP fetch but it's cached (30s) and off the critical
path (SWR background refresh), so document TTFB stays ~0.16s warm.
NB on live: far less dead-post churn, so the strip wouldn't have starved there —
but the deeper floor is harmless and robust. The activity endpoint is archive-poc's
own surface (feeds our strip), so this is in-lane despite living in the WP mu-plugin.

## Archive page: "People" type tab + no-scroll filter toggles (2026-05-29)
- **Filter toggles no longer scroll the page.** `applyAndFetch()` default flipped
  to `scroll:false` — typing in the search bar and toggling tabs/tags/tiers no
  longer yanks the page to the top. Only pagination scrolls (its own `goToPage`
  scrollIntoView). Opt-in `scroll:true` still available if ever needed.
- **"People" added to the TYPE list** (`/archive/` rail). It's a pseudo-kind
  (`state.kind==='people'`) — never sent to the API as a kind; instead the view
  renders matching authors as cards (avatar + name + post count) from the search
  endpoint's author facet. Clicking a person → leaves People view and shows ALL
  their content (drops the name query, matching the search modal's People links).
  - Backend: `search.php` author facet now LEFT JOINs `person` for `avatar_url`
    + `slug` so the People cards render rich (parity with the modal).
  - Frontend: `renderTabs` appends the People tab (count = author-facet length);
    `renderPeople()` renders `.person-card`s; `fetchSearch` branches on
    `kind==='people'` (hides pagination, meta shows "N people"); URL carries
    `?kind=people` for shareable/restorable state.

## People tab: pagination + posts/discussions-only (2026-05-29)
- **Paginated server-side.** `search.php` now takes `?people=1` → returns a
  `people` array (LIMIT/OFFSET via the standard limit/offset params) plus
  `people_total` (always computed, drives the People tab count accurately —
  no longer capped at the 20-row author facet). Frontend: on the People tab,
  `state.total = people_total`, renderPagination() runs (was hidden before),
  goToPage paginates people. Verified: 399 contributors, 24/page, page-nav works.
- **Only people with posts or discussions.** The people query filters
  `ci.kind NOT IN ('benefit','event')` — sponsors (benefit) and event-only
  authors are excluded; counts reflect post/discussion items only. (DB kinds:
  discussion, video, loothprint, misc, article, benefit, event — so "posts or
  discussions" = everything except benefit + event.)
- Frontend bits: `state.peopleTotal`, fetchSearch sends `people=1` on the tab,
  renderTabs People count uses `peopleTotal`.

## Forum links → bb-mirror, comprehensively (2026-05-30)
Last session only fixed the activity ENDPOINT's topic links. The discovery rows
+ search were still legacy because `content_item.url` in SQLite stored the old
BB permalink. Fixed at the indexing layer so EVERY archive-poc surface emits
bb-mirror `/forum/<forum-slug>/<topic-slug>/`:
- `bin/indexer.php` (incremental _sync), `bin/backfill.php` (SQLite full),
  `bin/backfill-pg.php` (pg cutover): discussion items now build the bb-mirror
  URL from immediate-forum post_name + topic post_name (verified slug parity).
- Re-ran `sudo -u www-data php bin/backfill.php` → 1167 discussions reindexed,
  0 legacy URLs left in SQLite. Search + discovery rows now all /forum/.
- `mu-plugins/archive-poc-sync.php` activity hydrate: also handles post_type
  'forum' (pinned forum → /forum/<slug>/) in addition to 'topic'.
- `web/_chrome-footer.php`: footer "Forums" link → /forum/.
- Verified: front page has 0 legacy forum links; sample /forum/ URLs return 200.

Not changed (not forum content): a `sponsor-page` CPT ("Strings Micro Factory")
whose WP permalink is forum-shaped links to its own page — correct. And
`config.json`'s "Suggestion box" CTA hardcodes a loothgroup.com (LIVE) forum
URL — dash-managed copy, left alone.

**Contract for bb-mirror:** archive-poc deep-links now hard-depend on
bb-mirror's forum.slug/topic.slug == bbPress post_name. If they ever re-slug,
these links break → would need a shared URL builder/shim. Worth putting on record.

## Activity strip: media facade for YouTube (2026-05-30)
Activity cards whose topic BODY links a video showed the raw URL as text. Now
the activity endpoint (`lg_activity_hydrate_post`) extracts a YouTube id from
`post_content` and, if there's no featured image, uses the free YouTube
thumbnail (i.ytimg.com/vi/<id>/hqdefault.jpg) + sets `yt_id`. The card renders
as an image card with a play overlay (`acard--youtube`) — a FACADE: just an
image, no iframe, no oEmbed, so warm TTFB stays ~0.09s. Both SSR
(_render-main-row.php) and client load-more (classifyActivity) already honor
`yt_id`. Bare media URLs (youtube/instagram/reddit) are now stripped from the
excerpt so cards don't show raw links.
- Instagram: detected + URL stripped, but NO thumbnail (IG oEmbed needs an app
  token — deferred; would be the slow path).
- Scope = activity strip only. Discovery rows / search (SQLite thumb_url) could
  get the same YT-thumbnail treatment in the indexer if wanted — not done yet.
- Optional follow-up: click-to-play inline (swap thumbnail→iframe on play-button
  click) instead of navigating to the topic. Not built (keeps it a pure facade).

## Activity strip: inline click-to-play YouTube (2026-05-30)
Play button on YT activity cards is now a real <button data-yt-play="<id>">.
Clicking it swaps the thumbnail for an autoplaying <iframe.acard__video> inline
(height captured from the wrap so footprint is unchanged) and cancels the card's
<a> navigation. Still a facade — iframe only created on click, so load stays
fast. Both SSR (_render-main-row.php) and client load-more (renderActivityCard)
emit the button; handler is a delegated rail listener in archive.js. CSS:
.acard__play is now clickable (pointer-events restored); .acard__video added.

## Video rails: YouTube facade + inline play (2026-05-30)
Extended the activity-strip video facade to the discovery RAIL cards (rcards).
- `_rowlib.php` (archive_poc_run_row): for kind='video', extract a YouTube id
  from the already-selected `body_text` (cheap regex, videos only) → `$it['yt_id']`.
  No schema change, no re-backfill needed (body_text was already in the query).
- `_render-card.php`: renders a `.rcard__play[data-yt-play]` button on video
  cards when a yt_id is present AND the card isn't gated (gated videos still
  just link to the page — no inline bypass).
- `archive.js`: document-level delegated handler swaps the thumbnail for an
  autoplaying iframe inline (`.rcard__video`), cancels the card <a> nav.
- `archive.css`: `.rcard__play` / `.rcard__video` reuse the acard play styling.
- rows-more AJAX reuses render_rcard + run_row, so lazy-loaded rail cards get it too.
Runtime cost ~zero: facade only (thumbnail already loads; iframe on click). ~13%
of videos have no extractable YT id (Vimeo/self-hosted) → those just link out, no button.

## Activity strip: inline body images (2026-05-30)
Activity hydrate now falls back to the first inline <img> in the post body when
there's no featured image and no YouTube id — forum posts embed uploaded photos
(fluentform/BB media), so those cards now render as image cards. Skips
emoji/avatar/spacer/icon sprites; applies the R2-graveyard guard (drop dev-local
images whose file is missing/empty). Fallback order: featured image → YouTube
thumb → first body image → text card. Pure facade (browser lazy-loads the image),
warm TTFB unchanged (~0.13s). Applies to all hydrated items, not just topics.

## Activity strip: BuddyBoss media images (2026-05-30, fix)
The prior "inline body image" change didn't help forum posts because forum
topics store photos as BuddyBoss MEDIA attachments (bp_media_ids meta), not
inline <img> — the post_content is typically empty. Added
`lg_activity_first_bp_media_thumb()` to the mu-plugin (mirrors the indexer's
first_bp_media_thumb: bp_media_ids → wp_bp_media.attachment_id →
wp_get_attachment_image_url). New image fallback order in lg_activity_hydrate_post:
featured image → YouTube thumb → BB media → inline body <img> → text.
Verified: fresh topic "fsdfs" (id 69625, empty body, bp_media_ids=3163) now
renders its photo (bb_medias/.../images-2.jpeg, 200); activity strip went from
~0 to 6 image cards. Still a facade (lazy <img>), speed unchanged.

## Player gating + one-at-a-time (2026-05-30)
- **Tier overlays confirmed intact** across changes (front page: 102 gated as
  public, 0 as pro). rcard play buttons already gated on !is_gated.
- **Closed an inline-play leak:** activity SSR card (_render-main-row.php) now
  only renders the play button when `!$is_gated` (a lite member viewing pro
  content can no longer click-play a gated video). Client load-more renderer
  (renderActivityCard) was ungated entirely — now computes is_gated from
  `window.__LG_VIEWER_TIER__` (newly emitted in index.php), adds the gated
  overlay class, and suppresses the play button on gated items.
- **One player at a time:** unified the acard + rcard play handlers into one
  document-level listener. The iframe now OVERLAYS the thumbnail (position:
  absolute, kept in DOM) and opening a new player tears down the previous
  (module-scoped `active`). Verified: 1 iframe max.

Note (honest gating model): hrefs and ungated YouTube ids are in the page HTML
— readable via inspector. The overlays are a discovery-layer visual gate; real
access control is server-side at the content (WP membership / bb-mirror). The
fix prevents click-to-play of gated video, but truly private video must be
unlisted/private on YouTube + server-gated, not relied on by hiding the id.

## Leak-free gated video — thumbnail id guard (2026-05-30)
Closed the last residual id exposure: a gated activity card whose image was the
YouTube thumbnail (i.ytimg.com/vi/<id>/…) leaked the id via the img URL. Now both
SSR (_render-main-row.php) and client (renderActivityCard) swap a gated card's
ytimg thumbnail for the generic fallback. Net: a non-entitled viewer receives NO
video id anywhere (no play button, no ytimg thumb) — rails were already clean.
Caveat recorded earlier stands: if the underlying YouTube video is public/unlisted,
true secrecy still requires private/unlisted-on-YouTube + server auth; this makes
the on-site gate leak-free, not the video itself.

## Rail video cards: real YouTube thumbnails (2026-05-30)
_render-card.php now uses i.ytimg.com/vi/<yt_id>/hqdefault.jpg for ungated video
rcards (yt_id from _rowlib body extraction); gated cards keep thumb_url/fallback
so no id leaks. onerror still falls back to LG_FALLBACK_IMG. Activity strip
already used YT thumbs; rails now match.
