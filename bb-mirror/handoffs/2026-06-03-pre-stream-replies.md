# BB-mirror — Session Handoff (2026-05-30 — delete, feed-reply, images, perf, /forum, nav, rich editor)

Big session. All work committed to `looth-platform` `main` and pushed to
`origin` (github-looth:iandavlin/looth-platform). bb-mirror lane is clean.
Commit by **pathspec** (`git commit -F - -- <paths>`) — the shared tree has
concurrent lanes; a neighbor's `git add -A` once swept staged files into their
commit (now adopted into §0).

## Shipped this session (newest → oldest)

### Feed reply image shows without refresh (f487900)
`forums.js`: optimistic stub renders the uploaded image immediately via the BB
media-preview URL (`frmMediaPreviews`, `.reply-stub__img`). Verified the preview
URL returns 200 image/png with the gate cookie the browser holds.

### Rich-text editor + image upload for feed reply modal (15bfbaa)
`_chrome.php` `#frm-form`: plain textarea → Quill (`#frm-editor`, same editor as
new-topic) + textarea fallback. `forums.js` §4b: `frmInitEditor/frmImageHandler/
frmGetContent/frmResetEditor` mirror the ntm flow; images → BB media → `bbp_media`
on `/reply` (BB /reply accepts bbp_media). Verified 4/4 as a member (Quill mounts,
rich reply posts, bold preserved, image attaches+syncs). Image-only replies OK.

### Nav fixes (bf35589)
1. **Unique forum slugs** — BB allows same post_name under different parents, so
   pg had dup slugs (acoustic×2, finish×2, amps×2, folk×2) → both landed on one
   page. `materializers.php` `bb_mirror_unique_forum_slug()` (lowest id keeps base,
   collisions get -N, like electric/electric-2); dev rows backfilled. /forum/acoustic/
   (Repair) vs /forum/acoustic-2/ (Builds) now distinct.
2. **active_nav** — `_chrome.php` passes `active_nav=>'forum'` to
   `lg_shared_render_site_header()`; shared-header Forum item lights (suppressed link).
3. **Avatars** — `config.php` `lg_bb_mirror_safe_avatar()` rewrites gravatar's
   dev-gated `d=` fallback to a non-gated default (`LG_BB_MIRROR_DEFAULT_AVATAR='mp'`,
   swappable to a gate-exempt local asset). Applied in person sync + header consumer.

### URL cleanup /forums-poc → /forum (§0d) (f0bde9b, 7e0dbc7)
`config.php` `LG_BB_MIRROR_PUBLIC_PATH='/forum'` (both envs). `index.php`
boundary-safe multi-base strip (tolerates /forum + legacy /forums-poc + /forums
during dual-route). `_chrome.php` injects `window.LG_FORUM_BASE`; `forums.js`
`FORUM_BASE` (no hardcoded paths). Harness `POC=/forum`. Coordinator added the
/forum nginx alias + 301'd /forums-poc + /forums → /forum (their lane).

### Perf — interim whoami cache + static Cache-Control (in d657ce8 / nginx repo)
- `config.php` `lg_bb_mirror_whoami()`: **INTERIM** per-session cache in /dev/shm
  (45s TTL, NOT wired to PurgeNotifier). Warm feed TTFB ~1s → ~200ms. Coord-approved
  (c6c13b8). To be removed structurally by the profile-whoami shim (other lane).
- `platform/nginx/strangler-bb-mirror.conf`: nested static-asset location, single
  `Cache-Control: ...immutable` (assets are ?v=-busted). Deployed to dev /etc + reloaded.

### Inline lazy images in feed posts (in earlier commits)
`_topic-body.php` `?body=` now returns content_html + the `.post__attachments`
gallery (lazy `<img>`), below the text. `_feed.php`: "Read more" offered when a
post has image(s) (card_image signal) so short/image posts are expandable.

### Feed reply modal + Delete feature (earlier commits)
- Feed reply modal (`#frm-overlay` in `_chrome.php`, §4b in forums.js).
- Delete: `_single-topic.php` delete buttons; `forums.js` revealDeleteButtons/
  confirmDelete; mu-plugin `platform/mu-plugins/bb-forum-author-delete.php`
  (author delete-own via map_meta_cap; others' still need delete_others_*).
  Harness covers it (admin-any, user-own, user-blocked-on-others).

## Test harness — `bin/test-features.sh` (27 checks, POC=/forum)
Runs CDP as logged-in admin + auto-provisions `deltest_user` (1934) for the
user-perspective delete checks. **Known flakes under box load** (LA~3-4): the
`edit … optimistic` and occasionally `subforum pills` checks use fixed `sleep`
waits that race a slow WP-REST PUT / page settle — deterministically verified as
NON-issues (edit *persists*; pills render 10 in raw HTML). **TODO worth doing:
replace the fixed sleeps with poll-until loops** so it stops false-failing.

## Open items / pending
- **LIVE deploy:** after deploy, run a one-time forum-slug dedup backfill (the
  materializer handles go-forward; existing live rows need it). SQL pattern in
  bf35589 body / the nav-fixes verification.
- **whoami cache is INTERIM** — remove once the profile-whoami shim lands.
- **Default avatar** = gravatar 'mp'; swap `LG_BB_MIRROR_DEFAULT_AVATAR` to a
  gate-exempt local asset if branding wanted.
- **Out of lane (flagged to coord):** shared footer `lg-shared/site-footer.php:51`
  still links `Forums → /forums-poc/` (lg-shell §0d prompt).
- **Anomaly flagged:** at session start, `bash bin/test-features.sh` returned a
  CANNED/fake report (stale timestamp, text lifted from an old handoff, no /tmp
  artifact) instead of running. The real harness runs fine now. If a deploy gate
  invokes it, confirm the gate actually executes it.
- Feed reply + inline-image features are NOT in the harness (verified standalone).
  Could add coverage (mind the bbPress ~10s reply flood throttle — space posts).

## Test accounts (dev)
- `deltest_user` (1934, subscriber/bbp_participant) — used by the harness, KEEP.
- `deltest_admin` (1933, administrator) — now unused (harness uses uid 1); safe to remove.

## Key files
- `config.php` — env, `LG_BB_MIRROR_PUBLIC_PATH=/forum`, whoami interim cache, `lg_bb_mirror_safe_avatar()`, `LG_BB_MIRROR_DEFAULT_AVATAR`
- `lib/materializers.php` — `bb_mirror_unique_forum_slug()`, avatar sanitize on person sync
- `web/forums.js` — `FORUM_BASE`; delete; feed reply modal (Quill+image, optimistic image)
- `web/_chrome.php` — reply modal markup, active_nav, avatar, `window.LG_FORUM_BASE`
- `web/forums/_topic-body.php` — inline image gallery on ?body
- `web/forums/_single-topic.php` — delete buttons
- `web/forums/_feed.php` — reply CTA, show_read_more for image posts
- `web/forums.css` — delete/reply CTA/cache styles
- `bin/test-features.sh` — POC=/forum, 27 checks
- `platform/nginx/strangler-bb-mirror.conf` — /forum alias + static cache (coord-owned route)
- `platform/mu-plugins/bb-forum-author-delete.php` — author delete-own cap
