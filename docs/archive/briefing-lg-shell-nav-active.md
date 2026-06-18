# Lane briefing — lg-shell: uniform nav + "you are here" active indicator

You're the **lg-shell** lane. One focused change to the **one canonical header** you own
(`/srv/lg-shared/site-header.php` + `site-header.css`). No lane forks this file
([[project_lg_shell_header_keeper]]). Work in `/srv/lg-shared/`. Commit by pathspec; coordinator
reviews, git-tsar pushes.

## The problem
The header currently **suppresses** the nav item for the current page
(`site-header.php:194-198`: `<?php if ($active_nav !== 'stream'): ?><li>…Stream…</li><?php endif; ?>`).
So the active item *disappears*, and the nav looks different on every page (each is missing one item).
Ian wants the opposite: **show all items everywhere, and mark the current one.**

## The change
1. **Always render all nav items** — Stream, Archive, The Hub, Events, Members, Loothtool. Remove the
   `if ($active_nav !== …)` suppression so nothing drops.
2. **Mark the active item** instead — on the `<li>`/`<a>` whose slug matches `$active_nav`, add
   `aria-current="page"` + a CSS hook (e.g. `class="is-active"`). Loothtool is external — never active.
3. **Style the active state** in `site-header.css` — a clear "you are here" indicator. Ian's design
   call on the exact look; sensible default = an underline or a small pointer/dot under the active item
   (keep it within the existing `@layer`/token system so it doesn't get overridden —
   [[feedback_lg_layout_v2_level_overrides]] is the cautionary tale for layered CSS).

Consumers already pass `active_nav` (the §0a contract), so **no consumer changes** — they keep sending
the same slug; you just render it as a highlight instead of a deletion.

## Verify (dev)
Load `/stream/`, `/archive/`, `/hub/`, `/events/`, `/directory/members/`:
- every page shows the **full** nav (all 6 items, identical order).
- the current section is clearly marked (and only that one).
- Loothtool never shows as active.
- logged-in vs logged-out both render the full nav (the active marker is independent of auth).

## Coordinator owns
Updating contract §0a in `STRANGLER-COORDINATION.md`: the active item is now **shown + highlighted**,
not "suppressed on the matching page." (Coordinator will edit it — flag when you ship so it lands together.)

## Report back
`DONE · FILES (site-header.php / site-header.css : lines) · VERIFIED (the 5 surfaces) · BLOCKED`.
