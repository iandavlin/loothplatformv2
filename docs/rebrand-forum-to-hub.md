# Rebrand: /forum/ → /hub/  ·  "Forum" → "The Hub"

Coordinated multi-lane change. **Safety net:** nginx 301s `/forum/` → `/hub/`, so every
existing `/forum/...` deep-link keeps working — lanes can update in any order, no atomic cutover.
The `<forum-slug>/<topic-slug>/` tail is UNCHANGED; only the `/forum` prefix becomes `/hub`.

## Sequence
**Step 1 (bb-mirror) + Step 2 (nginx, me) are COUPLED — land together.** Steps 3 & 4 lag safely.

---
### → bb-mirror  (the forum surface — owner)
1. **Front controller** (`web/index.php`): add `/hub` to the prefix-strip list (it already strips
   `/forum` + `/forums` + `/forums-poc`). `/hub/<f>/<t>/` must parse like `/forum/<f>/<t>/` does.
2. **Internal link generation**: emit `/hub/...` instead of `/forum/...` (wherever the base is set).
3. **Rebrand labels**: "Forum"/"Forums" headings + chrome → **"The Hub"**; `active_nav` key
   `'forum'` → `'hub'` (coordinate the key name with lg-shell so the nav highlight matches).
4. Report to coordinator when #1–2 land → I flip nginx (step 2) in the same window.

### → coordinator / nginx (me — after bb-mirror reports)
- Add a `^~ /hub/` location mirroring the `/forum/` block (same bb-mirror FPM pool + gate).
- Flip the redirects: `/forum/`, `/forums/`, `/forums-poc/` → **301 `/hub/$1`** (currently they
  point at `/forum/`). `/hub/` becomes canonical.

### → archive-poc  (the deep-link dependency — can lag, links 301 meanwhile)
- Build forum URLs as `/hub/<forum-slug>/<topic-slug>/` in `bin/indexer.php`, `bin/backfill.php`,
  `bin/backfill-pg.php`, and the activity-hydrate mu-plugin.
- **Re-run the backfill/reindex** so stored `content_item.url` rows flip `/forum/` → `/hub/`
  (otherwise they keep 301-redirecting — works, just an extra hop).
- `web/_chrome-footer.php` "Forums" link → `/hub/`; any "Forum" labels → "The Hub".
- ⚠️ This is the contract that hard-depends on bb-mirror's slug structure — keep the tail
  (`<forum-slug>/<topic-slug>`) identical to what bb-mirror emits; only the prefix changes.

### → lg-shell  (nav/footer — can lag, 301 meanwhile)
- Shared header nav item "Forum" → **"The Hub"**, href `/forum/` → `/hub/`.
- Footer "Forums" → "The Hub" `/hub/`. `active_nav` key matched to bb-mirror's (#3 above).
- Mirror to `lg-shell/lg-shared/` + commit by pathspec.

## Notes
- Keep the old `/forums/` + `/forums-poc/` 301s alive (now → `/hub/`) — old bookmarks/SEO.
- "Discussions" stays "Discussions" (the content kind); only the **section** is "The Hub."

— coordinator (relaying Ian)
