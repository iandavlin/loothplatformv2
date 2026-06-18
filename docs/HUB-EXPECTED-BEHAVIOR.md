# The Hub (/hub/) — master expected-behavior list

The source of truth for what /hub/ (the bb-mirror reader) should do. Each item is
tagged with harness coverage so this doubles as the regression-test roadmap:

- ✅ asserted by `bb-mirror/bin/test-features.sh`
- ⬜ not yet asserted (gap — a regression here would currently go undetected)

> /hub/ is the **reader**; the new /stream/ is the destination. Don't invest in
> /hub/ as a standalone destination. Writes go through the reply-write endpoint
> (`docs/reply-write-endpoint.md`).

---

## Replies (feed thread — "View N replies")

- **R1 — Lazy thread.** Replies are NOT rendered until the card's "View N replies"
  is clicked; the thread fragment loads from `/hub/?replies=<topic_id>`. ✅
- **R2 — One teaser.** A collapsed card shows exactly ONE teaser reply (newest). ✅
- **R3 — Only 5 open at a time.** The expanded thread shows **5 reply rows**, then a
  **"Load N more replies"** button; each click reveals the next 5 (offset paginated,
  appended in place). Rows = the whole thread flattened in reading order (DFS). ⬜
- **R4 — Two-tier nesting.** Top-level + one child indent; replies deeper than a
  direct child render at the child indent with a "↪ @author" prefix. ⬜
- **R5 — Images in replies, on click.** A reply with an image shows a "📷 Show image"
  button; the image has NO `src` until clicked, then it reveals (lazy by design). ⬜
- **R6 — "… more" excerpt.** Long reply text truncates ~160 chars with an inline
  "… more" toggle. ✅
- **R7 — Newest/Oldest sort.** Threads with >1 reply show a Newest/Oldest toggle
  (first page only); switching re-fetches the thread in that order. ⬜
- **R8 — Every rendered reply has an author name** (never blank/initial-only; falls
  back to "Anonymous", never empty). ⬜ ← *would catch the "nameless user" regression*

## Reply compose / write

- **W1 — Per-reply Reply chip.** Each reply (incl. the teaser) has a "↩ Reply" chip
  that opens the composer scoped to that reply (nested). ⬜
- **W2 — Rich composer.** Reply + new-topic composers mount Quill (toolbar + editor
  + image upload). ✅
- **W3 — Reply-write endpoint.** `POST /bb-mirror-api/v0/reply` → 200 published /
  202 pending (moderation) / 429 flood (Retry-After). ⬜ (`docs/reply-write-endpoint.md`)

## Identity / header  (header convergence — lg-shell keeper)

- **H1 — Logged-in header.** A logged-in viewer's header shows their real
  `display_name` + avatar (photo, else initial) + tier pill — from /whoami, identical
  to /archive/ and /u/. ⬜ ← *would catch the "logged-out header" regression*
- **H2 — Header category colour-code.** The forum-header tints by category. ✅

## Nav / layout / colour

- **N1 — Sidebar pills + filled chevron buttons**, subtle category-coloured borders. ⬜
- **N2 — One category colour palette** shared by sidebar pills, feed-card rails, and
  the banner (9 distinct category colours). ⬜
- **N3 — Sticky sidebar** sits below the site header (not obscured) on scroll. ⬜
- **N4 — Suggestion Box** renders as its own standalone pill (not under General). ⬜
- **N5 — No category/leaf pills in the feed header** (nav is the sidebar's job). ⬜

## Feed / cards / media

- **F1 — Cards link to their topic** (clickable; images open lightbox, not nav). ✅/⬜
- **F2 — Image lightbox.** Clicking any forum image opens a full-size overlay
  (close: ✕ / backdrop / Esc). ⬜
- **F3 — Embeds.** Bare provider URLs (YouTube/Vimeo/IG/X) render as embeds. ✅
- **F4 — Teaser/feed images lazy-load** (`loading="lazy"`). ✅
- **F5 — "Post here" gating.** Leaf forums show "+ Post here"; containers don't;
  All/category show the banner "+ New post". ✅ (partial)

## Permissions / safety

- **P1 — Edit** own posts; admins/mods edit any. ✅
- **P2 — Delete** own posts; admins delete any; users can't delete others'. ✅
- **P3 — Sanitised mirror.** No `<script>`/`onerror=`/`javascript:` survives sync. ✅

## Search

- **S1 — Search results** render as activity cards; matches highlighted via `<b>`. ✅
