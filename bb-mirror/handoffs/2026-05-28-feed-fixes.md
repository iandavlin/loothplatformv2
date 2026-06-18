# BB-mirror — Session Handoff (2026-05-28, feed-fixes)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).

## What landed this session (feed-fixes)

Three targeted fixes across four files + one new file.

| File | Change |
|---|---|
| **updated** `web/forums/_feed.php` | Fix 1: removed 67251 from feed exclusion (kept 3876); Fix 2C: replaced server-rendered full-body div with empty div + button carries data-topic-id + data-state |
| **updated** `web/index.php` | Fix 2A: added ?body=<id> route before the switch (seg_count=0 + _GET['body'] present) to _topic-body.php |
| **new** `web/forums/_topic-body.php` | Fix 2B: bare HTML fragment endpoint; prepared statement with bindValue; 400/404 on bad/missing topic |
| **updated** `web/forums.js` | Fix 2D: replaced sync read-more handler with async fetch-on-click; caches loaded state in body.dataset.loaded |
| **updated** `web/forums.css` | Fix 3: .reply-stub gets min-width:0; .reply-stub__excerpt changed from nowrap/ellipsis to white-space:normal + overflow-wrap + word-break + display:block |

### Fix 1: Anonymous Questions back in feed

Forum 67251 (anonymous-questions) was excluded from both topic queries in
_feed.php. Removed from the NOT IN (...) clause — now reads NOT IN (3876).

Note: forum 67251 currently has 0 published topics in the DB (the forum exists
but is empty in the mirror), so removing the exclusion has no visible effect
right now. The fix is correct for when topics appear.

Nav exclusion (in _chrome.php) is untouched — 67251 stays hidden from nav.
3876 (quick-questions) remains excluded from both feed and nav.

### Fix 2: Lazy-fetch full post body

Route (index.php): Added before the switch block. $seg_count introduced to
avoid repeating count($segments). New branch: seg_count === 0 and
isset($_GET['body']) → require _topic-body.php + exit.

$_GET['body'] is already populated by the existing parse_str($qs, ...) block
at the top of index.php — no additional parsing needed.

Fragment file (_topic-body.php): Prepared statement with :id bindValue.
Returns bare content_html. 400 on $tid===0, 404 if not found/unpublished.

Card markup (_feed.php): Button now carries data-topic-id and
data-state="collapsed". Empty <div class="feed-card__full-body"></div>
follows (no hidden attr — empty so invisible already).

JS (forums.js section 2b): Async handler. On first expand: fetches
/forums-poc/?body=<id>, injects innerHTML, sets body.dataset.loaded='1'.
Subsequent expand/collapse toggles body.hidden only (no re-fetch).
Error path restores button text + re-enables without broken state.

### Fix 3: Reply stub text wrapping

.reply-stub was missing min-width:0 — flex container couldn't shrink,
so excerpt overflowed. Added min-width:0 to .reply-stub.

.reply-stub__excerpt changed from nowrap/ellipsis to white-space:normal;
overflow-wrap:break-word; word-break:break-word; display:block.

## Verified checks (2026-05-28)

```
0     quick-questions in nav (grep -c = 0)                      PASS
759   grep -c 'feed-card' on /forums-poc/ (class refs incl)     PASS
0     grep -i 'quick-questions' in nav                          PASS
247+  /forums-poc/?body=12369 returns HTML bytes (>0)           PASS
<p>Test</p>  /forums-poc/?body=23821 short content returned     PASS
empty <div class="feed-card__full-body"></div> in page source   PASS
200   /forums-poc/general/stripped-out-trussrod/                PASS
```

## Gotchas discovered this session

1. **Forum 67251 is empty** — anonymous-questions exists in the forum table
   but has 0 published topics in the mirror DB. Removing from feed exclusion
   is correct but has no visible effect until reconciler syncs topics in.

2. **?body= param routing works via existing parse_str** — index.php already
   parses the full query string into $_GET before routing. The body branch
   just checks isset($_GET['body']) after that block. No extra URI parsing.

3. **$seg_count variable** — Introduced to avoid repeating count($segments).
   The switch was updated to switch ($seg_count) consistently.

## Prior gotchas still apply

- nginx alias drops QUERY_STRING; parse_url on REQUEST_URI used instead
- Dual "acoustic" slug under repair AND builds
- File ownership pattern: bb-mirror:loothdevs mode 664 for all web files
- forums.person has no wp_user_id
- featured_image_url column not populated by upstream sync yet

## Postgres infrastructure (unchanged)

- DB looth, schema forums, role bb-mirror
- 9 tables; 55 forums, 1128 topics, 4405 replies, 465 persons, 20 bp_groups,
  1549 attachments, 1 forum_read_state (test row)
- Reconcile cron every 10 min via bb-mirror-reconcile.timer

## Still queued

1. Group-member-aware private visibility — show private group forums to
   members. Needs /whoami + group-membership table (profile-app post-cutover).
2. Reply-form group gating — "Join SoCal to post here" CTA. Same dep.
3. §4.3 shared header swap — placeholder still in _chrome.php. Waits on
   archive-poc shipping /srv/lg-shared/site-header.php.
4. Forum-list unread chip aggregate — count of unread topics per forum in nav.
   Needs viewer state from /whoami.
5. Retire unreferenced dashboard/topic-list — web/forums/index.php and
   web/forums/_topic-list.php on disk but never routed. Safe to delete.
6. featured_image_url sync — upstream sync doesn't populate this column yet.
   COALESCE(t.featured_image_url, first_img.url) will auto-prefer it when it does.
7. Cat-map at nav query level — _feed.php runs a separate small forum query
   to build the cat-map. Minor RTT; could expose from bb_mirror_left_nav() later.

## Pointers

- Coordination doc: /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md
- Prior handoff: handoffs/2026-05-28-feed-polish.md
