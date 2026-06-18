# Lane briefing — FRONT PAGE (archive-poc /front-page/)

You are the front-page lane chat on the Looth dev box (you are `ubuntu`, the
repo is `/home/ubuntu/projects`, branch `main`). Your surface is the
archive-poc front page — dev.loothgroup.com/front-page/ — both audiences.

## Scope (yours)
- `archive-poc/web/index.php` — page shell + row loop + bento (map/events).
  Sends `Cache-Control: no-cache` — NEVER remove (stale-HTML scar).
- `archive-poc/web/_render-main-row.php` — row renderers (activity cards,
  video-promo, cta-bar, sponsors, local-looths…).
- `archive-poc/web/defaults.php` — config fallbacks (featured_member etc.).
- `archive-poc/config.json` — the LIVE row registry (tracked in git, owned
  archive-poc:www-data → edit via `sudo python3` json load/dump, then
  `sudo chown archive-poc:www-data`). The dash UI saves over it — your edit
  can be overwritten by a dash save; tell Ian when you change copy here.
- `archive-poc/web/archive.css` — front-page styles live in the
  `body.view-discover` scoped blocks; don't disturb hub/archive sections.
- `archive-poc/web/fp-map.js` — the map tile (states below).
- The page serves DIRECTLY from this tree (/srv/archive-poc is a symlink) —
  an edit is live on save. php -l / node --check before every save.

## NOT yours
- The Hub (bb-mirror), overlays in /var/www/dev/*.js (buck's lane — pwa.js,
  hub-polish etc.), profile-app internals, nginx. If a fix belongs there,
  write it up for Ian instead of editing.
- Guitardle: DECOMMISSIONED for launch (fast-follow). All code is live-ready
  (`web/_gdle-promo.php`, modal, embed top-5) — do NOT remount or remove.

## Current state (2026-06-12 early AM)
- PUBLIC view = Classic Landing (centered welcome hero, Dan Erlewine video
  2IBxue3zPxE, contained column). MEMBER view = Bento (What's-New promo +
  featured-member band + map/events bento + rails), capped 1200px.
- What's-New row = `video-promo-members` in config.json (`query.html`).
  Bullets render (the Classic-Landing ul-hide is public-row-scoped now).
  CTAs deep-link the Hub composer: `/hub/?compose=suggestion-box-bug-reporting`
  and `/hub/?compose`.
- Wording: "maker" → "looth" everywhere on this page (Ian). Map tile title =
  "Luthiers near you" both auth states.
- Join funnel: every Join button → https://www.patreon.com/c/theloothgroup/membership
  (canonical, also wp_options lgpo_patreon_link); Connect-your-Patreon =
  /connect-your-patreon/ (public standalone). Don't reintroduce /join/ CTAs.
- Map tile (fp-map.js) member states: (1) on the map → live map + You pin +
  closest-luthiers list; (2) never set a location → live map of their GENERAL
  AREA, NO center pin, honest copy, amber "Put me on the map" button (one
  click PUTs me/layout to re-add the section; with a stored place it reloads
  straight onto the map, else routes to their profile); (3) deliberately
  stowed section (me/location `opted_out:true`) → static teaser + same CTA,
  NO live map, NO IP guess — honor the opt-out. Logged-out → static teaser
  only (members API is login-walled; enumeration protection — do not weaken).
  OPEN IDEA Ian may pursue: make the anon tile a join funnel ("join to see
  who's near you" → Patreon link).
- me/location contract: `in_layout` (location in effective layout) and
  `opted_out` (owner SAVED a layout omitting it). Respect both.
- OPEN QUESTION flagged to Ian: `location_public_precision` ("Public sees")
  currently gates no anon-visible surface — either the public profile render
  consumes it or the control should hide. Don't build on it without his call.

## Working rules (standing, Ian)
- Commit clean, tested, logical increments promptly — the tree is contested
  (concurrent sessions + merges have clobbered uncommitted edits). Commit ≠
  push: NO git push without Ian's explicit say-so.
- Parity gate: no user-facing control ships on one surface/audience without
  its counterpart in the SAME change.
- Tiers/entitlement: server-side whoami only; never trust the lg_tier cookie
  (`lg_archive_poc_viewer_tier()` is the one rule). Anonymous fails closed.
  Gated cards must never carry yt ids/body payload in HTML.
- Verify in the real browser: headless Chrome CDP on 127.0.0.1:9222 — load
  the `chrome-dev-login` skill. Member-view testing: mint a JWT via
  `sudo -u profile-app php profile-app/bin/mint-dev-token.php <wp_user_id>`
  (wp 1 = Ian) + the dev-gate cookie from the nginx conf. Cache-bust test
  loads with `?cb=...`; asset tokens: archive.css/fp-map.js bust by filemtime
  automatically.
- Screenshots for Ian → /var/www/dev/mockups/<name>.png (he has the cookie).

## Report-back (end of every work block)
Write a short report to `docs/reply-to-coordinator-front-page.md` AND tell
Ian in chat: DONE (sha + one line each) / VERIFIED (how, which viewport +
audience) / OPEN (decisions you need) / TOUCHED (files). No silent pushes.
