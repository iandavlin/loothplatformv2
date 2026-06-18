# BB-mirror — Session Handoff (2026-05-28, feed-ui-complete)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP->pg
in real time; systemd timer reconciles every 10 min.

Scope contract: STRANGLER-COORDINATION.md section 3f.
Storage: section 3i.

## What landed this session (slug-disambig)

Three fixes to the activity feed.

| File | Change |
|---|---|
| **updated** `web/_chrome.php` | Fix 1A: slug-frequency map; append ?fid=<id> to nav links for duplicate-slug forums |
| **updated** `web/_chrome.php` | Fix 1B: active-highlight respects ?fid= param on 1-segment scoped-feed URLs |
| **updated** `web/forums/_feed.php` | Fix 1C: extract $fid; look up scoped_forum by id when fid>0, bypassing slug ambiguity |
| **updated** `web/forums.js` | Fix 2: hide .feed-card__op-excerpt when full body is shown; restore on collapse |
| **updated** `web/forums.css` | Fix 3: .feed-card.replies-expanded .feed-card__expand gets filled sage style instead of display:none |

### Fix 1: Duplicate-slug disambiguation

Four slug collisions exist in the DB:
- `finish` — id 3829 (Finish Repair) and id 3847 (Finish New Builds)
- `acoustic` — id 3823 and id 3845
- `amps-pickups-and-pedals` — id 3826 and id 3849
- `folk-bluegrass-irish-old-time-instruments` — id 3835 and id 3852

Nav links now render as e.g. `/forums-poc/finish/?fid=3829` for any forum
whose slug appears more than once. Feed picks up ?fid from REQUEST_URI and
queries `WHERE id = :fid` instead of `WHERE slug = :slug`, giving exact
scoping.

### Fix 2: Excerpt hidden while full body is shown

`.feed-card__op-excerpt` is hidden (`hidden = true`) when a "Read more" expand
fires, and restored on collapse. Also fires in the "close other open bodies"
loop so switching between cards does not leave orphaned excerpts.

### Fix 3: Reply collapse affordance

`.feed-card.replies-expanded .feed-card__expand` previously had `display:none`.
Now stays visible with a sage-tinted filled state (background: var(--lg-sage-tint),
border-color: var(--lg-sage), color: var(--lg-sage-d)) so it is clear the
button collapses the replies.

## Verified checks (2026-05-28)

- finish/?fid=3829 → 50 cards all "Repair and Restoration > Finish Repair": PASS
- finish/?fid=3847 → 30 cards all "New Builds > Finish New Builds": PASS
- No cross-contamination between the two finish feeds: PASS
- Nav links for all 4 duplicate-slug pairs include ?fid=<id>: PASS
- 50 feed-card articles on site-wide feed: PASS
- op-excerpt referenced 3x in forums.js (hide/restore/close-others): PASS
- /forums-poc/finish/airbrush-recommendations/ returns 200: PASS

## Gotchas

1. Four duplicate forum slugs in postgres: finish, acoustic,
   amps-pickups-and-pedals, folk-bluegrass-irish-old-time-instruments each
   appear on two different forums. Nav links now use ?fid=<id> to disambiguate.
   Feed and active highlight both check for this param.

2. nginx alias drops QUERY_STRING -- parse_url on REQUEST_URI used for slug;
   preg_match on REQUEST_URI used for ?fid param.

3. File ownership pattern: bb-mirror:loothdevs mode 664 for all web files
   (edit in /tmp, sudo cp back, sudo chgrp + chmod).

4. forums.person has no wp_user_id -- author linking is by slug only.

5. featured_image_url column not populated -- COALESCE falls to LATERAL join.

6. content_html has no inline <img> tags -- BB stores images as attachments.
   Reply attachments (742 rows) render via LATERAL join.
   .feed-card__full-body img CSS is ready for future sync improvements.

## Postgres infrastructure (unchanged)

- DB looth, schema forums, role bb-mirror
- 55 forums, 1128 topics, 4405 replies, 465 persons, 1549 attachments
  (742 with parent_kind='reply')
- Reconcile cron every 10 min via bb-mirror-reconcile.timer

## What landed across the full 2026-05-28 session

This session rebuilt the entire feed UI from scratch. Full timeline:

- **Activity feed** (`_feed.php`): replaced forum dashboard. Site-wide + scoped (recursive CTE). Collapse rule: 1 visible reply stub + accordion. Sort bar (new/old/hot). 50-card cap + Load older.
- **Left nav** (`_chrome.php`): full forum tree, category buckets, sticky desktop, off-canvas mobile drawer. Corner hamburger (clip-path triangle).
- **Two-pane layout** (`forums.css`): 240px nav + fluid content. Archive-poc token palette (`--lg-*`) adopted.
- **Featured image / first attachment**: LATERAL join → card thumbnail.
- **Forum header**: title + background image from first recent attachment.
- **Sort bar**: new/old/hot with active pill.
- **Card redesign**: OP excerpt + reply stubs + inline expand.
- **Lazy-fetch full body**: `?body=<id>` route + JS fetch-on-click. Excerpt hides on expand (style.display, not hidden attr — -webkit-box conflict).
- **Reply stub layout**: author row above text, time right-aligned, image thumbnail if present.
- **Accordion**: one post body open at a time; one reply section open at a time. Both collapsible.
- **Reply collapse affordance**: sage-filled button when expanded.
- **Duplicate slug fix** (`_single-topic.php`): JOIN query resolves forum+topic in one shot; no more false 404s.
- **Nav active highlight** (`_chrome.php`): 2-segment URLs resolve forum_id via JOIN; 1-segment uses ?fid param.
- **?fid disambiguation**: four slug collisions (finish, acoustic, amps-pickups-and-pedals, folk-bluegrass-irish-old-time-instruments) get ?fid=<id> on nav links.
- **Anon questions restored** in feed (forum 67251); quick-questions (3876) stays hidden.
- **Nav reorder**: category containers → General → Sponsors → Local Looths.
- **Color coding**: --cat-* vars; data-cat on cards; nav-section--* on headers.
- **Read more padding**: margin: 8px 0 4px, display: block.

## Add post modal — investigated, not built (next session)

`POST /wp-json/buddyboss/v1/bb-topics` exists with args: `title` (required), `item_type` (required), `permission_type` (required). No `content` field exposed in OPTIONS — needs deeper investigation. Before building:

1. Test a POST with `content` as an extra field to see if it silently accepts it.
2. Check `api/v0/auth.php` nonce format — the BB REST endpoint needs `X-WP-Nonce`.
3. Confirm `item_type` is the forum_id or a forum slug.
4. Quill.js from CDN (jsDelivr) is the plan for rich text — no build step.
5. Modal lives in `_chrome.php`; form POSTs via JS to `api/v0/new-topic.php` (looth-dev pool, WP context) which internally calls the BB REST endpoint.

## Still queued

1. **Add post modal** — see investigation notes above. Next session.
2. Group-member-aware private visibility -- needs /whoami + group-membership table
3. Reply-form group gating -- "Join SoCal to post here" CTA. Same dep.
4. Shared header swap -- placeholder in _chrome.php, waits on archive-poc
5. Forum-list unread chip aggregate -- needs viewer state from /whoami
6. Retire unreferenced dashboard/topic-list files (never routed, safe to delete)
7. featured_image_url sync -- upstream sync does not populate this yet
8. Avatar sync -- all 465 persons have generic gravatar URL; real hash needs email from WP sync

## Pointers

- Coordination doc: /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md
- Prior handoff: handoffs/2026-05-28-reply-layout.md

JS: post-body and reply-expand are now accordions (one open at a time, both collapsible).
Excerpt hides when full body is open, restores on close.
