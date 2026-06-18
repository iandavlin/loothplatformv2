# Coord note — bb-mirror hub UI can't commit cleanly (lane ordering)

**For: coordinator.** Date 2026-06-04. Nothing staged, nothing pushed; all changes sit
safe in the working tree. This is a sequencing issue, not lost work.

## What's done (mine)
Hub reading-UX, uncommitted:
- **Text-size toggle** — a 3-state "Text size → Large text → Larger text" pill beside the
  Compact pill (scales post/reply/card body copy ×1 / 1.25 / 1.5; persists per browser).
- **Compact view shows forum › leaf** — the breadcrumb (with its existing per-category
  glyph) now renders above the title in compact; timestamp dropped.
- Files: `web/forums.js`, `web/forums.css`, `web/forums/_feed.php`. (`web/_chrome.php` nets
  to zero — ignore.)

## Why it can't be committed in isolation
My work is built **on top of, and interleaved with, two other lanes' UNcommitted bb-mirror
work in the same files** (verified absent from HEAD):

1. **Compact-view feature** — the `feed-compact-toggle` button + `.hub-compact` CSS are
   NOT in HEAD (=0). My "Large text" pill sits beside the Compact pill and my breadcrumb
   scales inside `.hub-compact`, so my work references markup/CSS that isn't in the repo.
2. **Posting-gate** — `lg_bb_mirror_can_post()` / `can_post` is NOT in HEAD (=0). My
   text-toggle button markup is sandwiched between the (uncommitted) Compact button above
   and the (uncommitted) `if ($can_post && …)` gating below, in a single diff hunk with no
   unchanged line between them to split on.

Committing just my hunks would produce a broken commit (references a Compact button + a
`.hub-compact` mode that don't exist in HEAD). There is no clean seam to cut.

## Recommended sequencing
1. **Compact-view lane** commits its bb-mirror work (the Compact toggle + `.hub-compact`).
2. **Posting-gate lane** commits its `can_post` gating (+ reply-image work) — or whoever owns
   the feed/reply lane.
3. **Then my hub UI layers cleanly on top** → I commit it in one tidy pass
   (`bb-mirror: hub reading UX — 3-state text-size toggle + forum/leaf in compact`),
   diffstat for review, no push without sign-off.

## Alternative (not recommended)
Commit the whole bb-mirror working tree as ONE bundle. This mixes three lanes' work into a
single commit (against the clean-increment rule), and I can't vouch the other two lanes'
changes are tested. Only do this if those lanes are also you and you just want it landed.

## Ask
Tell those two lanes to commit their bb-mirror work first (then ping me to commit mine), or
greenlight the bundle. Either way my changes stay parked in the working tree until then.
