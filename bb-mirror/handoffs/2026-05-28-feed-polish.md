# BB-mirror — Session Handoff (2026-05-28, feed-polish)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).

## What landed this session (feed-polish)

Five feed-polish changes across four files.

| File | Change |
|---|---|
| **updated** `web/_chrome.php` | Added `bb_mirror_cat_key()` + `bb_mirror_build_cat_map()`; excluded forum IDs 67251/3876 from nav query; reordered nav sections (containers · general · sponsors · looths); added `nav-section--{cat}` classes |
| **updated** `web/forums/_feed.php` | Excluded 67251/3876 from both topic queries; added cat-map build; added `data-cat` on article elements; changed reply slice 3→1; updated expand button text; added Read-more full-body inline expand |
| **updated** `web/forums.css` | Added `--cat-*` CSS vars; nav-section color rules; `feed-card[data-cat]` border rules; `.feed-card__full-body` + `.feed-card__read-more` styles |
| **updated** `web/forums.js` | Added `feed-card__read-more` click handler (section 2b) |

### Change 1: Hide anonymous-questions (67251) + quick-questions (3876)

Both forum IDs excluded from:
- Nav query: `AND id NOT IN (67251, 3876)`
- Scoped feed query: `AND t.forum_id NOT IN (67251, 3876)`
- Site-wide feed query: `AND t.forum_id NOT IN (67251, 3876)`

### Change 2: Nav section reorder

New order: category containers · General · Sponsors · Local Looths

Sponsors bucket (`$sponsors[]`) catches top-level forums with the sponsor
forum id (34044) or slug containing "sponsor" AND no children. In practice
"Sponsor Forums" (id 34044) has children (Go Acoustic, Strings Micro Factory,
Total Vise, StewMac) so it falls into `$containers` and renders first among
containers — which is fine.

### Change 3: Category color coding

`bb_mirror_cat_key(parent_slug, own_slug)` maps slugs to color keys via
keyword matching. "new-construction" → builds (added "construction" keyword).

`bb_mirror_build_cat_map($rows)` builds a flat `forum_id → key` map from
the full forum tree fetched once at feed startup (cheap — 55 forums).

Nav: `nav-section--{key}` class on section label + each nav item.
Feed cards: `data-cat="{key}"` on each `<article>`.

### Change 4: Replies — 1 visible, expand shows full count

Changed `array_slice($replies, 0, 3)` → `[:1]` and `[3:]` → `[1:]`.
Expand button changed to `View {reply_count} replies ▾` using the DB
reply_count (not just the hidden stub count) — more informative.

If reply_count == 0: no replies section rendered.
If reply_count == 1: 1 stub shown, no expand button (correct — hidden array empty).
If reply_count > 1: 1 stub shown, expand button shows full count.

### Change 5: Read more inline expand

Server renders `<div class="feed-card__full-body" hidden>` with raw
`content_html` only when `strlen(strip_tags($content_html)) > 250`. Topics
shorter than that threshold (already fully shown in excerpt) get no button.

JS section 2b toggles `body.hidden` and flips button text between
"Read more ▾" / "Read less ▴".

## Verified checks (2026-05-28)

```
0    anon/quick in feed                                        ✓
0    anon/quick in nav                                         ✓
50   feed cards on /forums-poc/                                ✓
50   data-cat attributes on /forums-poc/                       ✓
38   feed-card__read-more buttons on /forums-poc/              ✓
200  /forums-poc/general/stripped-out-trussrod/                ✓
50   /forums-poc/?q=guitar search results                      ✓
ok   /bb-mirror-api/v0/_sync                                   ✓
```

Nav section color distribution confirmed:
  sponsors · repair · builds · tools · business · market · General · Local Looths

Feed card data-cat distribution: repair×29, builds×11, tools×7, business×2, market×1

## Gotchas discovered this session

1. **Slug-based cat matching** — The `bb_mirror_cat_key()` function works purely
   on slug keyword matching, not a DB-backed taxonomy. Two edge cases found and
   fixed: "new-construction" needed "construction" keyword; "sponsor-fourm" (note
   typo in slug) is caught by "sponsor" keyword. If new forum categories are added
   with unusual slugs, this function may need updating.

2. **Sponsor Forums is a container** — ID 34044 has children (Go Acoustic Audio,
   Strings Micro Factory, Total Vise, StewMac), so it goes into `$containers`,
   not `$sponsors`. The standalone `$sponsors` bucket would catch a sponsor-slug
   forum with no subforums. No impact on functionality — it renders first in containers
   with `nav-section--sponsors` color.

3. **Reply query sorts DESC** — The feed reply query uses `ORDER BY r.created_at DESC`,
   so `array_slice($replies, 0, 1)` gives the MOST RECENT reply (not oldest). That
   is the intended behavior (show the latest reply as the stub).

4. **full-body div contains raw BB HTML** — `content_html` from the DB is the same
   sanitized HTML already rendered in single-topic view. Trust model is unchanged.
   No additional escaping applied (consistent with single-topic behavior).

5. **Prior gotchas still apply** — nginx alias drops query string; dual "acoustic"
   slug (exists under repair AND builds); file ownership pattern; `forums.person`
   has no `wp_user_id`.

## Postgres infrastructure (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies, 465 persons, 20 bp_groups,
  1549 attachments, 1 forum_read_state (test row)
- Reconcile cron every 10 min via `bb-mirror-reconcile.timer`

## Still queued

1. **Group-member-aware private visibility** — show private group forums to
   members. Needs `/whoami` + group-membership table (profile-app post-cutover).
2. **Reply-form group gating** — "Join SoCal to post here" CTA. Same dep.
3. **§4.3 shared header swap** — placeholder still in `_chrome.php`. Waits
   on archive-poc shipping `/srv/lg-shared/site-header.php`.
4. **Forum-list unread chip aggregate** — count of unread topics per forum in
   nav. Needs viewer state from `/whoami`.
5. **Retire unreferenced dashboard/topic-list** — `web/forums/index.php` and
   `web/forums/_topic-list.php` on disk but never routed. Safe to delete once
   feed UI confirmed stable on live.
6. **`featured_image_url` sync** — upstream sync doesn't populate this column yet.
   `COALESCE(t.featured_image_url, first_img.url)` will auto-prefer it when it does.
7. **Cat-map at nav query level** — `_feed.php` runs a separate small forum query
   to build the cat-map. If we want to save that RTT, we could expose the
   cat-map from `bb_mirror_left_nav()` as a return value or a global. Minor.

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Prior handoff: `handoffs/2026-05-28-feed-images-header.md`
