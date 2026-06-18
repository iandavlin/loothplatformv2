# → layout-standalone: launch batch (3 independent parts)

Three independently-shippable items for cut. Commit each by pathspec.

## Part A — content pages: /calendar/, /sponsors/, /about/ (+ /contact/)
Build standalone versions (Ian ruled these stay on this install). They wear the shared
header. Source the content from the existing WP pages (port the copy/markup):
- `/about/`, `/contact/` — mostly static; port the page content into a standalone surface.
- `/sponsors/` ("Our Sponsors") — a listing; render from the sponsor CPT/page data (direct
  read, no WP boot), like the other standalone content surfaces.
- `/calendar/` — relates to events; decide whether to render a calendar or fold toward
  `/events/`. (Confirm with coordinator if it should just point at `/events/`.)
- nginx: add `^~` locations per slug → the standalone front controller (archive-poc FPM pool).

## Part B — video→WP fallback, then re-enable video + sponsor
Video + sponsor interception was backed out (only 9/319 video blobs; 1/13 sponsor) — turning
it on would 404 the uncovered majority. Fix:
- In `standalone/render.php`, on **blob-miss** (currently 404 at the lookup), **fall back to
  WordPress** instead — emit `X-Accel-Redirect` to an internal nginx location that proxies the
  same permalink to the WP FPM pool (covered → standalone-fast; uncovered → WP, no 404).
- Then re-enable the `/post-type-videos/` + `/sponsor-post/` (and the clean `/video/`,
  `/sponsor/`) interception in `archive-poc/nginx-snippet.conf`.
- Verify: a covered video renders standalone; an uncovered one serves the WP page (not 404).

## Part C — weekly-email archive page
Replicate the archive's **weekly-emails listing** page on standalone (Ian: "we have a page on
the archive of weekly emails, replicate on standalone"). It's a content-listing over the
`weekly_email` CPT — render the list of past issues (+ link to each issue) standalone, like the
feed but for `weekly_email`. (Sign-up form + actual sending are separate/later, NOT this.)

Master context: `docs/standalone-launch-inventory.md`.
— coordinator (relaying Ian)
