# Bootstrap — fresh bb-mirror chat

You're the BB-mirror chat. Read this top-to-bottom **before** doing
anything; then read `/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md`
for current session state. The handoff is chronological newest-first
with `---` separators between dated entries; recent entries pile on
top, older ones below.

## Role

You own the BB-mirror lane in the Looth Group strangler architecture.
Read-side strangler for BuddyBoss/bbPress forum threads — forum URLs
render out of a postgres mirror at native-static speed. Writes (new
topic, reply, edit) round-trip through BB REST → mu-plugin → loopback
sync → postgres.

**Scope contract:** [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md §3f](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
**Storage architecture:** [§3i](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
**Original briefing:** [/home/ubuntu/projects/docs/briefing-bb-mirror.md](/home/ubuntu/projects/docs/briefing-bb-mirror.md)

**You DON'T own:** /whoami, group-membership table (profile-app),
shared site-header partial (archive-poc), the poller, profile-app.
**You CONSUME:** /whoami (profile-app) and `/wp-json/looth-internal/v1/user-context` (poller).

## Environment

You're on the dev box, as the `ubuntu` sysadmin account. Sanity check
on first turn:

```bash
curl -s ifconfig.me   # → 50.19.198.38 means dev box, act locally
whoami                # → ubuntu means sudo available
```

Never SSH out to anything labeled "dev" — files there are files here.

## The stack

- **DB:** postgres 16, shared instance. Database `looth`, schema `forums`,
  owner role `bb-mirror`. Cross-schema reader: `profile-app`. Sync
  writer: `looth-dev`.
- **App:** PHP 8.3 via FPM, two pools:
  - `bb-mirror` — frontend (web/). NO WP context.
  - `looth-dev` — sync receiver + cookie-authed endpoints (api/v0/).
    Has full WP context via `wp-load.php`.
- **Routing:** nginx serves `/forums-poc/*` from `bb-mirror` pool,
  `/bb-mirror-api/v0/*` from `looth-dev`. Snippet at
  `/etc/nginx/snippets/strangler-bb-mirror.conf` (sourced by
  `sites-available/dev.loothgroup.com.conf`).
- **Sync:** mu-plugin at `/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php`
  fires on bbPress + BP-groups hooks → loopback POST to
  `/bb-mirror-api/v0/_sync` → upserts via shared `lib/materializers.php`.
- **Reconcile:** `bb-mirror-reconcile.timer` every 10 min runs
  `bin/reconcile.php` — delta walk + rollup refresh + bookmark advance.
- **Test harness:** ~20-25 PASS checks gating each visible feature.
  Selectors used by the harness ARE PART OF THE CONTRACT — if you
  rename a class the harness uses (e.g. `.feed-post-btn`), update both.

## File map

```
/home/ubuntu/projects/bb-mirror/
├── SESSION-HANDOFF.md         ← read first; chronological newest-first
├── config.php                  ← env + DB connection + helpers
├── schema.pg.sql               ← postgres DDL, idempotent
├── nginx-snippet.conf          ← mirrors /etc/nginx/snippets/strangler-bb-mirror.conf
├── web/
│   ├── _chrome.php             ← header + footer wrappers (search bar lives here)
│   ├── index.php               ← front controller, dispatches by URL + query
│   ├── forums.css              ← v2 visual tokens + all component styles (~63KB)
│   ├── forums.js               ← reply form, mark-seen, lazy-load, inline edit, embeds, autolinks (~37KB)
│   └── forums/
│       ├── _feed.php           ← activity feed (default + forum-scoped)
│       ├── _search.php         ← FTS results
│       ├── _single-topic.php   ← threaded reply view, inline edit, attachments
│       ├── _topic-list.php     ← LEGACY; not routed but on disk for fallback
│       ├── _topic-replies.php  ← lazy-fetched threaded replies endpoint (?replies=<id>)
│       └── _reply-render.php   ← shared render helpers (bb_mirror_avatar, feed_rel_time, ...)
├── api/v0/
│   ├── _sync.php               ← loopback receiver, looth-dev pool
│   ├── auth.php                ← cookie-authed; returns wp_user_id + REST nonce + can_edit_others
│   ├── mark-seen.php           ← POST topic_id → upserts forum_read_state
│   ├── unread.php              ← POST topic_ids batch → returns unread set
│   └── set-forum-image.php     ← admin endpoint: assigns cover image to a forum
├── bin/
│   ├── init-db.php             ← apply schema.pg.sql; --recreate drops + remakes
│   ├── backfill.php            ← walks WP → populates pg (sudo -u looth-dev wp eval-file)
│   ├── reconcile.php           ← delta walk, runs via systemd timer
│   └── migrate-sqlite-to-pg.load  ← pgloader recipe for cutover (unused on dev)
├── lib/
│   └── materializers.php       ← SHARED upsert helpers; required by _sync + reconcile + backfill
├── deploy/
│   └── bb-mirror-sync.php      ← source of mu-plugin; deployed copy at /var/www/dev/wp-content/mu-plugins/
└── handoffs/                   ← dated archived SESSION-HANDOFF.md files (when rotated)
```

External:
- `/var/www/dev/mockups/forums.html` — v2 visual reference (live at https://dev.loothgroup.com/mockups/forums.html)
- `/etc/systemd/system/bb-mirror-reconcile.{service,timer}`
- `/etc/php/8.3/fpm/pool.d/bb-mirror.conf`

## URL surface

| URL | Handler | Notes |
|---|---|---|
| `/forums-poc/` | `_feed.php` | site-wide activity feed |
| `/forums-poc/<slug>/` | `_feed.php` | feed scoped to forum + descendants |
| `/forums-poc/<slug>/?fid=<id>` | `_feed.php` | disambiguates duplicate slugs |
| `/forums-poc/<forum>/<topic>/` | `_single-topic.php` | threaded view + inline edit |
| `/forums-poc/?q=<query>` | `_search.php` | FTS results |
| `/forums-poc/?body=<id>` | (front controller endpoint) | lazy-fetch full feed body |
| `/forums-poc/?replies=<id>` | `_topic-replies.php` | lazy-fetch threaded replies |
| `/bb-mirror-api/v0/auth.php` | GET | viewer state + REST nonce |
| `/bb-mirror-api/v0/mark-seen.php` | POST | record view |
| `/bb-mirror-api/v0/unread.php` | POST | batch unread check |
| `/bb-mirror-api/v0/_sync.php` | POST (loopback only) | mu-plugin hook receiver |
| `/bb-mirror-api/v0/set-forum-image.php` | POST | admin cover image set |

## How work flows

Three chats + terminal sessions:

- **Ian** = user. All scope decisions through him.
- **Coordinator chat** = cross-lane routing. Drops briefings at
  `/home/ubuntu/projects/docs/reply-to-bb-mirror-*.md` and forwards
  decisions. Sometimes runs fresh (no prior context) — push back if
  briefings look stale relative to current state.
- **bb-mirror chat (you)** = plan + brief + review + decide. Big builds
  get cooked into terminal-session prompts; you don't usually type the
  keystrokes. Smaller stuff (tweaks, single-file fixes) you do directly.
- **Terminal sessions** = executors. Self-contained briefs, fresh
  context, build + verify + update handoff.

**Report-back template to coordinator:**

```
**BB-mirror → coordinator:** <one-line summary>

/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
```

Reference briefings in `/home/ubuntu/projects/docs/`:
- `reply-to-bb-mirror-render-bugs.md`
- `reply-to-bb-mirror-audit-findings.md`
- `reply-to-bb-mirror-p5.md`
- `reply-to-bb-mirror-reply-form.md`
- `reply-to-bb-mirror-burn-queue.md`

## Accumulated gotchas (field reports)

Read before debugging. Every one has bitten a prior session.

1. **File ownership.** `/home/ubuntu/projects/bb-mirror/` is
   `bb-mirror:loothdevs` mode 2775. `ubuntu` (you) is NOT in
   `loothdevs`. Edit pattern: write to `/tmp/foo`, then
   `sudo cp /tmp/foo <target> && sudo chgrp loothdevs <target> &&
   sudo chmod 664 <target>`. Edit tool will EACCES otherwise.

2. **nginx alias + try_files + fastcgi-php.conf DROPS `$query_string`.**
   `REQUEST_URI` is preserved end-to-end but `QUERY_STRING` arrives
   empty in PHP. Front controller parses `$_SERVER['REQUEST_URI']`
   manually and rebuilds `$_GET`. Don't trust `$_GET` cold in dispatch.

3. **`bbp_insert_topic()` / `bbp_insert_reply()` skip the high-level
   hooks.** They're import helpers. CLI tests must `do_action()`
   explicitly OR hit BB REST. Browser form-submit fires hooks via
   `bbp_new_topic_handler` correctly.

4. **Sync POSTs return HTTP 499 in nginx logs — NORMAL.** WP's
   `wp_remote_post(['blocking' => false, 'timeout' => 1])` disconnects
   before the receiver responds, but the receiver still completes.
   Anything 5xx is a real failure.

5. **`_bbp_sticky_topics` lives on the FORUM as serialized array of
   topic IDs.** NOT on the topic. Backfill walks each forum +
   UPDATEs the referenced topics. Same for `_bbp_super_sticky_topics`
   (site-wide option).

6. **`groupmeta.forum_id` is serialized PHP** (`a:1:{i:0;i:3818;}`),
   same as `_bbp_group_ids` on the forum side. Both go through
   `_bb_mirror_first_group_id()` in `lib/materializers.php`. SQL CAST
   returns 0; don't.

7. **`bp_media.attachment_id`** → `wp_posts` (attachment post-type).
   URL chain: `bp_media.id` (in postmeta CSV) → `bp_media.attachment_id`
   → `wp_get_attachment_image_url()` → `wp-content/uploads/bb_medias/...`.
   Resolution requires WP context (looth-dev pool only).
   `bb_mirror_sync_attachments()` lives in `lib/materializers.php`.

8. **bb-mirror FPM pool has NO WP context.** Anything needing
   `$wpdb` / `$current_user` / `wp_create_nonce` MUST run on looth-dev.
   That's why `_sync.php`, `auth.php`, `mark-seen.php`, `unread.php`,
   `set-forum-image.php` are routed there.

9. **Cookie-gate placement in nginx matters.** Putting
   `if ($loothdev_is_authorized != 1) { return 403; }` on the OUTER
   `/bb-mirror-api/v0/` block would 403 loopback `_sync.php` POSTs
   (no dev cookie on loopback). Must scope inside specific
   browser-callable nested locations only.

10. **§3d data-model: parent forums are NOT vestigial.** Repair &
    Restoration, New Builds, Tools/Spaces, Business, Market Place are
    CATEGORY CONTAINERS. The auto-enroll BB *groups* attached to them
    get deleted at cutover — the *forums* stay. Their subforums hold
    real content. Orphan-gate rule (effective_group_id → NULL via
    LEFT JOIN means "no gate") handles the transition.

11. **Local Looths data quirk.** `middle-tennessee-looths` (id 58440)
    has `_bbp_group_ids = a:0:{}` (empty); the real Middle TN group
    attachment is on `middle-tennessee-looths-2` (id 58442, private).
    Slug-based bucketing (`str_contains($slug, 'looth')` minus
    `looth-group-partners`) is more robust than `effective_group_id`
    for grouping the regionals together in render.

12. **Reads are NOT tier-gated.** `visibility = 'public'` on forum is
    the only read gate. Posting/replying tier-gates at form-render.
    `bb_mirror_tier_clause()` machinery exists for write-eligibility;
    don't apply it to read SELECTs.

13. **Duplicate-slug forums exist.** `finish` (ids 3829 + 3847),
    `acoustic` (3823 + 3845), `amps-pickups-and-pedals` (3826 + 3849),
    `folk-bluegrass-irish-old-time-instruments` (3835 + 3852). A
    forum-only lookup by slug is non-deterministic. Fixes:
    - **Single-topic** lookups: combined forum+topic JOIN in one shot
      (anchors topic to its actual forum). See `_single-topic.php`.
    - **Feed-scoped URLs**: `feed_forum_url($f, $slug_freq)` appends
      `?fid=<id>` ONLY for duplicate slugs.
    - **Pill nav + parent breadcrumbs** in `_feed.php` use fid-aware
      URLs so subforums under different categories resolve correctly.

14. **`bbp_media` field on edit PUT — OMIT, don't pass empty.**
    Sending `bbp_media: []` WIPES existing attachments. Sending no
    `bbp_media` field at all PRESERVES them. The inline-edit Quill
    flow in `forums.js §3c` omits intentionally — image add/remove
    during edit is not supported yet (would need full bbp_media set).

15. **Leaf-only posting.** Categories / parents-with-subforums are
    placeholders. Posting is restricted to LEAF forums (no kids).
    Enforced in two places: forum-list filter + reply-form gate.
    Don't undo without thinking — category posting breaks BB's
    activity-feed assumptions.

16. **Test harness selector contract.** The 20+ PASS checks rely on
    specific classes (`.feed-post-btn`, `.feed-card`, `.reply-stub`,
    etc.). Renames cascade — update harness when you rename. If the
    harness fails after a UI change, that's a bug or a contract update,
    not a flaky test.

17. **JS render-time enhancements** run across multiple surfaces:
    `bbProcessEmbeds()` → `.post__body` (single-topic), lazy feed
    full-bodies (`?body=` fetches), AND post-edit re-render.
    `bbAutoLink()` runs at the end of `bbProcessEmbeds()` —
    TreeWalker over text nodes, wraps bare http(s) URLs, skips text
    inside `A/SCRIPT/STYLE/CODE/PRE` and `.bb-embed`. Don't break
    these by re-using class names they target.

18. **Inline editing requires `can_edit_others`** on auth.php response
    (true for `edit_others_topics` / `moderate` / `administrator`).
    Edit-button visibility client-side; BB REST re-checks server-side.
    Topic PUT needs `id`+`parent`+`title`(+content); reply PUT needs
    `id`+`topic_id`+`content`.

## Conventions

- **Tone:** technical, terse, surface decisions explicitly. Tables
  when comparing. No padding.
- **No emoji decorations.** UI state glyphs (📌 📍 🔒 ★ ↩ ●) are
  established; new emoji should be deliberate.
- **No comments narrating WHAT.** Comment the WHY when non-obvious.
- **Server-rendered HTML is canonical.** No client-side render.
  Mutations reload OR optimistic-update + JS re-runs `bbProcessEmbeds`.
- **Vanilla JS + vanilla PHP.** No frameworks. No build step.
- **JS uses Quill via CDN** for the inline editor — that's the only
  vendored client dep.
- **Server-side: BB sanitizes upstream; we store raw.** Template
  uses `htmlspecialchars()` for author/title/description but renders
  `content_html` raw. Defense-in-depth via `wp_kses_post()` would be
  a one-line upgrade if anyone flags it.

## Standing held items (waiting on upstream)

Don't try to ship these — they need contracts from other lanes:

- **Group-member-aware private visibility + reply-form group gating**
  → `/whoami` with `groups[]` + user-group membership table
- **Shared site-header swap (§4.3)** → archive-poc ships
  `/srv/lg-shared/site-header.php`; bb-mirror swaps placeholder in
  `web/_chrome.php`
- **Forum-list unread chip aggregate** (count of unread per forum)
  → /whoami-shaped viewer state
- **`person.is_moderator` fill** → poller's
  `/wp-json/looth-internal/v1/user-context/{wp_user_id}` capability surface
- **Live-site cutover** → blocked on §4 sequence; bb-mirror itself is
  ready, cutover-lane drives timing

## Queued (in-lane, no upstream block)

- **Edit from the FEED** (currently single-topic page only)
- **Add/remove images during edit** (would need full bbp_media set)
- **Delete/trash a post from UI** (REST DELETE exists)
- **"Edited" indicator** / reason_editing log surfaced in mirror

## First moves on bootstrap

1. **`cat /home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md`** —
   newest-first chronological. Top entries are most recent decisions.
2. **Verify health:**
   ```bash
   TOK=$(sudo grep -E 'set \$loothdev_token' \
     /etc/nginx/sites-available/dev.loothgroup.com.conf | \
     head -1 | grep -oE '"[^"]+"' | tr -d '"')
   curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null
   for u in '' 'acoustic/' 'general/stripped-out-trussrod/' '?q=guitar' \
            'forums.css' 'forums.js'; do
     curl -s -b /tmp/bbjar -o /dev/null \
       -w "%{http_code}  /forums-poc/$u\n" \
       "https://dev.loothgroup.com/forums-poc/$u"
   done
   sudo systemctl is-active bb-mirror-reconcile.timer
   ```
3. **`ls /home/ubuntu/projects/docs/reply-to-bb-mirror-*.md`** — any
   open briefings sitting there for you to address.
4. **If a build is in flight:** check `handoffs/` for prior states,
   check `/tmp/bb-mirror-*` for stale stagings.

## When in doubt

- Ask Ian. Don't speculate on user intent.
- Push back if coordinator briefings look misaligned with current
  state. Format: "X is already done per Y. The real ask inside this
  is Z."
- Don't auto-pivot mid-task; finish + queue unless the new ask is an
  explicit "stop, do this first."
- Pre-cutover dev work is reversible. Production-adjacent edits
  (mu-plugin deploy, nginx, systemd, postgres role grants) deserve
  confirmation. DB migrations on dev fine; on live not.
- If the project keeps growing: natural next split is
  `bb-mirror-product` (UI + schema + features) vs.
  `bb-mirror-ops` (backups, cron, mu-plugin maintenance, observability).
  Don't split UI/DB — features cross both.

## Domain cheat sheet

**9 tables in `forums` schema:**

| Table | Purpose |
|---|---|
| `forum` | nested via `parent_forum_id`; `group_id` + `effective_group_id` (recursive rollup); `total_*` rollups |
| `topic` | threaded; `sticky_kind`, `search_doc` (tsvector), `featured_image_url` |
| `reply` | `parent_reply_id` for threading; deferrable FK so bulk insert works |
| `bp_group` | wp_bp_groups mirror; status enum; `attached_forum_id` |
| `attachment` | image URLs only (no blobs); `(parent_kind, parent_id)` |
| `forum_subscription` | composite PK |
| `forum_read_state` | `(user_id, topic_id)` PK; `last_read_at` |
| `person` | denormalized author byline cache |
| `sync_state` | kv bookmark (`last_reconcile_at`, `schema_version`) |

**Data on dev (steady state ~):**
- 55 forums (5 categories + 9 regionals + 4 sponsor + 27 subforums + 10 site-wide)
- 1130-ish topics, 4400-ish replies (1600-ish threaded)
- 1500+ attachments
- 20 bp_groups
- forum_read_state grows as real viewers visit

**Public visible:** 3 of 9 regional Looths show (Ohio, Middle TN,
Basque). The 6 private ones (SoCal, NYC, DMV, PNW, SW Ontario, Ireland)
are filtered by `visibility='public'` — they'll surface to group members
post-cutover when `/whoami` lights up.

---

That's the bootstrap. With this + the current SESSION-HANDOFF.md
you have everything to be productive without re-deriving history.
Update this doc on your way out if a gotcha you hit isn't listed.
