# Front-page lane → coordinator (2026-06-12)

## DONE
- `4684588` fp-events: event cards link to the event post, not the Zoom call.
  The ecard anchor now goes to the `/event/...` permalink for every tier;
  "Join →" label dropped (gated → "Details →", else "RSVP →"). Tier-gated
  Zoom URL kept ONLY in the 📅 calendar-ICS button data.
- `899d5c2` fp-events: the 📅 button opens a chooser menu — Google Calendar /
  Outlook.com / Office 365 deep-link the prefilled add-event compose in a
  new tab; "Apple / other" keeps the .ics download. Menu on <body> (ecard
  is one big <a>), closes on toggle / outside click / Escape. Calendar URL
  = the server-rendered tier-gated data-url (Zoom entitled, post otherwise).

## VERIFIED
- `4684588`: curl of the live dev page (server-rendered hrefs), both
  audiences: member (Ian, wp 1, JWT) and anon. All cards → event-post
  permalinks; member ICS data-url still carries Zoom; anon page contains
  0 "zoom" strings. php -l clean.
- `899d5c2`: headless Chrome CDP, both audiences @1280: menu opens
  positioned under the button, 4 items; member Google link carries
  dates + Zoom details; anon carries the event-post URL and 0 zoom hrefs
  anywhere in the menu; toggle/outside-click/Esc close; card click still
  navigates to the post. node --check clean. Screenshot:
  /var/www/dev/mockups/cal-menu.png.

- `a1a847d` fp-events: cal-menu dark-mode fix — background was hardcoded
  #fff under token ink; now var(--lg-card-bg) so both dark gates (attr +
  OS) flow through. Verified via CDP both modes; screenshot
  mockups/cal-menu-dark.png.

- `335ca44` fp: "Report a bug or suggestion" modal — What's-New CTA relabeled
  (config.json) + modal (archive.js/css) + server-side mail handler at the
  top of index.php (address never client-side; honeypot + 60s/IP cooldown +
  5k cap; sender identity from whoami appended). Hub-composer href = no-JS
  fallback. Verified end-to-end via CDP: mail in mailpit with identity
  block, 0 address strings in page, 429 on repeat, honeypot drops, dark
  modal correct. Screenshot mockups/feedback-modal-dark.png.

- `34b4b1a` fp-rows: member rails trimmed to NEW "Most recent posts"
  aggregator (12 newest, exclude discussion+misc) + Mandolin + Dan
  Erlewine; the five other rails flipped audience→public so the
  logged-out page is unchanged. Verified both row sets via curl.

- `05fecd8` fp: featured member → Chip Tait (Brooklyn Fretworks, wp 141) +
  Instagram embed (DQcbipnERt1) replaces both promo videos; band now BOTH
  audiences (CSS de-scoped from .is-member). Chip's location_visibility =
  members → the city line renders members-only on the band. video_id kept
  in config as revert; `instagram` field takes precedence.

- (data, no commit) Chip Tait's profile_socials filled from his own public
  web presence: web/instagram/facebook/email/phone (all published on
  brooklynfretworks.com/contact). Renders 5 header icons for members;
  anon sees none (his profile is members-only visibility — by design).

- `f3e9fcb` fp: IG embed OFF again (Ian) — featured videos restored on both
  promo rows via the kept video_id; Chip Tait band stays both audiences.
  Renderer `instagram` support stays dormant in code.

## OPEN
- PRE-EXISTING (not from today's changes): the active-discussions row
  resolves 0 items for BOTH audiences (item_ids:[] in the page JSON) so
  it silently never renders. Query is kind=discussion sort=active.
  Needs a look — possibly the standalone lane's content_item side.
- The LIVE NOW banner (`web/index.php` happening-now block) still sends
  entitled members straight to Zoom ("Join →"). Left as-is — it's a
  join-the-call-now affordance, not a card — but flag if Ian wants it
  repointed too.
- Judgment call: kept the Zoom URL in the calendar ICS for entitled
  viewers (a calendar entry you click at event time to join). Say the
  word if it should also be the post URL.

## TOUCHED
- archive-poc/web/_render-main-row.php
