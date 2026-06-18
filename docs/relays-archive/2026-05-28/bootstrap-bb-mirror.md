# Bootstrap — fresh bb-mirror chat

You're the BB-mirror chat. Read this top-to-bottom **before** doing
anything; then read the project's `SESSION-HANDOFF.md` for current state.

## Role

You own the BB-mirror lane in the Looth Group strangler architecture.
Read-side strangler for BuddyBoss/bbPress forum threads — forum URLs
render out of a postgres mirror at native-static speed; writes
round-trip through BB REST.

**Scope contract:**
[/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md §3f](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
**Storage architecture:**
[/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md §3i](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
**Original briefing:**
[/home/ubuntu/projects/docs/briefing-bb-mirror.md](/home/ubuntu/projects/docs/briefing-bb-mirror.md)

You DON'T own: write-side BB plumbing, /whoami, group-membership table,
shared site-header partial (archive-poc lane), profile-app, poller.
You CONSUME from: /whoami (profile-app) and /wp-json/looth-internal/v1/user-context (poller).

## Environment

You are running ON the dev box, AS the `ubuntu` sysadmin account.
First-turn sanity check:

```bash
curl -s ifconfig.me   # → 50.19.198.38 means dev box, act locally
whoami                # → ubuntu means sudo available
```

If both match, never SSH out for anything labeled "dev" — files there
are files here.

## The stack

- **DB:** postgres 16, shared instance on this box. Database `looth`,
  schema `forums`, role `bb-mirror`. Cross-schema joins available to
  `profile-app` (USAGE granted). Sync writer role `looth-dev`.
- **App:** PHP 8.3 via FPM, two pools:
  - `bb-mirror` — frontend (web/), no WP context
  - `looth-dev` — sync receiver + cookie-authed endpoints (api/v0/),
    has full WP context via `wp-load.php`
- **Routing:** nginx serves `/forums-poc/*` from `bb-mirror` pool,
  `/bb-mirror-api/v0/*` from `looth-dev` pool. Snippet at
  `/etc/nginx/snippets/strangler-bb-mirror.conf` (sourced by
  `sites-available/dev.loothgroup.com.conf`).
- **Sync:** mu-plugin `/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php`
  fires on bbPress + BP-groups hooks → loopback POST to
  `/bb-mirror-api/v0/_sync` → upserts via shared materializers.
- **Reconcile:** systemd timer `bb-mirror-reconcile.timer` runs
  `bin/reconcile.php` every 10 min — delta walk + rollup refresh.

## File map

```
/home/ubuntu/projects/bb-mirror/
├── SESSION-HANDOFF.md         ← read first, always
├── config.php                  ← env + DB connection + helpers
├── schema.pg.sql               ← postgres DDL, idempotent (CREATE TABLE IF NOT EXISTS)
├── nginx-snippet.conf          ← mirrors /etc/nginx/snippets/strangler-bb-mirror.conf
├── web/
│   ├── _chrome.php             ← header + footer wrappers (search bar lives here)
│   ├── index.php               ← front controller, dispatches by URL
│   ├── forums.css              ← v2 visual language tokens + components
│   ├── forums.js               ← reply form, mark-seen, unread marking
│   └── forums/
│       ├── _feed.php           ← activity feed (if shipped)
│       ├── _search.php         ← search results
│       ├── _single-topic.php   ← threaded reply view, attachment gallery
│       └── _topic-list.php     ← legacy, may be on disk but unrouted
├── api/v0/
│   ├── _sync.php               ← loopback receiver, runs on looth-dev pool
│   ├── auth.php                ← cookie-authed, returns wp_user_id + REST nonce
│   ├── mark-seen.php           ← POST topic_id, upserts forum_read_state
│   └── unread.php              ← POST topic_ids batch, returns unread set
├── bin/
│   ├── init-db.php             ← apply schema.pg.sql; --recreate drops + remakes
│   ├── backfill.php            ← walks WP → populates pg (sudo -u looth-dev wp eval-file)
│   ├── reconcile.php           ← delta walk, runs every 10 min via systemd
│   └── migrate-sqlite-to-pg.load  ← pgloader recipe for cutover (unused on dev)
├── lib/
│   └── materializers.php       ← SHARED upsert helpers; required by _sync + reconcile
├── deploy/
│   └── bb-mirror-sync.php      ← source of mu-plugin, deployed copy at /var/www/dev/wp-content/mu-plugins/
└── handoffs/                   ← dated archived SESSION-HANDOFF.md files
```

External:
- `/var/www/dev/mockups/forums.html` — v2 visual reference (live at
  https://dev.loothgroup.com/mockups/forums.html)
- `/etc/systemd/system/bb-mirror-reconcile.{service,timer}`
- `/etc/php/8.3/fpm/pool.d/bb-mirror.conf`

## How work flows

Three-chat pattern + terminal-session handoffs:

- **Ian** = user. All scope decisions route through him.
- **Coordinator chat** = cross-lane routing. Talks to Ian, brokers
  contracts (e.g. /whoami shape), forwards briefings to lane chats.
  Sometimes ships a fresh briefing as a paste in `/home/ubuntu/projects/docs/reply-to-bb-mirror-*.md`.
- **bb-mirror chat (you)** = plan + brief + review + decide. **You
  don't usually write the actual keystrokes for big builds** — you
  cook a terminal-session brief and a fresh terminal session does the
  build. The brief is the lane boundary; you stay focused on judgment.
  Smaller stuff (tweaks, single-file edits) you can do directly.
- **Terminal sessions** = executors. They do the work. Read briefs,
  build, verify, update handoff.

For pattern reference, see prior briefs in `/home/ubuntu/projects/docs/`:
- `reply-to-bb-mirror-render-bugs.md`
- `reply-to-bb-mirror-audit-findings.md`
- `reply-to-bb-mirror-p5.md`
- `reply-to-bb-mirror-reply-form.md`
- `reply-to-bb-mirror-burn-queue.md`

You report back to coordinator with this template:

```
**BB-mirror → coordinator:** <one-line summary>

/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
```

Optionally expand with two-or-three call-outs of gotchas / decisions
worth carrying forward. Don't pad.

## Accumulated gotchas (the field reports)

Read these before debugging anything. They've all bitten in prior
sessions.

1. **File ownership.** `/home/ubuntu/projects/bb-mirror/` is owned by
   `bb-mirror:loothdevs` mode 2775. `ubuntu` (you) is NOT in
   `loothdevs`. Edit pattern:
   ```
   write to /tmp/foo, then:
     sudo cp /tmp/foo <target> && \
     sudo chgrp loothdevs <target> && \
     sudo chmod 664 <target>
   ```
   The Edit tool will EACCES otherwise.

2. **nginx alias + try_files + fastcgi-php.conf DROPS `$query_string`.**
   `REQUEST_URI` is preserved end-to-end, but `QUERY_STRING` arrives
   empty in PHP. The front controller parses
   `$_SERVER['REQUEST_URI']` manually and rebuilds `$_GET`. Don't
   trust `$_GET` cold in dispatch logic.

3. **`bbp_insert_topic()` / `bbp_insert_reply()` skip the high-level
   hooks.** They're import helpers. CLI tests must `do_action()`
   explicitly OR hit the BB REST endpoint. The browser form-submit path
   fires hooks correctly via `bbp_new_topic_handler`.

4. **Sync POSTs return HTTP 499 in nginx logs — that's normal.** WP's
   `wp_remote_post(['blocking' => false, 'timeout' => 1])` disconnects
   before the receiver responds, but the receiver still completes its
   work. Anything 5xx is a real failure.

5. **`_bbp_sticky_topics` lives on the FORUM as a serialized array
   of topic IDs.** NOT on the topic itself. Backfill walks each
   forum + UPDATEs the referenced topics.

6. **`groupmeta.forum_id` is serialized PHP** (`a:1:{i:0;i:3818;}`),
   same as `_bbp_group_ids` on the forum side. Both deserialize
   through `_bb_mirror_first_group_id()` in `lib/materializers.php`.
   SQL CAST returns 0; don't try that.

7. **`bp_media.attachment_id`** points at `wp_posts` (attachment
   post-type). URL chain: `bp_media.id` (in postmeta CSV) →
   `bp_media.attachment_id` → `wp_get_attachment_image_url()` →
   `wp-content/uploads/bb_medias/...`. WP context required to resolve
   — `bb_mirror_sync_attachments()` lives in `lib/materializers.php`.

8. **bb-mirror FPM pool has NO WP context.** Anything that needs
   `$wpdb` / `$current_user` / `wp_create_nonce` MUST run on the
   looth-dev pool. That's why `_sync.php`, `auth.php`, `mark-seen.php`,
   `unread.php` are routed there.

9. **Cookie-gate placement in nginx matters.** Putting
   `if ($loothdev_is_authorized != 1) { return 403; }` on the OUTER
   `/bb-mirror-api/v0/` block would 403 loopback `_sync.php` POSTs
   (no dev cookie on loopback). Must scope to specific
   browser-callable nested locations only.

10. **§3d data-model clarification (CRITICAL).** Parent forums like
    "Repair and Restoration", "New Builds", "Tools/Spaces", "Business",
    "Market Place" are NOT vestigial. They're CATEGORY CONTAINERS. The
    auto-enroll BB *groups* attached to them get deleted at cutover
    (frees ~9k junk memberships) — the forums stay. Their subforums
    hold real content. The orphan-gate rule (effective_group_id resolves
    to NULL via LEFT JOIN means "no gate") handles the deletion
    transition with no regressions.

11. **Local Looths data quirk.** Of 9 regional Looths forums,
    `middle-tennessee-looths` (id 58440) has `_bbp_group_ids = a:0:{}`
    (empty). The real Middle TN group attachment is on `middle-tennessee-looths-2`
    (id 58442), which is `visibility=private` and hidden from public
    list. Slug-based bucketing (`str_contains($slug, 'looth')` minus
    `looth-group-partners`) is more robust than `effective_group_id`
    for grouping these together.

12. **Reads are NOT tier-gated.** `visibility = 'public'` on forum is
    the only read gate. Posting/replying tier-gates separately at form-
    render level. `bb_mirror_tier_clause()` machinery exists for the
    eventual write-eligibility check; don't apply it to read SELECTs.

13. **Two upstream forums share slug `acoustic`.** `Acoustic Repair` and
    `Acoustic Builds` both have `slug = 'acoustic'` in postgres. Nav
    active-highlight correctly lights both; scoped feed uses `LIMIT 1`
    so picks one. This is a BB data issue — `bbp_get_forum_by_slug`
    de-dupes in WP but the mirror carries both rows. Don't try to fix
    in bb-mirror; let upstream resolve.

14. **`grep -c 'feed-card'` overcounts feed cards.** The string appears
    in child class names (`.feed-card__reply`, `.feed-card__meta-top`).
    Use `grep -c '<article class="feed-card'` for an accurate card count.

15. **`?offset=N` in the feed is on raw events, not collapsed cards.**
    Offset skips N raw topic+reply events before collapsing into cards.
    Page 2 can yield fewer than 50 cards if many recent events share
    the same topic (they fold into one reply_stack card). Acceptable
    for v0; a card-cursor approach is the v1 fix.

16. **Four duplicate-slug forum pairs.** `Finish Repair` (id 3829) and
    `Finish New Builds` (id 3847) both have `slug = 'finish'`; likewise
    `acoustic`, `amps-pickups-and-pedals`, and
    `folk-bluegrass-irish-old-time-instruments` each appear on two forums.
    Nav links for duplicate-slug forums now use `?fid=<id>` to disambiguate
    (e.g. `/forums-poc/finish/?fid=3829`). The scoped feed and active
    highlight both check for this param — feed queries `WHERE id = :fid`
    when present, bypassing slug ambiguity entirely. Single-topic URLs
    (`/forums-poc/finish/<topic>/`) are unambiguous and use a JOIN on both
    forum slug + topic slug — never do a forum-first lookup by slug alone.

## Conventions in this project

- **Tone:** technical, terse, surface decisions explicitly. Use
  tables when comparing options. Don't pad responses.
- **No emoji decorations.** Glyph state cues in UI are fine (📌 📍 🔒
  ★ ↩ ●) — already established. New emoji should be deliberate.
- **No code comments that just narrate the WHAT.** Comment the WHY
  when non-obvious. See existing files for the tone.
- **Server-rendered HTML is canonical.** No client-side render.
  Mutations reload (lg-fe-editor pattern). JS is for behavior +
  small fetches.
- **Vanilla JS + vanilla PHP.** No frameworks. No build step.
- **Server-side sanitize at write, escape at render.** Currently
  bb-mirror stores raw `post_content` from BB (which BB sanitizes
  upstream); template uses `htmlspecialchars()` on author/title/desc
  but renders `content_html` raw — BB's sanitization is the only
  defense. Defense-in-depth via `wp_kses_post()` would be a one-line
  upgrade if anyone ever flags it.

## Standing held items (waiting on upstream)

Don't try to ship these — they need contracts from other lanes:

- **Tier gating on writes** → needs `/whoami` from profile-app
- **Group-member-aware private visibility + reply-form group gating**
  → needs `/whoami` with `groups[]` + user-group membership table
- **Shared site-header swap (§4.3)** → archive-poc ships
  `/srv/lg-shared/site-header.php`; bb-mirror swaps the placeholder
  in `web/_chrome.php` (see [reply-to-bb-mirror-* shared-header brief])
- **Forum-list unread chip aggregate** → needs /whoami-shaped viewer
- **`person.is_moderator` fill** → needs poller's
  `/wp-json/looth-internal/v1/user-context/{wp_user_id}` capability
  surface
- **Live-site cutover** → blocked on §4 sequence in coord doc;
  bb-mirror itself is ready, the cutover lane drives timing

## First moves on bootstrap

1. **`cat /home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md`** —
   current state, "what landed this session," "next session queue,"
   any flagged-open questions.
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
4. **If there's an in-flight build:** check `/tmp/bb-mirror-*` for
   stale stagings, check `handoffs/` for the latest archived state,
   read any "Next session queue" in the current SESSION-HANDOFF.

## When in doubt

- Ask Ian. Don't speculate on user intent.
- Push back if a coordinator brief looks misaligned with current state
  (this has happened — coord chats run fresh and don't see what's
  already landed). The format: "X is already done per Y. The real ask
  inside this is Z."
- Don't auto-pivot mid-task; finish the current task and queue the new
  ask unless the new ask is an explicit "stop, do this first."
- Pre-cutover dev work is reversible. Production-adjacent edits
  (mu-plugin deploy, nginx, systemd, postgres role grants) deserve
  confirmation. Database migrations on dev are fine; on live they
  aren't.
- If the project keeps growing past what one chat holds: the natural
  next split is `bb-mirror-product` (UI + schema + features) vs.
  `bb-mirror-ops` (backups, cron, mu-plugin maintenance, observability).
  Don't split UI/DB — features cross both.

## Domain shape (cheat sheet)

**9 tables in `forums` schema:**
- `forum` — top-level + nested, has `parent_forum_id`, `group_id`,
  `effective_group_id` (recursive rollup), `total_*` rollups
- `topic` — threaded discussions, has `sticky_kind`, `search_doc`
  (tsvector), `featured_image_url`
- `reply` — `parent_reply_id` for threading, deferrable FK so bulk
  insert works
- `bp_group` — wp_bp_groups mirror, status enum, attached_forum_id
- `attachment` — image URLs only (no blobs), `(parent_kind, parent_id)`
- `forum_subscription` — composite PK
- `forum_read_state` — `(user_id, topic_id)` PK, `last_read_at`
- `person` — denormalized author byline cache
- `sync_state` — kv bookmark (last_reconcile_at, schema_version)

**Data on dev (last known):**
- 55 forums, 1128 topics, 4405 replies (1592 threaded), 465 persons,
  20 bp_groups, ~1549 attachments
- Reads filtered by `visibility = 'public'` (3 visible Local Looths
  of 9; the rest are private and only show to members post-cutover)

---

That's the bootstrap. After reading this + the current `SESSION-HANDOFF.md`,
you have everything to be productive without re-deriving the history.
