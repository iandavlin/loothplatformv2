# Hub card density — spec for bb-mirror (2026-06-06)

Three density tiers for the Hub feed card. Same card markup, CSS-switched. Post-deploy (pairs with the
expand-on-click / one-page Hub direction — not a cut blocker).

Reference — the full element vocabulary on one fake card: `https://dev.loothgroup.com/mockups/hub-card-verbose.html`

## The three tiers
1. **Compact** — dense scannable list. Toggled by the EXISTING `.feed-compact-toggle` in the rail
   (`_filter-rail.php` already renders it). User opt-in for "show me more, smaller."
2. **Semi-verbose** — the DEFAULT feed card. Enough to read + react without opening.
3. **Max-verbose** — the FULL card. Shown **on demand**: when a card is expanded in place
   (expand-on-click) and inside the thread/reply view. This is where everything renders.

## Element matrix (what shows at each tier)
| Element | Compact | Semi (default) | Max (expanded) |
|---|---|---|---|
| Avatar / logo | small | yes | yes (larger) |
| Author name | yes | yes | yes |
| Business name | — | yes | yes |
| Badges (OP / Sponsor / Verified) | OP only | OP + Sponsor | all |
| Member subline (since · location · post-count) | — | — | yes |
| Category breadcrumb | pill only | full breadcrumb | full breadcrumb |
| Type badge | icon | yes | yes |
| Time / edited | — | time | time + edited |
| Title | yes (1 line, truncate) | yes | yes |
| Cover / media (▶ / duration) | — | yes | yes (full) |
| Body excerpt | — | 2 lines + Read more | full body |
| Tags | — | — | all |
| Product / download strip | — | price chip only | full strip (files, vendor) |
| Engagement bar (reactions · comments · share · save · views) | counts only | full bar | full bar |
| Reply teaser | reply count | 1 reply + "View N" | full thread expanded |
| Inline composer | — | — | yes |

## Interaction
- **Compact toggle** (rail) → adds `feed--compact` on the feed; CSS collapses each card to the Compact
  column. Already wired to a class — this spec just defines what that class hides.
- **Default** = semi-verbose, no class.
- **Expand-on-click** → a card gets `feed-card--max` (+ fetch full body/replies WP-free); CSS reveals
  the max-only elements. Single-open is fine (collapse others) to keep the DOM light.
- The **thread/reply view** renders at max by definition.

## Per-type subsets (within a tier, drop what doesn't apply)
- **Discussion** → no cover unless an image is attached; no product strip.
- **Video** → cover + ▶ + duration; body optional; no product strip.
- **Loothprint / sponsor product** → product/download strip is the point; short body.
- **Article** → cover + body excerpt; no strip.
Gate every element on "data present," so a sparse post degrades cleanly instead of rendering empty slots.

## Implementation (bb-mirror)
- `forums/_feed.php` — render the FULL element set with stable classes; let CSS show/hide per tier
  (don't branch the PHP per density — one markup, three CSS states).
- `forums.css` — three density blocks: `.feed--compact .feed-card`, default `.feed-card`,
  `.feed-card--max`. Mobile (≤640) keeps the app-card look from Step 1.
- Compact toggle already exists — wire it to `feed--compact` on the feed container if it isn't already.
- Max tier shares the WP-free read endpoints (comments ~30ms) for body/replies on expand.

## Verify
- Toggle compact → cards collapse to the list, counts still readable, no horizontal scroll at 390.
- Default feed → semi-verbose matches the matrix; sparse posts (no cover/strip) render clean.
- Expand a card → max elements appear (full body, full replies, composer); collapse others.
- Desktop unchanged in default.

## Status
Post-deploy. Captured alongside the **merged dual-action rail** (`hub-merged-rail.html`) and the
**one-page expand-on-click** direction — they're one redesign. Sequence after the cut.
