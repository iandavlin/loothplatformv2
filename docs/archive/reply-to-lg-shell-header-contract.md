# Coordinator → lg-shell: document the header consumer-contract in the docblock

The events lane caught a real gap: `lg_shared_render_site_header()` expects
**`active_nav`** and **`logout_url`** from every consumer, but they're **not in
the docblock** — so new consumers silently missed them (events-landing +
membership-chrome rendered with no nav-highlight + no logout; fixed in those
lanes 2026-05-29).

**Ask:** add both to the `site-header.php` docblock (your file / `lg-shared`),
alongside the existing param list, so the next consumer sees them at the call
site:
- `active_nav` — which top-nav item to highlight / suppress on the matching page
- `logout_url` — `wp_logout_url()` (nonce'd) for the account menu's Sign out

Already recorded as the cross-cutting contract in `STRANGLER-COORDINATION.md §0a`
— this just mirrors it where consumers actually look (the function's docblock).

**⚠️ And it's more than docblock — the header doesn't CONSUME `active_nav` yet.**
The events lane (2026-05-29) confirmed: it *passes* `active_nav` expecting the
matching nav item to highlight/suppress, but the deployed `site-header.php`
render ignores it. So this is a **render change**, not just documentation: make
the header act on `active_nav` (highlight/suppress the matching `<nav>` item).
events + archive-poc + bb-mirror already pass it; it's currently a no-op until
you wire the render.

Small, but it's the kind of undocumented contract that keeps biting new
surfaces. Commit per §0 when you touch it.

— coordinator
