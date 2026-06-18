# Briefing — lg-layout-v2 lane: image-block lightbox regression

**Paste into a fresh chat.** The **lightbox on image blocks stopped working** ("now" = a recent
regression — it used to open). Find the cause and fix it. Stay in-lane: lg-layout-v2 image block +
its front-end JS. Header/whoami are cross-cutting — route those to coordinator (don't touch them).

## What's already known (don't re-derive)
- The lightbox JS is **`lg-layout-v2-front`** → `assets/lg-front.js` ("our own front-end JS — lightbox + popout").
- It **IS on `Isolate::SCRIPT_ALLOWLIST`**, so the managed-CPT dequeue pass is **NOT** stripping it.
  → This is almost certainly NOT an isolation/allowlist problem. Look at the binding, not the enqueue.
- **Prime suspect: commit `2f28fae`** ("block refinements (callout/embed/image/post-footer) …") —
  it changed `blocks/image/render.php` + `blocks/image/shell.css`. That's the markup + CSS the
  lightbox attaches to and renders over. Other commits in the window: `1a3b267` (header — unlikely),
  `cbef2be` (older, MANAGED_CPTS/FE-editor).

## First moves
1. `git show 2f28fae -- blocks/image/render.php blocks/image/shell.css` — see exactly what changed.
2. In `assets/lg-front.js`, find what the lightbox **binds to** (selector / class / data-attribute) and
   what it expects in the DOM. Compare against the post-`2f28fae` render.php markup — a renamed/removed
   class or wrapper is the classic break.
3. Reproduce in a real browser (load the `chrome-dev-login` skill): open a post with an image block,
   click the image. Check: is `lg-front.js` actually loaded? Any console error? Does the click handler
   bind? Is a CSS change (`pointer-events`, `overflow`, `position`, `z-index`, an overlay) now
   intercepting or hiding the click target?

## Two render paths — check both
- **WP path** (`?lg_edit=1` / logged-in): rendered by the plugin; `lg-front.js` enqueued via `WpAssets`.
- **Standalone path** (public posts via `archive-poc/standalone/render.php` + the **vendored engine
  copy** of the image block): confirm the standalone page even includes `lg-front.js`, and that its
  vendored image-block markup matches what the lightbox expects. The standalone copy is vendored —
  if the fix touches it, flag to coordinator (don't fork it silently).

## Constraints
- In-lane = image block (`blocks/image/*`) + `assets/lg-front.js`. Do NOT edit the shared header,
  `/whoami`, or Isolate's allowlist without coordinator sign-off.
- Changes land uncommitted for review-before-push (coordinator gate); don't push.

## Report back to coordinator
Root cause (which commit/line broke it), the fix, both-path verification (WP + standalone, in-browser
lightbox actually opens), and whether the vendored standalone engine copy needed the same change.
