# Lane briefing — bb-mirror: whoami fast-path with shim fallback

You're a fresh **bb-mirror** lane. One surgical change. Work in the canonical tree
(`/home/ubuntu/projects/bb-mirror/`) — NOT a worktree (isolation is shelved; dev serves from main).
Commit by pathspec; coordinator reviews, git-tsar pushes. **Coordinator has already ratified this
(option 3) — build it.**

## The problem
The forum is slow because it resolves the viewer **server-side** via the WordPress shim
(`lg_bb_mirror_whoami()`, `bb-mirror/config.php:~135`), which boots all of WordPress to proxy JSON —
~687 ms on a cache miss. The fast endpoint (`/profile-api/v0/whoami`, ~5 ms) keys off the visitor's
`looth_id` JWT, but **many logged-in members don't have a JWT yet** (the unbridged-member gap), so
naively repointing would render real members as logged-out.

## The fix (option 3 — try fast, fall back to shim)
In `lg_bb_mirror_whoami()`:
1. **Try the fast endpoint first** — call `/profile-api/v0/whoami`, **forwarding the visitor's
   cookies** on the loopback call (same way you already forward them to the shim — the visitor's
   `looth_id` JWT must ride along). If it returns `authenticated:true`, use it. ~5 ms, no WP boot.
2. **Fall back to the shim only when the fast path returns anon** — `/wp-json/looth/v1/whoami`
   bridges the WP login session (validates `wordpress_logged_in_*` + adds the trusted headers) and
   catches members who have no JWT yet. This is the slow path, but it now fires only for the shrinking
   set of unbridged members.
3. **Keep the existing 45 s tmpfs cache** (`config.php:148`) — cache the *resolved* identity so even a
   fallback bites only on a miss.

This heals itself: the login lanes (running now, bridge enabled 2026-06-04) are handing every member a
JWT, so over time almost everyone hits the fast path and the shim fallback rarely fires.

## Correctness — this is identity code, so prove the matrix on dev before push
1. **Bridged member** (has JWT) → fast path, correct identity, fast.
2. **Unbridged member** (no JWT) → fast returns anon → falls back to shim → correct identity (NOT
   logged-out).
3. **Anon visitor** → stays anon.
4. **Two different members back-to-back** → no cache bleed. **Verify the cache is keyed per
   user/session** — this is the one real danger (a shared/wrong key = member A sees member B). The
   cache already exists; just confirm its key is per-viewer after your change.

Safe by construction: read-only (no login/write/gating changes), and it fails safe — the fast path
reads the visitor's *own* cookie, so the worst case is under-recognizing them (logged-out), never
showing one member's identity/content to another, never letting anon see member content.

## Scope guard
Touch **only** `config.php` (the whoami resolution). Do NOT touch the 5 currently-held bb-mirror files
(`_chrome.php`, `forums.css`, `forums.js`, `forums/_feed.php`, `forums/_reply-render.php`) — those are
a separate 3-lane tangle the coordinator is hand-separating. Your file (`config.php`) is clean and
doesn't collide. Don't gate posting on `/whoami` (see [[feedback_gate_posting_on_wp_cookie_not_whoami]]).

## Report back
`DONE · FILES (config.php:lines) · VERIFIED (the 4-case matrix, with before/after timing) · BLOCKED`.
Loop perf-czar for the before/after numbers — the whole point is killing the 687 ms.
