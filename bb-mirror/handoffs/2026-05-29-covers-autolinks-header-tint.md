# BB-mirror — Session Handoff (2026-05-29, covers + autolinks + header-tint)

## Latest (16:9 covers, legacy autolinks, header tint)

### Feed card image → uniform 16:9 cover, right column of card header
- `_feed.php`: image is the right column inside `.feed-card__header` (where the
  old 88px thumb sat), linking to the topic. Old `.feed-card__thumb*` removed.
- `forums.css`: `.feed-card__cover { flex-shrink:0; width:240px; align-self:flex-start }`
  + `.feed-card__cover-img { width:100%; aspect-ratio:16/9; object-fit:cover }`.
  Mobile (<=640px): header stacks column, cover goes full-width.
- (Iterated: tried top-banner then bottom-bleed; user wanted it back on the right
  like the old thumb but a uniform 16:9 — this is the final.)

### Legacy hyperlinks now clickable (auto-link)
- Legacy content has real `<a>` tags (already worked) AND bare URLs as plain text
  (instagram, youtu.be, store links). WP make_clickable()s bare URLs at render;
  we echo raw, so they were dead text.
- `forums.js`: new `bbAutoLink(root)` runs at the end of `bbProcessEmbeds()` —
  TreeWalker over text nodes, wraps bare http(s) URLs in `<a target=_blank
  rel=noopener class=bb-autolink>`. Skips text inside A/SCRIPT/STYLE/CODE/PRE and
  `.bb-embed` (so embeds + existing links aren't touched/double-wrapped).
- Runs on `.post__body` (single-topic), lazy feed full-bodies, and post-edit re-render.
- `forums.css`: `.post__body a, .feed-card__full-body a, .bb-autolink` → accent +
  underline + `overflow-wrap:anywhere` (long URLs don't blow out card width).
- Verified: store-link topic 48340 → 1 autolink with correct href.

### Header tint = subtle nav/callout color
- `.forum-header` background changed from the sage→cream gradient to flat
  `var(--lg-sage-tint, #eef2e3)` — matches nav-button hover + callout fills.

Harness still 20/20 (embeds unaffected — autolink skips .bb-embed).

---

## (2026-05-29) header redesign + lazy reply loading

### Header redesign + breadcrumb contrast fix + post button moved
- `forums.css`: `.forum-header` is now ONE consistent look — sage→cream gradient
  card with border/shadow, regardless of whether the forum has an image. The
  image (when present) is a faint right-side fade (`__bg` opacity .22, masked).
  Body is a column: parent breadcrumb / title-row / "ACTIVITY" label.
- **Bug fixed**: the parent breadcrumb was invisible on image-headers — the
  `--has-image` variant rendered on a white card but the breadcrumb text was set
  white (`rgba(255,255,255,.9)`). Removed that override; breadcrumb is always sage.
  (That was the "breadcrumb on some not others" report — not stale cache.)
- **Post button moved OUT of the header** onto the New/Old/Hot sort bar,
  right-aligned (`.feed-post-btn`, `margin-left:auto`). `_feed.php` renders it in
  `.feed-sort-bar` now (was `.forum-header__post-btn` in the header title-row).
  Harness selectors updated to `.feed-post-btn`.

### Lazy reply loading (perf)
The feed used to fetch EVERY reply for all ~50 cards, build trees, and render
every stub (hundreds of divs; one topic has 226 replies). Now:
- `_feed.php` reply query → `DISTINCT ON (topic_id) … ORDER BY created_at DESC`:
  ONE teaser reply per card. Renders teaser + a hidden `.feed-card__replies-full`
  container + "View N replies" button carrying `data-topic-id`.
- **New endpoint** `?replies=<id>` → `web/forums/_topic-replies.php` returns the
  full threaded reply HTML (top-level newest-first + indented children). Routed in
  index.php next to `?body=`.
- **Shared render**: `web/forums/_reply-render.php` holds `bb_mirror_avatar()`,
  `feed_rel_time()`, `bb_mirror_render_reply_stub()` — required by both `_feed.php`
  and `_topic-replies.php` so markup is identical. (_feed.php's old local copies
  of avatar/rel_time were removed.)
- `forums.js` §2: "View N replies" now lazy-fetches `?replies=<id>` into
  `.feed-card__replies-full` on first click, then toggles. CSS hides the teaser
  while expanded (`.replies-expanded > .feed-card__replies > .reply-stub`).
- Result: initial feed HTML went from hundreds of reply stubs → 39 (one per card).
  Full thread loads only on demand. ~145KB / <1s.

### Harness now 20 checks — all PASS
Updated #5 to lazy behavior: "replies not rendered until expand (lazy)" +
"'View N replies' lazy-loads the thread".

---

## (2026-05-29) sibling nav on leaves + parent breadcrumb

`web/forums/_feed.php`:
- **Leaf pill nav**: pills used to show only on category pages (forum WITH children).
  Now a LEAF forum shows its SIBLINGS (parent's children) as the pill row, with
  itself marked `.subforum-pill--active` (aria-current). `$pill_forums` =
  children if any, else siblings; `$pill_active_id` = self on a leaf.
- **Parent breadcrumb**: `.forum-header__parent` ("‹ Parent Name") link in the
  header when the scoped forum has a parent. Styled in forums.css.
- **fid-aware URLs**: new `feed_forum_url($f,$slug_freq)` appends `?fid=<id>` only
  for duplicate slugs (folk-bluegrass…, acoustic, finish, amps-pickups-and-pedals).
  Used by both the pills and the parent link so dup-slug forums resolve correctly.
- Verified on the New Builds → folk leaf (fid=3852): breadcrumb "‹ New Builds",
  7 sibling pills, dup-slug siblings carry ?fid, self pill active.

### Harness now 19 checks — all PASS
New: "leaf shows sibling-nav pills", "leaf marks its own pill active",
"leaf header shows parent breadcrumb".

---

## (2026-05-29) inline post editing + admin edit-all

Edit topics + replies inline on the single-topic page. Authors edit their own;
admins/moderators edit everything.

- **`api/v0/auth.php`**: now returns `can_edit_others` (true for
  `edit_others_topics` / `moderate` / `administrator`). Drives which Edit buttons
  the UI reveals. BB REST re-checks server-side regardless.
- **`web/forums/_single-topic.php`**: `.post__edit-btn` (hidden) on the OP and
  every reply, carrying data-edit-kind (topic|reply), data-edit-id, data-author-id,
  data-forum-id, (topic) data-title, (reply) data-topic-id.
- **`web/forums.js` §3c**: `revealEditButtons(viewerId, canEditOthers)` shows Edit
  where `authorId===viewerId || canEditOthers`. `startEdit()` swaps the post body
  for an inline Quill editor (title field for topics), seeded from the rendered
  body HTML. Save → `PUT /buddyboss/v1/topics/<id>` or `/reply/<id>`. Optimistic:
  body updates in place on success (no reload), then `bbProcessEmbeds()` re-runs.
- **Endpoints**: topic PUT needs `id`+`parent`+`title` (+content); reply PUT needs
  `id`+`topic_id`+`content`. Permission: author OR `current_user_can('edit_topic',id)`
  — admins pass via edit_others_topics. VERIFIED admin can edit another user's post.
- **Media safety**: edit PUTs OMIT `bbp_media` on purpose — VERIFIED this PRESERVES
  existing attachments (does not wipe them). Image add/remove during edit is NOT
  supported yet (text/formatting/title only).

### Harness now 16 checks — all PASS
New: "admin sees Edit on a post they didn't author", "edit updates post in-place
(optimistic)", "edit persisted to BB (WP post_content)". Round-trip creates a
throwaway topic, edits via the real UI (Quill), verifies WP content, deletes it.

### Still queued (editing follow-ups)
- Edit from the FEED (currently single-topic page only).
- Add/remove images during edit (would need to send full bbp_media set).
- Delete/trash a post from the UI (REST DELETE exists).
- "edited" indicator / reason_editing log surfaced in the mirror.

---

## (2026-05-28) lock posting to leaf forums + harness nav fix

### Can't post to category/container forums
Categories (and any parent forum that just holds subforums) are placeholders —
posting is now restricted to LEAF forums only. Two enforcement points:
- `web/_chrome.php` composer `<select>`: query excludes `forum_type='category'`
  AND any forum that is a parent (`id NOT IN (SELECT parent_forum_id ...)`).
- `web/forums/_feed.php`: scoped query now selects `forum_type`; computes
  `$is_postable_forum = scoped && empty($child_forums) && forum_type!='category'`.
  The "+ Post here" header button only renders when `$is_postable_forum`.
- Fallback safety: if you're on a category page and open the global "+ New post",
  the category isn't in the select, so it stays at "choose a forum".
- (Server-side BB REST would still accept a category post if hand-crafted — UI is
  locked down, which matches the requirement. Not adding server enforcement.)

### Harness nav() race fix
- `nav()` previously did `Page.navigate` + fixed `sleep 3`. On a slower run the
  assertions queried the PREVIOUS page → false failures (pills/post-btn showed as
  missing because it was reading the site-wide feed). Now polls until
  `document.readyState==='complete'` AND URL path matches, up to ~12s.

### Harness now 13 checks (was 11) — all PASS
Added: "category has NO Post here button", "leaf subforum HAS Post here button",
"composer select excludes category container". Test leaf = `touring-tech`.
Run: `bash bin/test-features.sh`

---

## media-sync race fix + Chrome test harness

### Image "didn't stick" — root cause + fix
- New posts with images: image uploaded + attached as `bbp_media` fine on the BB
  side, but DIDN'T appear in bb-mirror. Cause: BuddyBoss attaches forum media via
  an `edit_post` priority-999 hook that runs AFTER `bbp_new_topic`. Our real-time
  sync dispatched at prio 99 — before `bp_media_ids` was committed — so the async
  `_sync` read the post with no media. Reconcile (10 min) healed it; immediate view didn't.
- **Fix** (`deploy/bb-mirror-sync.php`, DEPLOYED to mu-plugins): added
  `bb_mirror_sync_dispatch_deferred()` that dispatches on `shutdown` instead of
  immediately. Used for `bbp_new_topic`/`bbp_edit_topic` + `bbp_new_reply`/`bbp_edit_reply`
  (the events where media can attach). Other events stay immediate. De-dupes per
  (kind,id,action). By shutdown, `bp_media_ids` is committed → sync captures the image.
- Verified e2e in terminal: upload → create topic w/ bbp_media → attachment row
  auto-lands in pg (no reconcile) → renders on single-topic page.

### Chrome feature test harness — `bin/test-features.sh`
- Self-contained bash harness. Drives headless Chrome via CDP (regenerates its own
  helper), mints gate + WP admin cookies, runs 11 checks, emits PASS/FAIL report to
  stdout + `/tmp/bb-mirror-test-report.txt`. Re-runnable; only write is a throwaway
  topic it deletes.
- Covers: cache-busters, nav accordion toggle, single-visible-reply, inline reply
  "more" (the [hidden] CSS fix), "View N replies" accordion, Quill mount, subforum
  pills, "Post here" button, YouTube embed, image upload→real-time-sync→render.
- **Current: 11/11 PASS.** Run: `bash bin/test-features.sh`
- NOTE: render-check polls (async sync needs a few seconds); don't tighten the loop.

---

# BB-mirror — Session Handoff (2026-05-28, richtext-embeds-media)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP->pg
in real time; systemd timer reconciles every 10 min.

Scope contract: STRANGLER-COORDINATION.md section 3f. Storage: section 3i.

## What landed this session (richtext-embeds-media)

| File | Change |
|---|---|
| `web/_chrome.php` | Quill CSS+JS from jsDelivr CDN in head/footer. Modal textarea → Quill editor container (`#ntm-editor`) + hidden textarea fallback + paste-to-embed hint. `bb_mirror_asset_ver()` filemtime cache-buster on forums.css/js. |
| `web/forums.js` | Quill init (lazy, on authed open) + custom image handler. Image upload → `/media/upload` → track `upload_id` in `ntmMediaIds`. Topic create sends `content` (Quill HTML, `<img>` stripped) + `bbp_media`. Section 2d: client-side embed engine (YouTube/Vimeo/Twitter/IG). |
| `web/forums.css` | Quill toolbar/container/editor overrides. `.bb-embed` responsive 16:9 video iframes, tweet/IG wrappers. Rendered-body `img` styles. |
| `web/forums/_feed.php` | Visible top-level replies: 2 → **1** (most recent only). |

### Rich text composer (Quill 2.0.3)

- Loaded from jsDelivr CDN (`quill@2.0.3`). Both Quill + forums.js are `defer` so order is guaranteed.
- Toolbar: headings (H2/H3), bold/italic/underline, blockquote, code-block, ordered/bullet list, link, image, clean.
- Lazy-init on first authed modal open (`ntmInitEditor`). Falls back to plain textarea if `Quill` undefined.
- On submit: `ntmGetContent()` returns Quill HTML with inline `<img>` stripped + empty `<p>` collapsed.

### Image upload (BB media → bbp_media)

- **Validated end-to-end via curl**: `POST /media/upload` (multipart field `file`) → `{upload_id, upload, upload_thumb}`. Then `POST /topics` with `bbp_media: [upload_id]` attaches it (creates BB media row + our sync mirrors it as an attachment).
- Quill image button → file picker → upload → push `upload_id` to `ntmMediaIds` + inline preview (thumb) in editor.
- Inline preview `<img>` is STRIPPED from content on submit — the real image is stored as BB media and rendered by the mirror's attachment LATERAL join (matches BB's own forum-media behavior; avoids broken `forbidden_*` preview URLs in stored content).
- Auth nonce comes from `/bb-mirror-api/v0/auth.php` (same as reply form). Upload request sends `X-WP-Nonce`.

### Client-side embeds (section 2d in forums.js)

- `bbProcessEmbeds(root)` runs on: page load (`.post__body`, loaded full-bodies) + after feed lazy-load (`?body=` fetch).
- Three detection passes: (1) bare `<a>` whose text==href, (2) `<p>` whose entire text is one URL, (3) container with no element children that is just a URL (covers content_html stored as a bare URL, e.g. topic 5655).
- Providers: YouTube (`/embed/`), Vimeo (`player.vimeo.com`), Twitter/X (blockquote + widgets.js), Instagram (blockquote.instagram-media + embed.js, best-effort).
- **Verified live in headless Chrome**: topic 5655's bare YouTube short → `youtube.com/embed/vT_PmmGEFjI` iframe.

### Cache-buster (fixes the "more still broken" report)

- forums.css/js URLs now carry `?v=<filemtime>`. Root cause of "more on replies still broken": browser served a STALE cached forums.js. Both `… more` (inline) and "View N replies" (accordion) handlers were verified WORKING on fresh load via CDP — the bug was caching, now fixed permanently.

### Single visible reply

- Feed cards now show only the **most recent** top-level reply (was 2). Rest go behind the "View N replies" accordion. Verified: `visible_toplevel_replies: 1`.

## Headless Chrome verification (this session)

- YouTube embed renders: PASS (`youtube.com/embed/...` iframe present)
- Quill mounts: PASS (toolbar + editor + image button, form visible)
- Inline `… more` click reveals full text + removes button: PASS
- "View N replies" accordion reveals overflow (display none→flex): PASS
- 1 visible top-level reply per card: PASS
- forums.js URL version-stamped: PASS
- Screenshot: /var/www/dev/mockups/ntm-quill.png

## Gotchas

1. Quill loaded from CDN — if jsDelivr is blocked/down, composer falls back to plain textarea (hidden `#ntm-content`). Acceptable degradation.
2. Image upload field name is `file` (multipart). `/media/upload` reads `$_FILES` directly — CANNOT test via WP-CLI (is_uploaded_file fails); must use real HTTP multipart.
3. `bbp_media` on `/topics` wants the UPLOAD ids (from `/media/upload`), not media ids.
4. Embeds are CLIENT-SIDE only (bb-mirror FPM pool has no WP/oEmbed). Existing posts get embeds via regex; exotic providers fall through to plain links.
5. Inline images stripped from submitted content on purpose — bbp_media is the storage path.
6. Cache-buster uses filemtime(__DIR__/<file>) — edits auto-invalidate. No manual version bump needed.
7. Four duplicate forum slugs (finish, acoustic, amps-pickups-and-pedals, folk-...) use ?fid=<id>.
8. bb-topics (BB activity topics) ≠ topics (bbPress forum threads).

## Postgres infrastructure (unchanged)

- DB looth, schema forums, role bb-mirror
- 55 forums, 1128 topics, 4405 replies, 465 persons, 1549 attachments
- 1593 threaded replies (parent_reply_id set)
- Reconcile cron every 10 min via bb-mirror-reconcile.timer

## Still queued

1. **Rich text in REPLIES** — reply form on single-topic still plain textarea. Could get the same Quill treatment + image upload (reply endpoint is `/reply`, takes `bbp_media` too).
2. **Embed in composer preview** — pasted URLs don't preview as embeds inside Quill (they embed on the rendered page after post). Minor.
3. Subforum pills: highlight active subforum.
4. Optgroup sort fix in forum select (cosmetic repeats).
5. Group-member-aware private visibility — needs /whoami + group-membership table.
6. Reply-form group gating — "Join SoCal to post here" CTA.
7. Shared header swap — placeholder, waits on archive-poc.
8. featured_image_url sync, avatar sync — upstream sync gaps.

## Pointers

- Coordination doc: /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md
- Prior handoff: handoffs/2026-05-28-nav-threading-pills.md
- Chrome verify: chrome-dev-login skill, CDP 127.0.0.1:9222
