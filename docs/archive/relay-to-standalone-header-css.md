# → layout-standalone / archive-poc: front-page is missing the shared header CSS

## Symptom
On the archive-poc front feed (`/front-page/`) and search (`/archive/`), the header's
social modals render RAW — unstyled, dumped inline below the header — instead of sliding
in off-canvas. They look correct on events, `/u/`, bb-mirror, and the standalone CPT pages.

## Root cause
Those pages render the shared header (modal markup + `social-modals.js`) but never load the
shared stylesheet that skins it. `archive-poc/web/index.php` links only its own
`/archive-poc/archive.css`; it does NOT link `/lg-shared/site-header.css` (which holds the
`.lg-social-modal` skin). Every other consumer links it — the front-page + search don't.

## Fix (purely additive — one `<link>` per file)
Add this to the `<head>` of `archive-poc/web/index.php` AND `archive-poc/web/search.php`,
alongside the existing archive.css link — copy the exact pattern the working surfaces use:

```php
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
```

That's verbatim how `events/web/index.php:86`, `profile-app/web/u.php:70`, and
`archive-poc/standalone/render.php:277` already pull it in — copy a working pattern, don't invent.

## Contract
Purely additive — a stylesheet include. Does NOT touch `lg_shared_render_site_header()`,
`social-modals.js`, or any data path, so it cannot regress the shell lane. Owns the edit:
the standalone/archive-poc chat (these are `archive-poc/web` files).

## Verify (with the seeded data on user 1)
Load `/front-page/` logged-in → click bell / messages / friends → they should slide in
off-canvas, matching events. (Whether they show *data* still depends on shell's
`social-modals.js` fix — see `relay-to-shell-social-modals.md`. This relay is skin-only.)

— coordinator
