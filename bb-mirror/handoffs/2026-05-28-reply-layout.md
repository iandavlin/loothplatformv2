# BB-mirror — Session Handoff (2026-05-28, reply-layout)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP->pg
in real time; systemd timer reconciles every 10 min.

Scope contract: STRANGLER-COORDINATION.md section 3f.
Storage: section 3i.

## What landed this session (reply-layout)

Four UI changes to the activity feed.

| File | Change |
|---|---|
| **updated** `web/forums/_feed.php` | Change 1: read-more button moved after full-body div |
| **updated** `web/forums/_feed.php` | Change 2: reply stubs restructured to two-row layout |
| **updated** `web/forums/_feed.php` | Change 3: LATERAL join for reply images; renders reply-stub__img when present |
| **updated** `web/forums.css` | Change 2 CSS: reply-stub flex-direction:column; head/body/time/img rules |
| **updated** `web/forums.css` | Change 4: feed-card__full-body scoping rules for img/a/ul/ol |

### Change 1: Read-more button moves to bottom when expanded

DOM order changed from: excerpt -> [button] -> [full-body div]
to: excerpt -> [full-body div] -> [button]

No JS changes needed. Button appears below excerpt when collapsed (full-body is
empty); appears below loaded content when expanded.

### Change 2: Reply stub two-row layout

Previous: single-row flex with avatar + author + excerpt + time all inline.
New layout:
- Row 1 (.reply-stub__head): avatar | author | time (margin-left:auto)
- Row 2 (.reply-stub__body): padded left calc(28px + 8px) to indent under avatar

Applied to BOTH visible stubs and .reply-stub--overflow stubs.
The .feed-card.replies-expanded .reply-stub--overflow { display: flex } rule
still works correctly with the column-direction flex container.

### Change 3: First attachment image on reply stubs

Reply SQL now includes LATERAL join on forums.attachment (parent_kind='reply').
reply_image_url rendered as .reply-stub__img inside .reply-stub__body when non-null.
742 reply attachments in DB; 51 images rendered in the first page of the feed.

### Change 4: Images in expanded post body

.feed-card__full-body now scopes img, a, ul, ol, p to prevent overflow.
_topic-body.php already outputs content_html raw (no strip_tags) -- confirmed.
Note: BB stores images as attachments, not inline in content_html, so <img>
tags in expanded bodies are rare currently. CSS rules are ready.

## Verified checks (2026-05-28)

- 50 feed-card articles: PASS
- 254 reply-stub__head elements: PASS
- 51 reply-stub__img elements (from 742 DB reply attachments): PASS
- full-body div appears BEFORE read-more button in source: PASS
- 200 /forums-poc/finish/airbrush-recommendations/ single topic: PASS
- topic body endpoint returns raw HTML (no strip_tags): PASS

## Gotchas

1. Four duplicate forum slugs in postgres: finish, acoustic,
   amps-pickups-and-pedals, folk-bluegrass-irish-old-time-instruments each
   appear on two different forums. Single JOIN in _single-topic.php handles all.

2. nginx alias drops QUERY_STRING -- parse_url on REQUEST_URI used instead.

3. File ownership pattern: bb-mirror:loothdevs mode 664 for all web files
   (edit in /tmp, sudo cp back, sudo chgrp + chmod).

4. forums.person has no wp_user_id -- author linking is by slug only.

5. featured_image_url column not populated -- COALESCE falls to LATERAL join.

6. content_html has no inline <img> tags -- BB stores images as attachments.
   Reply attachments (742 rows) render via LATERAL join (Change 3).
   .feed-card__full-body img CSS is ready for future sync improvements.

## Postgres infrastructure (unchanged)

- DB looth, schema forums, role bb-mirror
- 55 forums, 1128 topics, 4405 replies, 465 persons, 1549 attachments
  (742 with parent_kind='reply')
- Reconcile cron every 10 min via bb-mirror-reconcile.timer

## Still queued

1. Group-member-aware private visibility -- needs /whoami + group-membership table
2. Reply-form group gating -- "Join SoCal to post here" CTA. Same dep.
3. Shared header swap -- placeholder in _chrome.php, waits on archive-poc
4. Forum-list unread chip aggregate -- needs viewer state from /whoami
5. Retire unreferenced dashboard/topic-list files (never routed, safe to delete)
6. featured_image_url sync -- upstream sync doesn't populate this yet
7. Cat-map at nav query level -- minor RTT optimization

## Pointers

- Coordination doc: /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md
- Prior handoff: handoffs/2026-05-28-reply-layout.md (was SESSION-HANDOFF.md)

JS: post-body and reply-expand are now accordions (one open at a time, both collapsible).
