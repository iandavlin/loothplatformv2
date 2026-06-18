# Hub Audit — both layers, under Ian's control

**Status: AUDIT ONLY. Nothing fixed.** Awaiting Ian's approval before any change.
Date: 2026-06-15. Lane: Hub (Buck's work folded in — no buck coordination).
Verified by curl/inspection on dev1 (this box). dev2 items flagged for Ian to run.

---

## 1. The two-layer model (why "fixed, pushed, still broken")

The hub is rendered by **two layers that both touch the same surfaces**:

1. **GIT SERVER (bb-mirror)** — PHP at `/srv/bb-mirror` → `/home/ubuntu/worktrees/bespoke-cutover/bb-mirror`, branch `bespoke-cutover`. Serves `/hub/` (server-rendered feed + topic HTML) and the `/bb-mirror-api/v0/*` endpoints. **In git.**
2. **OUT-OF-GIT OVERLAY** — JS/CSS in `/var/www/dev/*.js` (owner `buck:loothdevs`), hot-edited in the webroot, **NOT in git**. `pwa.js` injects them on `/hub` and re-renders cards/modals **client-side on top of** the server HTML.

A server-only fix lands in git but the overlay re-renders over it client-side → the fix never reaches the modal/cards. That split is the root of the "tested, pushed, still broken" pattern.

**Page load order on `/hub`** (from `pwa.js`): `site-header.css` → `/hub/forums.css` → `mobile-hub.css` → `pwa.js` → `/hub/forums.js` → `/hub/hub-filters.js` → then `pwa.js` injects the overlay (`app-settings.js`, `hub-polish.js`, `hub-infinite.js`, `sponsor-cards.js`, `hub-nojump.js`, and `mobile-hub.js` if ≤640).

**There are TWO different modals — keep them straight:**
- **/hub modal** (in-feed "N replies") — rendered by `hub-polish.js`, fetches the fork's own `/hub/?body=<id>` + `/?replies=<id>`. Does **not** call bb-mirror-api except for the auth nonce.
- **Front-page modal** (on `/`, the new front) — rendered by `archive-poc/web/fp-discuss.js`, fetches **`/bb-mirror-api/v0/topic`**. This is the one that says "Couldn't load" when that route 404s.

---

## 2. File-by-file map

### Layer 1 — GIT SERVER (bb-mirror), all in `bespoke-cutover`

| File | Server/Client | Route | Owns | Notes |
|---|---|---|---|---|
| `web/forums/_feed.php` | Server HTML | `/hub/`, `/hub/<slug>` | Hub feed cards, sort (new/old/hot/random), filters, mute, teaser gating | Reads `t.content_html`; teaser model, full body behind "Read more" → `?body=` |
| `web/forums/_single-topic.php` | Server HTML | `/hub/<slug>/<topic>/` | Permalink page: OP + threaded replies | Reply tree depth cap 4 desktop / 2 mobile; reply form POSTs to `/wp-json/buddyboss/v1/reply` |
| `web/forums/_topic-body.php` | Server HTML fragment | `/hub/?body=<id>` | Full OP body expansion (feed "Read more" + hub modal) | Single source = `content_html`; resolves `{{mention}}`; anon contact-scrub |
| `web/forums/_reply-render.php` | Server HTML (helper) | — | Reply stub markup, avatars, reaction chips, edit/delete UI | Reads reply `content_html`; reaction counts from `discovery.card_reactions` |
| `web/forums/_topic-replies.php` | Server HTML fragment | `/hub/?replies=<id>` | Full reply thread, newest/oldest, paginate-by-5 | Tree flattened to 2 visual tiers |
| `web/forums/index.php` | Server HTML | `/hub/` (index) | Forum directory grouped by category | No content_html |
| `web/forums/_filter-rail.php` | Server HTML | sidebar | Type/Category/Author facets + mute switches + search box | — |
| `web/forums/_search.php` | Server HTML | `/hub/?q=` | FTS over titles+bodies, ranked snippets | tsvector `search_doc` |
| `web/forums/_suggest.php` | JSON | `/hub/?suggest=hub|author` | Autocomplete (titles, authors) | Author mask = visibility + discussion_visibility |
| `web/forums/_chrome.php` | Server HTML | wrapper | Header/footer/nav, injects `LG_FORUM_BASE` | Defers to canonical site-header |
| `web/forums.css` | Client CSS | — | Card/topic/reply design, gallery, reaction chips | Responsive grid |
| `web/forums.js` | Client JS | — | Reply-stack expand (`?replies=`), body expand (`?body=`), composer image tray | Tray gated `min-width:641px` |
| `web/hub-filters.js` | Client JS | — | Sidebar facet clicks, live `q=`, author autocomplete | Debounced 160ms |
| `web/img.php` | Image server | `/img.php?w=` | On-the-fly WebP resizer + cache | Buckets 96/240/480/400-1200; `_rzcache` |
| `api/v0/auth.php` | JSON | `/bb-mirror-api/v0/auth` | Mint wp_rest nonce + viewer state | **Runs on `looth-dev` FPM pool** |
| `api/v0/topic.php` | **Server HTML fragment** | `/bb-mirror-api/v0/topic?forum=&topic=` | **Front-page discuss modal hydration** | **Runs on `bb-mirror` FPM pool**; returns `lg-fpd-op` fragment |
| `api/v0/reply.php` | JSON | `/bb-mirror-api/v0/reply` POST/PUT/DELETE | Reply **create / edit / delete** | Wraps BuddyBoss `rest_do_request` in-process; **runs on `looth-dev` pool** |
| `api/v0/unread.php` | JSON | `/bb-mirror-api/v0/unread` | Batch unread check | looth-dev pool |
| `api/v0/mark-seen.php` | JSON | `/bb-mirror-api/v0/mark-seen` | Record last-read | looth-dev pool |
| `api/v0/set-forum-image.php` | JSON | `/bb-mirror-api/v0/set-forum-image` | Admin forum header image | looth-dev pool |
| `api/v0/_sync.php` | internal | `/bb-mirror-api/v0/_sync` | WP→PG sync receiver | looth-dev pool, not user-facing |
| `lib/materializers.php` | sync lib | — | Upsert topic/reply: `content_html`=`wp_kses_post(...)`, `content_text`, author, attachments, visibility | **No wpautop** — HTML arrives from WP |
| `bin/backfill.php` | one-shot | — | Full walk of wp_posts → PG, same fields | Real reply counts; orphan-gate |

### Layer 2 — OUT-OF-GIT OVERLAY (`/var/www/dev/*.js`, owner buck:loothdevs)

| File | Size | Desktop/Mobile | Owns |
|---|---|---|---|
| `pwa.js` | 18K | both | **The injector** + service worker. Route-gates on `/hub`, viewport-gates ≤640. Decides what loads. |
| `hub-polish.js` | **270K** | both | **The big one.** Re-renders feed cards client-side, the /hub discussion sheet/modal, reactions, composer. Owns card inline-expand + modal. |
| `hub-infinite.js` | 10K | both (wider margin desktop) | Infinite scroll append |
| `hub-nojump.js` | 3.6K | both (280/200px) | Cover-image placeholder to stop scroll-jump |
| `mobile-hub.js` | 7K | ≤640 only | Kill compact mode, long-press (380ms) reactions |
| `mobile-hub.css` | 8.5K | `@media ≤640` | Mobile card chrome, grid layout, forces Light theme |
| `app-mobile-fixes.js` | 27K | ≤640 site-wide | Footer/sort-bar/text-scale fixes, video orient lock, profile reorder |
| `app-settings.js` | 30K | both site-wide | Theme/font/text-size; drives `--lguser-scale` hub uses |
| `sponsor-cards.js` | 11K | both | Spotlight sponsor cards in feed |
| `guitardle-teaser.js` / `gdle-side-art.js` | 15K/1.4K | both | Guitardle strip / side art |
| `bottom-nav.js` | 44K | mobile + desktop gear | Mobile tab bar |
| `directory-desktop.js` / `directory-mobile.js` | 40K each | split | Members map directory (NOT hub) |
| `push.js` / `sw.js` | 11K/3K | both | Web push (flagged off) / service worker |
| *(others: events-*, profile-sheet, privacy-sheet, sponsor-sheet, messenger-sheet, practice-sheet, loothalong, shop-bubble)* | — | — | Non-hub sheets; listed for completeness |

**Front-page modal consumer lives in a different lane:** `archive-poc/web/fp-discuss.js` (`TOPIC_API = '/bb-mirror-api/v0/topic'`, line 24/218). It is part of the **archive-poc / dev2-cut lane (do not touch)** but it *calls our bb-mirror endpoint*. Boundary: we own the endpoint, that lane owns the caller.

---

## 3. Behavior → owner map

| User-visible behavior | Owner (file / layer) |
|---|---|
| Feed cards (initial) | **Server**: `_feed.php` (git) → then **re-rendered by `hub-polish.js`** (overlay) |
| Card inline expand ("Read more" → unclamp in place) | `hub-polish.js` `wireExpand()` (overlay) toggles `.lg-unclamp`; full body fetched from `/hub/?body=` (git) |
| /hub discussion modal (in-feed "N replies") | `hub-polish.js` `openRepliesSheet()` (overlay); body from `/hub/?body=`, replies from `/?replies=` (git) |
| Front-page discussion modal (on `/`) | `archive-poc/fp-discuss.js` (other lane) → `/bb-mirror-api/v0/topic` = `api/v0/topic.php` (git) |
| New TOP-LEVEL post (topic) | **Legacy BuddyBoss only** — no bb-mirror topic-create endpoint. Mirrored into hub via sync. *(see Known Issue 4)* |
| Reply create / edit | `hub-polish.js` composer → `/wp-json/buddyboss/v1/reply` (legacy), OR `/bb-mirror-api/v0/reply` (git, wraps BuddyBoss) |
| Reply delete | `api/v0/reply.php` DELETE (git) — **admin/`can_edit_others` only** in modal UI |
| Reactions / likes | `_feed.php` + `_reply-render.php` render from `discovery.card_reactions`; long-press picker = `mobile-hub.js` |
| Filters / search / suggest | `_filter-rail.php` + `hub-filters.js` (git) → `_search.php` / `_suggest.php` |
| Infinite scroll | `hub-infinite.js` (overlay) |
| Scroll-jump prevention | `hub-nojump.js` (overlay) |

---

## 4. Desktop / mobile boundary

- **Primary breakpoint: 640/641px.** `pwa.js` computes `mobileish = matchMedia('(max-width:640px)') || (coarse pointer && ≤1366px)` and only injects `mobile-hub.js` when mobile.
- `mobile-hub.css` = `@media (max-width:640px)`; `forums.js` composer image tray = `min-width:641px`; `app-mobile-fixes.js` ≤640.
- Server-side: `_single-topic.php` caps reply tree depth 4 (desktop) / 2 (mobile).
- **Guarding:** `mobile-hub.css` re-declares card chrome to drift-proof against forums.css; `app-mobile-fixes.js` duplicates some lane CSS as a live-domain guard (a stale copy there can override a correct lane rule — known footgun).

---

## 5. Per-box deploy state

| Item | dev1 (this box) | dev2 (launch candidate — Ian to verify) |
|---|---|---|
| `/hub/` feed | **200 ✓** (verified) | verify |
| `/bb-mirror-api/v0/topic` | **200 ✓** (verified, behind cookie gate; returns `lg-fpd-op` fragment) | **reported 404** — front-page modal "Couldn't load" |
| nginx snippet | `/etc/nginx/snippets/strangler-bb-mirror.conf` → `projects/platform/nginx/strangler-bb-mirror.conf`; has the `topic` rewrite + `bb-mirror` FPM sock | snippet likely missing the `topic` rewrite/location or wrong sock |
| FPM pools | `topic.php`→`php8.3-fpm-bb-mirror.sock`; `auth/reply/...`→`php8.3-fpm-looth-dev.sock` | verify both pools exist |

**Nginx snippets are flat copies, NOT in git** (`strangler-bb-mirror.conf` is a symlink into `projects/platform/nginx/`, but the per-box copy can drift). The dev1↔dev2 difference is a **deploy/route gap**, not a code gap.

**Command for Ian to run on dev2** to pin the 404:
```bash
curl -sk -b "loothdev_auth=<dev2-token>" -o /dev/null -w "%{http_code}\n" \
  "https://dev2.loothgroup.com/bb-mirror-api/v0/topic?forum=acoustic&topic=<any-real-topic-slug>"
sudo grep -n "bb-mirror-api/v0/topic" /etc/nginx/snippets/strangler-bb-mirror.conf
systemctl status php8.3-fpm-bb-mirror 2>/dev/null | head -3
```

---

## 6. Overlay → git: what must be folded in (+ drift)

Confirmed against the injector (`pwa.js`) + a hub-touchpoint grep over all 24 overlay files. To make git canonical (per `project_buck_out_fold_in`), these live overlay files need capturing INTO git:
- **Must fold — hub-only** (pwa.js injects ONLY on `/hub`): `hub-polish.js`, `hub-infinite.js`, `hub-nojump.js`, `sponsor-cards.js`, `mobile-hub.js`, `mobile-hub.css`.
- **Must fold — site-wide but hub depends on them:** `pwa.js` (the injector), `app-settings.js` (drives `--lguser-scale`/theme used by cards), `app-mobile-fixes.js` (mobile sort-bar/text-scale), `bottom-nav.js` (header gear), `sw.js`.
- **NOT a hub layer (do not fold as hub):** `directory-*`, `events-*`, all `*-sheet.js`, `loothalong.js`, `push.js`, `shop-bubble.js` (0 hub coupling, not injected on /hub). `guitardle-teaser.js` has 4 hub refs but they are self-exclude guards — pwa.js does NOT inject it on /hub (consistent with "Hub teaser OFF").
- **Drift warning:** `project_overlay_fork_drift` notes the `bespoke-cutover` overlay fork (`hub-overlay-flag/*.js`) is ~18 versions stale vs the LIVE `/var/www/dev/*.js`. **LIVE webroot is the source of truth** for the overlay — capture live → git, never cp the stale git fork over live.
- Symlinked layer (`bb-mirror/**`) has no drift (it IS git).

---

## 7. Known issues — located & attributed (NOT fixed)

1. **Cards pop open / balloon** → overlay. `hub-polish.js` `wireExpand()` toggles `.lg-unclamp` (`-webkit-line-clamp:unset`), expanding the clamped excerpt **in place** in the card. Client-side; git can't reach it.
2. **Modal = wall of text** → overlay-fed data. The modal body comes from `/hub/?body=` / `/bb-mirror-api/v0/topic` = `content_html`. The git wpautop fix was **reverted** (§8), so raw rows with `\r\n` and no `<p>` render as one block. Both the render path (overlay clones server HTML) and the data (mixed) contribute.
3. **Front-page modal "Couldn't load"** → route gap. `fp-discuss.js` → `/bb-mirror-api/v0/topic`: **200 on dev1, 404 on dev2.** Endpoint code is fine; dev2's nginx snippet/FPM pool is the gap. (§5)
4. **New posts go to legacy forum, not hub** → there is **no topic-create endpoint in bb-mirror**. New top-level posts are created through legacy BuddyBoss and only appear in the hub after sync/materialize — and land in whatever `forum_id` the legacy composer targets. Attribution: topic creation is 100% legacy + sync; the hub is read-mirror for topics (only *replies* have a bb-mirror write path).
5. **No delete control in the modal** → `hub-polish.js` renders the trash button only inside `.feed--can-moderate` (admin `can_edit_others`); own-post delete is explicitly stubbed ("needs author id on the row — server change, queued"). So regular users see no delete. The DELETE endpoint (`api/v0/reply.php`) exists; the UI gating is the gap.
6. **(implicit) "fixed/pushed/still broken"** → the §1 split-brain. Any card/modal/body fix must land in `hub-polish.js` (overlay), not just git.

---

## 8. Today's mess — TRUE current state (code + data)

**CODE (dev1 worktree): CLEAN, reverted.** The prompt said "dev1 worktree is currently DIRTY" — it is **not**. `git status` = clean, branch up to date with origin. The two wpautop commits were both reverted on top:
```
5fd44bd Revert "wpautop synced content_html so topic/reply paragraphs survive"   (reverts 9510cbf)
b656d2b Revert "route forum descriptions through bb_mirror_content_html too"       (reverts def57f8)
def57f8 / 9510cbf  ← the wpautop attempt, now reverted
```

**DATA (PG `forums.topic.content_html`): MIXED / inconsistent.** Code revert does NOT un-backfill data. Of 1284 topics: **1010 start with `<p>`, 274 do not** (raw `\r\n`, e.g. id 71655 "Hey,\r\n\r\nI'm working on..."). So some rows carry paragraph HTML and some are raw text. The raw rows are the "wall of text" rows. This inconsistency is the residue of the push→backfill→partial-revert on dev1 (and separately on dev2 — Ian to confirm dev2's data state, which may differ).

**Net:** the wpautop approach is gone from code, but the data it wrote is partly still there on dev1. A real fix needs to decide the canonical paragraph strategy (server materializer vs overlay render) AND reconcile the data — but **not until Ian approves.**

---

## 9. Buck's home — uncaptured hub work (added after Ian: "look in buck's files")

The §1–§6 audit captured the **deployed** webroot (which is actually the NEWEST: live = `hub-polish v199`, `app-settings v32`, `app-mobile-fixes v36`, `bottom-nav v21`, `hub-infinite v4`). But Buck's home holds additional hub material that is NOT in the report above and NOT on origin:

### 9a. Unpushed git commits — scattered across 6 branches in 2 clones (NONE on origin)
| Clone / branch | State vs origin | Tip / hub content |
|---|---|---|
| `~buck/bespoke-cutover` @ `bespoke-cutover` | **ahead 15**, behind 25 | 15 "hub(overlay)" + shop commits folding overlay→git, up to **hub-polish v171** (older than live v199) — search-in-place, heart→thumbs-up, ultrawide cap, DM entry points, composer un-wedge |
| `~buck/looth-platform` @ `buck/hub-desktop-bar` | **ahead 6** | up to **hub-polish v195** + amf v35: mobile video landscape/inline, dmodal mobile parity, desktop New-post pill on sort bar, banner removal |
| `~buck/bespoke-cutover` @ `buck/social-modal-dark` | ahead 1 | app-settings v32 dark messenger drawer |
| `~buck/bespoke-cutover` @ `buck/lp-gate-note` | ahead 1 | hub-polish loothprint tier-gate honesty |
| `~buck/bespoke-cutover` @ `buck/amf-secmove` | ahead 1 | amf v29 profile Move up/down |
| `~buck/bespoke-cutover` @ `buck/sheet-secmove` | ahead 1 | profile-sheet v7 Move up/down |
| `~buck/bespoke-cutover` @ `buck/shop-tab-modal` | ahead 1 | shop-bubble v21 |

**Key insight:** Buck's committed branches are all BEHIND the live webroot (v171/v195 < v199). So **live is canonical; his commits are a trailing record.** Their value = the commit *messages* (the WHY behind each version) and any intermediate piece the live capture doesn't carry. Folding plan stands: capture LIVE → git, then harvest unpushed commit history/docs for context. None of this is pushed — it dies if his box is wiped.

### 9b. Deployed-but-out-of-git server piece (NOT in my §2 map)
- **`bb-forum-author-delete.php`** mu-plugin — DEPLOYED at `/var/www/dev/wp-content/mu-plugins/bb-forum-author-delete.php`, but **NOT in git** (`/srv/bb-mirror/platform/mu-plugins/` lacks it). This is the **server backend for forum author-delete** — directly relevant to Known Issue #5 ("no delete control"): the cap/endpoint side may exist here while the modal UI gates it to admins. Must be folded into git too.

### 9c. Two front-page variant working trees (flat copies, NOT git repos)
- `~buck/fp-mapnav/` and `~buck/fp-classic/` = Buck's two front-page design lanes (map-nav front page vs classic landing). Each contains a **flat (non-git) copy** of `bb-mirror/web/` (forums.css/js, hub-filters.js) + a `live-webroot-capture/2026-06-06/` snapshot of hub-polish/hub-infinite + `platform/mu-plugins/`. These are scratch/snapshot, not authoritative code — but they hold the spec docs below.

### 9d. Hub spec / intent docs (the requirements — read BEFORE fixing)
In `~buck/fp-{mapnav,classic}/docs/`: **`HUB-EXPECTED-BEHAVIOR.md`**, `hub-filter-nav-spec.md`, `hub-deploy-roadmap.md`, `hub-up-to-speed.md`, `briefing-hub-card-density.md`, `hub-anon-and-workflow-tags-FORM38.md`, `rebrand-forum-to-hub.md`, `handoff-hub-userdb-drift{,-followup}.md`, `coord-bb-mirror-hub-ui-commit-blocker.md`. These define what the hub is *supposed* to do — the source of truth for "expected" vs the bugs. Not yet read in this audit; recommend ingesting before any fix.

### 9e. IAN'S RULING (6/15): no new features from Buck's files — "just make what we have work."

This **discards 9a's harvest plan.** We do NOT pull Buck's unpushed feature branches (search-in-place, DM entry points, ultrawide cap, mobile video, etc.). "What we have" = the **LIVE deployed overlay (v199 set), already audited in §1–§6** — and it is the NEWEST, so nothing in Buck's git is more current. His branches are ignored.

**Revised fold list (per Ian's ruling):**
1. Freeze the **LIVE deployed files as-is** (hub-polish v199, app-settings v32, app-mobile-fixes v36, bottom-nav v21, hub-infinite v4, hub-nojump, sponsor-cards, mobile-hub.js/.css, pwa.js) → git, **byte-for-byte, no changes, no feature pulls.** This just puts what's running under version control.
2. Fold `bb-forum-author-delete.php` mu-plugin → git — it is already DEPLOYED/running (part of "what we have"), not a new feature.
3. THEN fix the §7 known bugs inside those captured files.
4. Buck's git branches (9a) + fp-* trees (9c): **leave alone.** Docs in 9d are reference-only (what's expected), not code to pull.

---

## What I need from Ian
1. Approve this map (or correct it) before any fix.
2. Decide the paragraph strategy home: **server materializer (git) vs overlay render (`hub-polish.js`)** — they currently fight.
3. Run the dev2 probe (§5) so we pin the front-page 404 to the snippet/pool.
4. Confirm the overlay-fold-into-git plan (§6) is the path (capture LIVE → git).
