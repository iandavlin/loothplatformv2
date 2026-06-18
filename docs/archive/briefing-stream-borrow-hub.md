# Lane briefing — stream: borrow look + functionality from The Hub

You're a fresh **stream** lane. The forum ("The Hub", bb-mirror) is well ahead of the stream
(archive-poc `/stream/`) on polish and features. Bring the stream up to Hub parity by **borrowing the
Hub's look and behavior** — they're both activity feeds and eventually converge
([[project_activity_stream_launch]]), so this is the right direction, not throwaway. Work in the
canonical tree (`/home/ubuntu/projects/archive-poc/`) — NOT a worktree (isolation is shelved). Commit
by pathspec; coordinator reviews, git-tsar pushes.

## Study the reference first
- **Live:** `https://dev.loothgroup.com/hub/` (logged in) — the target look/feel.
- **Source:** `bb-mirror/web/forums.css`, `forums.js`, `web/forums/_feed.php`, `web/forums/_reply-render.php`,
  `web/_chrome.php`. (Heads-up: some of this is currently *uncommitted* on dev — the coordinator is
  committing it; reference the on-disk files + the live page.)
- **Your files to bring to parity:** `archive-poc/web/stream.php`, `web/_stream-feed.php`,
  `web/_render-stream-card.php`, `web/archive.css`, `web/archive.js`.

## Borrow these from the Hub
1. **Visual look** — the banner header ("The Hub / ACTIVITY" treatment), card layout, typography, and
   brand theme tokens. Match it so stream and hub feel like one product.
2. **Sort tabs** — New / Old / Hot (the Hub has them; stream should too).
3. **The reader toggles** (top-right of the Hub feed) — **Compact view**, **Larger text** (3-state),
   and the **color theme** (Default → Panels → Dark → Black, 4-state). Port these to the stream.
4. **Threaded replies** — "View N replies" / Reply affordances. Threading is **1 level deep** (top-level
   + one reply layer, no nesting — matches the comments spec). 
5. **Inline actions** — like / comment / (download where applicable). Stream already has likes; wire
   **comments through the NEW comments API** the comments-db lane just shipped
   (`/archive-api/v0/comments` read + `comment-post`) — same archive-poc/discovery stack, natural fit.

## Stream-specific (don't blindly copy)
- The Hub's left nav is **forum categories**; the stream is **content** (articles/videos/loothprints +
  activity). If you add a left rail, it's content facets/types, not forum categories — flag the nav
  question to coordinator before building it.
- Stream cards already carry content thumbnails + the image seam; keep those, restyle to match the Hub.

## Convergence note (north star, not this lane's job)
The toggles + theme + card styles ideally become **shared** components both Hub and stream use, not two
maintained copies. For now, **port** to hit parity fast — but build them clean/extractable so the later
hub+stream merge can hoist them to a shared asset. Don't fork the lg-shell shared header (consume it).

## Verify (dev)
`/stream/` logged-in + logged-out: matches the Hub's look; sort tabs work; all three toggles work + 
persist; replies render 1-deep; inline like + comment work (comment via the new API). Loop perf-czar — 
stream must stay as fast as the Hub (97 Lighthouse) after the restyle.

## Report back
`DONE · FILES · VERIFIED (parity checklist + perf) · DECISION-NEEDED (e.g. left nav) · BLOCKED`.
