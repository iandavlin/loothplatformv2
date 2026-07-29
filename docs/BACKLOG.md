# Backlog

Items Ian has asked for that no lane owns yet. Newest on top. When a lane picks
one up, move the line into that lane's charter and note the lane name here.

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

## 2026-07-29 — Events mobile: banner cut off + time listed twice (Ian, screenshot)

RULING ADDED (Ian, later 7/29): on MOBILE, don't open the event modal/sheet at
all — clicking an event goes straight through to the event's post page. The
crop + duplicate-time fixes then apply to whatever surfaces still use the sheet
(desktop).

On the mobile event sheet (seen on "Looth Pro — Intermediate Inlay with CNC"):
(1) the banner image is cut off — the AUG-2 date chip overlaps the artwork and
the bottom of the banner crops the "August 2nd, 3PM" text baked into the image;
(2) the time line "Sunday, August 2, 2026 · 3:00 PM ET" renders TWICE — once
under the title, again below the PRO tier chip. Exactly one should remain.
Where: mobile event detail sheet/modal. Unowned — fold into the next events
lane charter (events-fix died in the 7/29 reboot; its branches are still
unmerged).

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
