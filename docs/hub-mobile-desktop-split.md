# Hub — mobile / desktop split (CONFIRMED 2026-06-06, Ian + Buck)

**Decision:** one server-rendered feed, two presentation layers split at **640px**, disjoint files.
Buck owns the mobile FB app-card look; Ian/Hub lane owns desktop + the shared render. Achieved by
**CSS-arrange on a FLAT shared markup — NOT JS DOM-reshape** (reshape = the mobile flash; banned).

## Architecture
```
SHARED (coordinator-governed):  _feed.php renders the FLAT card contract (below) — ONE grid container
                                per card, all regions as siblings. The markup/classes/data-attrs ARE
                                the contract. Data query, gating, counts shared. Contract changes are
                                announced to both lanes.
DESKTOP (Ian / Hub lane):       forums.css scoped @media (min-width:641px) — never styles mobile.
MOBILE  (Buck):                 mobile-hub.css @media (max-width:640px) — CSS-arranges the flat markup
                                into the FB app-card (grid-template-areas). mobile-hub.js = BEHAVIORS
                                ONLY (swipe, infinite scroll) — never the look.
```

## THE CONTRACT — flat, grid-arrangeable card markup (Hub lane renders this)
Every region a DIRECT child of `.feed-card` (the grid container). No nesting in branches — that's the
whole point, so `grid-template-areas` can place any region anywhere per breakpoint.
```html
<article class="feed-card" data-lg-card="1" data-id="{id}" data-type="{discussion|video|article|loothprint|…}"
         data-href="{permalink}" data-gated="{0|1}">
  <a    class="fc-avatar"   href="{author.profile}"><img src="{author.avatar}" alt=""></a>
  <div  class="fc-author">
    <span class="fc-author__name">{name}</span>
    <span class="fc-author__biz">{business}</span>
    <span class="fc-author__badges">{OP/Sponsor/Verified}</span>
  </div>
  <nav  class="fc-category">{parent › leaf breadcrumb}</nav>
  <time class="fc-time" datetime="{iso}">{relative}</time>
  <a    class="fc-cover"    href="{href}"><img …>{play+duration if video}</a>
  <h3   class="fc-title"><a href="{href}">{title}</a></h3>
  <div  class="fc-excerpt">{excerpt}…</div>
  <div  class="fc-actions">{engagement bar — reactions · comments · share}</div>
  <div  class="fc-replies">{reply teaser / count}</div>
</article>
```
- All `fc-*` are **direct children** of `.feed-card`. Stable class names. `data-*` on the article = data contract.
- Absent pieces (no cover, etc.) render nothing → CSS degrades cleanly per type.
- `.fc-actions` = the reactions-comments SURFACE lane's shared partial, emitted as one flat region.
- Desktop CSS arranges these as a forum row; mobile CSS (Buck) arranges them as the FB app-card. Same markup.

## Loading (the no-flash gotcha — IMPORTANT)
- **`mobile-hub.css` MUST be a `<link>` in `<head>`**, media-gated, so it paints on first load:
  `<link rel="stylesheet" href="/mobile-hub.css?v=…" media="(max-width:640px)">` — server-rendered in
  `_chrome.php` `<head>` (or via the nginx sub_filter). **Do NOT inject it via pwa.js** — deferred JS
  applies CSS after paint = the flash returns.
- **`mobile-hub.js` (behaviors only)** → fine to defer via pwa.js, mobile-gated.

## Ownership / rules
| Piece | Owner |
|---|---|
| Flat card markup + data query + gating + the contract | coordinator-governed (Hub lane renders) |
| `.fc-actions` engagement-bar partial | reactions-comments SURFACE lane |
| Desktop CSS `@media ≥641` (`forums.css`) | Ian / Hub lane |
| Mobile CSS `@media ≤640` (`mobile-hub.css`) + `mobile-hub.js` behaviors | **Buck** |
- Buck never edits desktop CSS / forums render; Ian never edits `mobile-hub.*`. Shared-edit surface =
  the flat markup contract only (coordinate via coordinator; contract changes announced).
- Mobile band-aids (`app-mobile-fixes.js`: brand-light over dark/black, strip hub-compact, sort-bar
  header-tuck) **fold into `mobile-hub.css`** under the split. The sticky-sort-bar + hide-view-toggles
  already landed canonical — Buck drops those two from the band-aid.

## Who needs to know
Hub/desktop lane (render flat contract + gate CSS ≥641), Buck (mobile layer), reactions-comments
SURFACE lane (`.fc-actions` partial in the contract), mobile-czar (convergence target = Buck's mobile
layer now), git-tsar (new files: `mobile-hub.css/js`, the `<head>` link).
