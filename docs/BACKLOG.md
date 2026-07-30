# Backlog

Items Ian has asked for that no lane owns yet. When a lane picks one up, move the
line into that lane's charter and note the lane name here.

## PRIORITY INDEX (the order — edit THIS to re-rank; tell keeper "bump X")

**P0 — live/member-facing bugs**
1. Reacting on a shorty fails on LIVE
2. BUG: fresh reply prefilled with last reply's data — *edit-post-parity fixing*
3. Profile social links go stale on posts/events (member-reported)

**P1 — wanted now / deploy-blocking**
4. Follow-controls consolidation modal — *deploy gate; thread-follow building*
5. Notifications: quick-reply modal (default) w/ full-post link
6. Admin can edit ANY post, full functionality
7. Discussion hub cards: play video inline ★
8. Front page: latest weekly email for logged-out users

**P2 — polish / UX**
9. Advanced search: dynamic facet narrowing
10. Add-discussion modal: resizable + text scaling
11. Group chat header: collapse + text scale
12. Post header: title legibility over text thumbnails
13. PWA launch animation/message
14. Mail-containment: gate on host not LG_ENV (latent security)

**P3 — big builds (scope first)**
15. Front-end authoring for all post types ★ vision
16. Stripe membership: audit → build

---
*Full item details below, newest-first. The index above is the running order.*

## 2026-07-30 — Front page: show the latest weekly email to LOGGED-OUT users (Ian)

Ian 7/30: surface the most-recent weekly email on the FRONT PAGE for logged-out
visitors. Reuse what already exists — the signup page renders the latest sent
issue dynamically via LG_WD_Signup_Page::latest_sent_issue_id() +
LG_WD_Issue::get_data() through the same email builder, cached 1h
(class-lg-wd-signup-page.php). The front page is archive-poc's discovery feed
(served at / and /front-page/; widgets driven by config.json overlaying
web/defaults.php). So: add a front-page block/widget, shown only when
logged-out, that embeds the latest issue preview (same dynamic source, honor the
1h cache) with a signup CTA. Gate to anon (members already get the digest / the
Hub). Craft gates apply (public surface). Pairs with the public signup page just
shipped.

## 2026-07-30 — Notifications: quick-reply-in-modal vs go-to-post (Ian) — MEDIUM-LOW effort

Ian 7/30 (refined): tapping a notification DEFAULTS to opening a reply MODAL —
the modal shows the reply that generated the notif + a composer, AND carries a
link/button to the full post. No two-action row; the modal is the default, the
full-post link lives inside it. KEEPER ESTIMATE: not hard, most pieces exist —
- The notif ⋯ menu already exists (the hook for a "Reply" action / two-action row).
- openComposerSheet is reusable in reply mode (no new composer).
- Each notif already resolves to its source reply/comment (context to show).
New work: two-action affordance on the notif row (View → post, Reply → modal),
the modal showing the source reply as context + composer, submit → existing
reply-to endpoint. Reuses composer + reply POST. Coordinate with thread-follow
(owns notif surface) and the composer reset-vs-prefill fix. Member-facing, high
value now that follow-notifs are live.

## 2026-07-30 — BUG: a fresh reply is pre-populated with the LAST reply's data (Ian)

Ian 7/30: starting a NEW reply opens the composer already filled with the
previous reply's content. The composer isn't clearing its state between uses —
stale body/photos/fields carry over. Real member-facing bug: risks posting the
wrong or duplicated content. Likely the reused composer (openComposerSheet) not
reset on open for a fresh reply (vs edit, which SHOULD prefill). Fix: on open-
for-new-reply, hard-reset all fields; only prefill when opening in edit mode.
NOTE for the edit-post-parity/composer lane: this is the same composer you're
working — the reset-vs-prefill distinction matters (new = empty, edit = filled).

## 2026-07-30 — Advanced search: dynamic facet narrowing (Ian)

Ian 7/30: as options are picked in advanced search, REDUCE the remaining
options to only those that would actually return content. E.g. pick author
"Dan Erlewine" → the tags, content-topics, type, etc. facets update to show
ONLY values that co-occur with his content (non-empty result guaranteed).
Dynamic, live as each facet is chosen — no dead-end combinations that return
zero. Needs: facet counts recomputed against the current filter set on each
pick (server endpoint returning available values+counts for the remaining
facets), and the UI disabling/hiding zero-result options. Applies to the adv
search surface (the Advanced Search fold). Member-facing polish.

## 2026-07-30 — Admin can edit ANY post, full functionality (Ian)

Ian 7/30: "I need to be able to edit any post as admin as well. Full
functionality on any post editing as admin." Admin (or can_edit_others) edits
ANY post type — discussion, video, article, event, loothprint, sponsor — from
the front end with the FULL composer (every control creating it offers). Today
edit is wired for discussions/OP (author or mod); this generalizes it to all
types for admins. Depends on the composer-unification decision below (one
composer that handles every type + create/edit + desktop/mobile). Pairs with
the front-end-authoring-all-types vision.

## 2026-07-30 — Add-discussion modal: resizable, with text scaling to modal size (Ian)

Ian 7/30: make the "New post" (add-discussion) 4-step modal RESIZABLE, and have
the TEXT SIZE scale with the modal size — bigger modal → bigger text, so it
stays comfortable when enlarged rather than tiny text in a big box. Applies to
the discussion composer wizard (Where/Write/Photos/Review). Pairs with the
edit-discussion work (edit reuses this same modal). Likely a resize handle +
type scale bound to modal width (clamp/container units), both light and dark.

## 2026-07-30 — Follow controls: collapse the cramped row into a modal + email frequency (Ian)

Ian, looking at the topic action row (Like / 8 replies / Share / bell / envelope
/ Save all crammed on mobile): "this is really cramped. Should we have a button
that pops a modal?" AND: email frequency controls so users pick cadence —
"1 per hour, 1 per day, 1 per week or something."

KEEPER SYNTHESIS (recommended): these are one feature. Replace the inline
personal-action icons with a SINGLE control that opens a small modal/sheet
holding everything: notify on/off, email on/off, email frequency (Off / Instant
/ Hourly digest / Daily / Weekly), AND **Save/bookmark** (Ian added Save to the
consolidation 7/30). Like / N-replies / Share stay inline; the set-once personal
stuff (follow, email, save) collapses behind one button.

DESIGN NUANCE for the mock: Save is a TAP-OFTEN action while follow/email are
SET-ONCE — burying Save fully behind a modal may add friction. Mock BOTH:
(a) everything in the modal, vs (b) Save stays a one-tap on the row while
notify/email/frequency consolidate. Ian picks from the pictures. Timing: lane
mocks this AFTER the desktop-flash fix + orange on-state ship (Ian's call 7/30).

Cross-lane: thread-follow owns the control + the topic_follow store (add a
`frequency` column). weekly-recap/digest owns the SENDING — hourly/daily/weekly
means batching follow-emails into digests per the user's choice, not one mail
per event. Coordinate on the board. Instant = the current per-event path;
Off = no email. Ship the modal first (de-cram), frequency second.

## 2026-07-30 — Front-end authoring for all post types via layout-v2, gated (Ian) ★ vision

Ian's direction, 7/30. Near-term ask: **add loothprints as a post type USERS can
post from the front end** (today discussions are the main user-postable type;
loothprints join them). Full vision: **author EVERY post type from the front end
through the layout-v2 block system** — the same block/forms UX, not wp-admin.

Gating model Ian wants:
- Most post types (video, article, event, sponsor-post, etc.) → **admin-gated**
  front-end forms.
- **Discussions and loothprints** → open to members (the un-gated, user-facing
  authoring path).
- **Prefer a USER WHITELIST over the WP `author` role** to decide who can post
  the gated types — explicit allow-list of trusted users, not a role grant.

Notes for whoever scopes this: the stack already has a "Frontend Admin" plugin
(seen in wp-admin sidebar) and layout-v2 owns the block model (_lg_layout_v2
meta; see the layoutv2-ian handoff — its backend add-block UX is itself being
fixed). This is a multi-phase build: (1) loothprints front-end form first, (2)
generalize the layout-v2 authoring surface to the front end per type, (3) the
whitelist-based gate. Big item — Ian rules the phasing before any build.

## 2026-07-30 — Discussion hub cards: PLAY a video inline like a video post (Ian) ★ wants this

Ian restated 7/30: "I want to be able to play a video in a discussion in the hub
card like a video post would have happen." A discussion whose body carries a
YouTube/Instagram/Facebook link should show that media EMBEDDED and PLAYABLE on
its hub card — the exact inline-play facade (thumb + play button → iframe on
click) video posts already get. Fine if the embed is driven by a link pasted in the discussion
posting modal (the composer already shows "paste a YouTube/Vimeo/Instagram link
on its own line to embed it" — so IN-BODY embed on the post page likely already
works; the gap is the HUB CARD).

What exists (verified in _feed.php:1048-1075): the card inline-play facade
(thumb + play button, iframe swapped in on click by forums.js) is built ONLY for
card_type='content' with content_kind IN ('video','shorty') — sourced from the
engine's stored yt_id (ACF youtube_link → v2 embed block). DISCUSSION cards
(card_type='topic') are excluded, and the yt_id extraction only runs for
video/shorty. So a discussion with a YT link in its body gets no card embed today.

Scope: (1) extract an embeddable id from a discussion's body/first-embed at index
or render time; (2) let the topic-card path carry the same facade; (3) IG/FB are
NOT youtube — they need their own oembed/id handling, more than the yt regex.
DISCUSSIONS ONLY per Ian. Verify the in-body-on-post-page case first and report
whether it already works, so the ask narrows to just the card.

## 2026-07-30 — PWA apps need a launch animation/message (Ian)

The installed PWA apps can take a while to fire up now; there's no feedback
during the cold start — it reads as a hang. Add a splash/loading animation or
"starting up…" message on launch (service-worker/app-shell boot). Applies to the
Add-to-Home-Screen PWA path (webroot/pwa.js, sw.js). Small, member-facing polish.

## 2026-07-30 — Front-page admin pencils: KEEP THE BUTTON (Ian) — CLOSED, no work

front-page-editor shipped the edit pencils behind an "Edit page" button, and
asked whether they should instead be always-on for admins (a one-line flip).
**Ian ruled 2026-07-30: keep the button.** The reason is the reason it was built
that way — editor code loads only on intent, so "visitors get zero editor bytes"
stays provable rather than asserted, and the page keeps its chrome. Recorded so
a later lane doesn't "improve" it into always-on in good faith. No work owed.

## 2026-07-29 — Reacting on a shorty fails on LIVE (Ian, screenshot w/ console)

On live, e.g. /shorty/dan-erlewine-stewmac-purfling-jig-mod/: tapping a
reaction emoji fails — console shows POST /archive-api/v0/card-react returning
**400**, twice, from inject_main.js. The react strip renders (lg-pf-react__opt
buttons) but the endpoint rejects the payload. Suspects: archive-poc's
card-react endpoint validating something live's page doesn't send (auth/token
shape, card id format for shorties), or shorty cards minting ids the endpoint
refuses. Verify on live read-only first; the fix likely lives in archive-poc
(web or api/v0/card-react). Member-visible on live — high priority next seat.

## 2026-07-29 — Group chat header: verbose member list + cramped text (Ian, screenshot)

Seen on Partners Chat (8 people): the panel header lists every member's full
display name inline — half the panel is names before the first message. Two
asks: (1) past a few participants, collapse the name roll to a count ("Group -
8 people") plus the MANAGE-MEMBERS button as the way to see/edit who's in the
chat — no verbose list; (2) the chat must respect the member's text size and
scale its container accordingly — at larger text it's awfully cramped (fixed
container + growing text). Candidate owner: next messages-surface lane (the
side-chat popout Ian references in that thread is already shipped; this is the
group-header + type-scale layer).

## 2026-07-29 — Profile social links go stale on posts/events (member report: Massimiliano Monterosso, 7/1 email)

Member removed the Linktree link from his profile, but it still shows on the
EVENTS he posted; and the FB link on his video POST differs from his current
profile. Shape of the defect: post/event surfaces carry a SNAPSHOT of the
author's social links taken at creation instead of reading current profile
truth at render — same drift class the mirror work fixed elsewhere. Fix
direction: bylines/social rows on posts + events render live from the profile
store (or re-sync on profile change via the new profile_update pipeline).
Audit which surfaces snapshot links (video posts, events, others?) and whether
a backfill is needed for existing content. Member-visible, member-reported —
prioritize into the next content-surface seat.

## 2026-07-29 — Stripe membership: back on the roadmap — AUDIT FIRST, then build (Ian)

Ian: "It's time to start building that out. We probably need to audit the whole
thing at this point." Audit scope before any build: the poller's Stripe half
(Tick→Sync→UserProvisioner — POLLER-ONBOARDING-AUDIT §3: an email-keyed latent
minter runs every 5 min for 3 live customers; if Stripe onboarding is coming it
must route through shared Patreon-id-aware identity matching, never email-only),
the lg-stripe-billing Slim companion app (cross-cutting handoff in
docs/SESSION-HANDOFF.md), the lg_membership schema on live (customers 4,
wp_user_bridge 3, entitlements 7, subscriptions 3; lg_role_sources 44 stripe
rows), the origin/stripe-poller-audit branch (dev2 Stripe sandbox-safety doc,
6/20), webhook + key state, and Patreon coexistence (the sweep's
payment_source=stripe skip). Deliverable: atlas audit + a build plan Ian can
rule on. CHARTER CANDIDATE: stripe-audit lane — next free seat.

## 2026-07-29 — Post header: title illegible over thumbnails that contain text (Ian, screenshot)

On post/video pages, the hero overlays the post title on the thumbnail. When the
thumbnail has its own baked-in text (e.g. AGBC video cards: "Offset soundhole,
modern guitar design" behind the title "Acoustic Guitar Builders Club: Offset
Soundhole…"), the two layers of text fight and the title goes hard to read.
Ian wants the header text more legible in that case. Candidate treatments (lane
decides, Ian picks from pictures): stronger scrim/gradient behind the title
block, blur/darken the image region under the text, or a busy-image detection
that switches to a solid band. Applies to the hub post hero; check video posts
especially (thumbnails almost always carry text).

## 2026-07-29 — Public weekly-email signup page (Ian)

A page where someone WITHOUT a WP account signs up for the weekly email. No WP
user may be required or created by signing up. Stack notes: the weekly digest and
its recipient rules are the weekly-digest-recap lane's domain (fold this into its
respawn charter — candidate owner); FluentCRM exists on the stack and live mail
rides FluentSMTP→SES; public-facing page → craft gates apply. Needs: the page,
a store for non-member subscribers, unsubscribe, and the digest sender reading
that store alongside members.

## 2026-07-29 — ~~Events mobile: banner cut off + time listed twice~~ → CLOSED by the events-mobile lane

RULING (Ian, later 7/29): on MOBILE, don't open the event modal/sheet at all —
clicking an event goes straight through to the event's post page. The ruling as
recorded added "the crop + duplicate-time fixes then apply to whatever surfaces
still use the sheet (desktop)" — **that clause resolves to nothing, and it matters
that it does: there is no desktop sheet.** `webroot/events-mobile.js` is the only
thing that ever built one and it hard-returns above `max-width:640px`; desktop has
navigated to the post page since Buck wrote it. The two other scripts `/pwa.js`
loads on `/events` without a mobile gate (`events-live.js`, `loothalong.js`) carry
no modal, no `preventDefault`, no card interception. So the ruling makes mobile
match desktop, and nothing is left unfixed on desktop.

Original report: on the mobile event sheet ("Looth Pro — Intermediate Inlay with
CNC", live 72363): (1) the banner was cut off — the AUG-2 chip sat on the artwork
and the bottom crop ate the "August 2nd, 3PM" typeset into the image;
(2) "Sunday, August 2, 2026 · 3:00 PM ET" rendered TWICE, under the title and
again below the PRO chip.

**Both diagnosed, then closed by the ruling itself** — branch `events-mobile`,
which retires the sheet and so deletes the surface both defects lived on. Verified
they do not migrate to the destination: under 768px `post-header/shell.css` runs
the hero at `height:auto; object-fit:unset` (uncropped), and the event header
states the date once. Causes, for the record: `.lev-cover` pinned `height:170px`
around a 16:9 poster (81 image px off top and bottom, 22.6% of the height), and
the description selector led with `.lg-event-header__detail`, whose only `<p>` is
the byte-identical date line — so it also meant the real blurb never rendered at
all. Ian's ruling is now gated (`tools/gates/events-tap-navigates-gate.sh`, GATE
7/7) because it is encoded in an absence and a future lane would undo it in good
faith.

**One residual — RULED 2026-07-30, Ian: the EVENT POST PAGE.** "Add to calendar"
is gone from the mobile events flow; it lived only in the sheet, and the event
post page has no calendar affordance. Ian chose the event post page over the
landing card, because that is where every tap now lands, so one build serves
mobile and desktop alike and it adds no chrome back to the landing surface he
just had stripped. Scoped as a small work item, not its own lane. Not started.

**Two findings handed to the post-header item below** ("title illegible over
thumbnails that contain text") rather than filed separately, since they are the
same hero on the same posters:
1. That item's fix is **already solved on mobile, in-tree** — under 768px the
   shell drops the overlay entirely (`object-fit:unset`, scrim hidden) and puts
   the title on a cream ground BELOW the photo. Its comment says why: "the
   overlay-on-photo treatment was unreadable on narrow viewports (title + chip
   collided over a busy image)". One of that item's own candidate treatments — a
   solid band — is what mobile already does, so the desktop question is really
   "adopt the mobile answer, or scrim harder".
2. Separately, `.lg-post-header__photo` is a **raw one-size upload**: no
   `/img.php?w=`, no `srcset`, no width/height, `loading="eager"
   fetchpriority="high"`. 263KB of poster at full size on a phone, and the missing
   dimensions reflow the page on load. Breaks the standing image rule in
   CLAUDE.md, and Ian's ruling just put it on the mobile critical path.
   `post-header` is used by EVERY post, so whoever takes the legibility item
   should take this too.

   **RULED 2026-07-30, Ian: its OWN LANE, spins at the next free memory slot.**
   Not folded into events-mobile (that branch is approved and merge-ready, and
   this is cross-cutting work on a block every post renders) and not deferred —
   the mobile-critical-path argument carried. Charter still to be written.

## 2026-07-29 — Mirror dispatch must be durable → CHARTERED: mirror-dispatch lane

Ian approved 7/29 (spins after merger night). Charter: ~/lane-prompts/mirror-dispatch.md.
Outbox queue + reconcile reverse pass + WP-native delete hook; proves §9's hop.
Root cause: docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md §10.

## 2026-07-29 — Mail containment must gate on host, not LG_ENV (keeper)

`platform/mu-plugins/lg-dev-mail-containment.php` treats `LG_ENV=dev2` as proof
of a dev box — but live IS `LG_ENV=dev2` by design (prod-at-cut layout;
bb-mirror/config.php:38-44 documents the decoupling). Dormant today only because
the plugin is not in live's curated mu-plugin symlink set. Fix: require the
public-host check too (`LG_PUBLIC_HOST`/request host ≠ loothgroup.com) before
containing anything, so an accidental future symlink on live stays a no-op.
Same review should sweep any other tool using LG_ENV as a dev/live SAFETY key
rather than a LAYOUT key.

## 2026-07-29 — Edit post must equal Add post (Ian)

The edit-post modal today offers only title, body text, and image removal. It
must be fully functioning like the original add-post modal: pick forum, add
tags, and everything else the composer offers. Ian's stated preference: **reuse
the add-post mechanic completely** rather than maintaining a second, lesser
modal — open the same composer pre-filled with the post being edited.

Acceptance sketch: editing a post exposes every control that creating one does
(forum picker, tags, formatting, images/embeds), through the same code path as
add-post; saving preserves anything edit doesn't touch (replies, reactions,
timestamps beyond modified). Unowned — needs a lane.

**Status 2026-07-30 — BUILT AND PROVEN; the API half is already merged to main
(7825a27, Ian "looks good"). Remaining delta is two JS files on branch
`edit-post-parity`. Awaiting keeper's dev2-serve verification, then merge.**

Ian's rulings that shaped it, both binding:
- **The desktop/mobile split STAYS.** Edit reuses the composer CREATE uses *on the
  same viewport* — desktop edit opens the 4-step wizard on Write, mobile edit opens
  the same flat form mobile create opens. Parity is per-viewport, not one composer
  everywhere. Proven through the real controls, not the seams:
  `tools/edit-post-parity/create-edit-parity.py`, 39/39, both viewports.
- **Per-topic drafts STAY (2026-07-30).** An unfinished reply comes back when you
  return to that topic. This is NOT the stale-composer bug that was fixed alongside
  it — see COMPOSER-V2-PLAN §1.2 for the table of which is which, and do not
  "fix" the draft restore.

Saving verified in the DB rather than the UI, across a real forum round trip
(3837 → 3823 → 3837): replies, reactions, the activity row, `post_date`, author,
counts and every reply row held; the move carried both replies' `_bbp_forum_id`,
both forums' counters and the postgres mirror with it.
