# BB-mirror — Session Handoff (2026-05-28, nav-threading-pills)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP->pg
in real time; systemd timer reconciles every 10 min.

Scope contract: STRANGLER-COORDINATION.md section 3f.
Storage: section 3i.

## What landed this session (nav-threading-pills)

| File | Change |
|---|---|
| `web/_chrome.php` | Nav: categories are now accordion sections (collapsed by default, open if active). Category label is a link to category feed. Duplicate parent-forum link removed. `data-slug` on forum `<option>` elements. `data-public-path` on ntm-form. |
| `web/forums/_feed.php` | Forum header: "+ Post here" button (data-ntm-open, data-forum-id). Subforum pills nav below header when forum has children. Threaded reply stubs: top-level replies + indented direct children. Reply query now includes `parent_reply_id`, sorted ASC for tree building. Tree built in PHP via two-pass index+attach. Shows 2 visible top-level threads. |
| `web/forums.css` | Accordion styles (section-head, toggle, section-body, --open). Forum header title-row + post-btn. Subforum pills. reply-stub--child indented styles. |
| `web/forums.js` | Section 1b: accordion toggle. Section 4: ntmShowOverlay(overrideForumId), ntmLoadAuth(overrideForumId), data-ntm-open delegation. Post redirect: builds bb-mirror URL from forum option data-slug + topic slug extracted from BB link. |

### Nav accordions

- Categories collapsed by default; open if current URL matches the category or any child
- Category label (`<a>`) links to the category's activity feed (`/forums-poc/<slug>/`)
- Duplicate "parent forum" link inside accordion removed — category label IS the link
- Toggle button (▶) rotates 90° when open

### Forum header "Post here" button

- Only shown when on a scoped forum feed (`$scoped_forum` set)
- Opens new-topic modal via `data-ntm-open`; `data-forum-id` overrides pre-selection
- JS: `ntmShowOverlay(overrideForumId)` accepts optional forum ID to pre-select

### Subforum pills

- Rendered when `$scoped_forum` has child forums in postgres
- Shown as scrollable pill row below forum header
- "Repair and Restoration" → shows 8 child forums as pills

### Threaded reply stubs

- Reply query now includes `parent_reply_id`, sorted ASC (chronological for tree building)
- PHP tree build: index all replies by id, attach children to parents, sort top-level DESC
- Feed cards: 2 visible top-level threads; each thread shows all direct children indented
- `.reply-stub--child`: 28px left margin + left border, smaller avatar (22px), smaller text
- Overflow accordion shows remaining top-level threads + their children together
- 1593 threaded replies in DB across topics

### Post redirect fix

- Previously redirected to legacy BB URL (`/all-forums-all-topics/topic/<slug>/`)
- Now extracts topic slug from BB response `link` field, combines with forum slug from
  `<option data-slug>`, builds `/forums-poc/<forum-slug>/<topic-slug>/`

## Gotchas

1. Four duplicate forum slugs in postgres: finish, acoustic,
   amps-pickups-and-pedals, folk-bluegrass-irish-old-time-instruments each
   appear on two different forums. Nav links now use ?fid=<id> to disambiguate.

2. nginx alias drops QUERY_STRING -- parse_url on REQUEST_URI used for slug;
   preg_match on REQUEST_URI used for ?fid param.

3. File ownership: ubuntu:ubuntu mode 664 (world-readable, FPM can read).
   Previously bb-mirror:loothdevs — either works since files are world-readable.

4. forums.person has no wp_user_id -- author linking is by slug only.

5. featured_image_url column not populated -- COALESCE falls to LATERAL join.

6. content_html has no inline <img> tags -- BB stores images as attachments.

7. bb-topics vs topics: /buddyboss/v1/bb-topics = BuddyBoss activity topics.
   /buddyboss/v1/topics = bbPress forum threads. Don't confuse.

8. Nav accordion: `$active_forum_id` is only set on 2-segment URLs (single-topic pages).
   On 1-segment feeds, `$active` (slug) is used for accordion open detection.

## Postgres infrastructure (unchanged)

- DB looth, schema forums, role bb-mirror
- 55 forums, 1128 topics, 4405 replies, 465 persons, 1549 attachments
- Reconcile cron every 10 min via bb-mirror-reconcile.timer

## Still queued

1. **Accordion state persistence** (localStorage) — low priority, current behavior is fine
2. **Subforum pills: highlight active subforum** when navigating into a subforum from its parent
3. **Optgroup sort fix** in forum select — groups may repeat (cosmetic only)
4. Group-member-aware private visibility — needs /whoami + group-membership table
5. Reply-form group gating — "Join SoCal to post here" CTA. Same dep.
6. Shared header swap — placeholder in _chrome.php, waits on archive-poc
7. Forum-list unread chip aggregate — needs viewer state from /whoami
8. Retire unreferenced dashboard/topic-list files (never routed, safe to delete)
9. featured_image_url sync — upstream sync does not populate this yet
10. Avatar sync — all 465 persons have generic gravatar URL

## Pointers

- Coordination doc: /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md
- Prior handoff: handoffs/2026-05-28-add-post-modal.md
