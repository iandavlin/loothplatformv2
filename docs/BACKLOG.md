# Backlog

Items Ian has asked for that no lane owns yet. Newest on top. When a lane picks
one up, move the line into that lane's charter and note the lane name here.

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
