# Briefing — lean the comments modal (`?lg_comments=1`)

**Paste into a fresh chat.** The comments modal is slow on **both CPTs and the new `/stream/`** because
its iframe target `?lg_comments=1` drags in the full BuddyBoss theme + Elementor. Make it fast. Stay
in-lane: the `lg-comments-frame` mu-plugin. Header/whoami are cross-cutting → coordinator.

## Measured problem (coordinator, 2026-06-03)
`GET /<post>/?lg_comments=1`: **TTFB 1.2s, total 3.3s, 48KB**, response carries **BuddyBoss theme +
Elementor** assets (7 elementor / 4 buddyboss / theme CSS refs). The standalone page it opens *from*
is **66ms**. So the modal is ~50× slower than its host page — for a comment thread.

## What's already right (do NOT rebuild)
`/var/www/dev/wp-content/mu-plugins/lg-comments-frame.php` already renders a **lean comments-only
template** on `?lg_comments=1` (its own markup + WP's native comment form + `wp_head()`, no theme
chrome) via `template_redirect`. The template is fine. The slowness is two leftovers around it.

## The two real costs
1. **`wp_head()` still prints BuddyBoss + Elementor enqueued assets** (those plugins hook
   `wp_enqueue_scripts` globally; nothing dequeues them for this view). → **This is the big, easy win.**
   Dequeue everything not needed for a comment thread before `wp_head()` — an Isolate-style allowlist
   (keep: WP comment-reply JS, jquery if the form needs it, this plugin's own inline CSS; drop: the BB
   theme CSS/JS + all Elementor + Elementor-Pro + Dynamic-Content). Mirror the dequeue pattern in
   `lg-layout-v2/src/Isolate.php` (`dequeue_styles`/`dequeue_scripts` + allowlist). Expect the 3.3s
   total to collapse.
2. **~1.2s TTFB = Elementor + BuddyBoss plugins fully booting** on every request (PHP init runs before
   `template_redirect`, so you can't stop it from here). This is the harder, lean-WP class (same family
   as the whoami SHORTINIT fix). **Assess it AFTER #1** — once the asset weight is gone the modal may
   feel fine; if the 1.2s floor still hurts, flag to coordinator for the lean-WP treatment (don't build
   that solo — it's cross-cutting).

## First move
Dequeue the BB/Elementor assets on the `?lg_comments=1` view (#1). Measure before/after with the
**perf-czar lane**. Then judge whether the PHP-boot floor (#2) needs escalation.

## Constraints
- In-lane = `lg-comments-frame.php` only. Don't touch the header, `/whoami`, or other plugins' code.
- The comment **form must still work** (posting a comment) — don't dequeue what the form/submit needs;
  verify a comment still posts after the dequeue.
- Leave changes uncommitted for review-before-push (coordinator gate). Test in-browser via `chrome-dev-login`.

## Report back to coordinator
Before/after (TTFB, total, transfer), what you dequeued, confirmation the comment form still posts,
and whether the ~1.2s PHP floor remains (→ escalate for lean-WP if so).
