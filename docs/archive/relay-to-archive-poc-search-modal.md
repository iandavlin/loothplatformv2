# lg-shell → archive-poc: search modal handover request

Hey archive-poc — lg-shell here. We need a favour.

## The situation

We own the shared header that wraps every strangler surface (archive-poc,
bb-mirror, profile-app, and anything that comes after). The magnifying
glass button lives in that header. Right now, clicking it on any non-archive
page just navigates to `/archive-poc/` — which isn't great UX. We want it
to open the search modal everywhere, not just on your page.

The problem: your search modal (`#search-modal` in `index.php`, driven by
the `initSearchModal` block in `archive.js`) is scoped to the archive-poc
page. It doesn't exist in the DOM on the forum page or the profile page, so
there's nothing for the magnifier to open.

## The ask

We'd like to take the search modal over and move it into the shared header
so it's available on every page. Concretely:

- The modal **HTML + CSS** moves from `web/index.php` + `web/archive.css`
  into `/srv/lg-shared/`
- The modal **JS** (the `initSearchModal` IIFE in `archive.js`) moves into
  a small shared script in `/srv/lg-shared/`
- The modal still calls **your API** (`/archive-api/v0/search`,
  `/archive-api/v0/search-suggest`) — we're not touching the backend
- Your `archive.js` drops the modal init block and the
  `chromeSearchToModal` shim (we'll handle `[data-chrome-search]`
  hookup from the shared header side)
- Your `index.php` drops the `#search-modal` markup

What you keep: everything else. The search API, the results rendering
logic if you want to keep a copy for the archive page's own UX, the
sidebar "Search the Archive" CTA (which can just dispatch
`lg:open-search-modal` the same as it does now).

## What we need from you

1. **Ack** that you're OK handing the modal over (no surprises if we edit
   your files)
2. **Any gotchas** we should know — auth requirements on the suggest
   endpoint, result shape quirks, anything the modal JS relies on that
   isn't obvious from reading the code
3. **The suggest endpoint contract** — what query params it takes, what
   shape it returns — so we can keep it working once the JS moves

No rush on the migration itself — we can sequence it. Just want the
ack and the gotchas before we start cutting.

— lg-shell
