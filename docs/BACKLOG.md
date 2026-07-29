# Backlog

Items Ian has asked for that no lane owns yet. Newest on top. When a lane picks
one up, move the line into that lane's charter and note the lane name here.

## 2026-07-29 — ~~Events mobile: banner cut off + time listed twice~~ → CLOSED by the events-mobile lane

Ian, screenshot, on the mobile event sheet ("Looth Pro — Intermediate Inlay with
CNC", live 72363): (1) the banner was cut off — the AUG-2 chip sat on the artwork
and the bottom crop ate the "August 2nd, 3PM" typeset into the image;
(2) "Sunday, August 2, 2026 · 3:00 PM ET" rendered TWICE, under the title and
again below the PRO chip.

**Both diagnosed, then resolved by Ian's own follow-up ruling** (keeper-signed
2026-07-29): *on mobile, stop opening the modal entirely — a tap goes straight to
the event's post page.* Branch `events-mobile`. Retiring the sheet deletes the
surface both defects lived on, and there was never a desktop sheet to keep them
in — `webroot/events-mobile.js` always self-gated to `max-width:640px` and desktop
has navigated to the post page since Buck built it. The destination has neither
defect: under 768px `post-header/shell.css` runs the hero at
`height:auto; object-fit:unset` (uncropped), and the event header states the date
once. Causes, for the record: `.lev-cover` pinned `height:170px` around a 16:9
poster (81 image px off top and bottom, 22.6% of the height), and the description
selector led with `.lg-event-header__detail`, whose only `<p>` is the byte-identical
date line — so it also meant the real blurb never rendered at all.

**Two residuals this created, both needing an Ian call — genuinely unowned:**
1. **"Add to calendar" is gone from the mobile events flow.** It lived only in the
   sheet; the event post page has no calendar affordance. Natural homes: the event
   post page, or the landing card.
2. **`.lg-post-header__photo` is a raw one-size upload** — no `/img.php?w=`, no
   `srcset`, no width/height, `loading="eager" fetchpriority="high"`. 263KB of
   poster at full size on a phone, and the missing dimensions reflow the page on
   load. Breaks the standing image rule in CLAUDE.md, and it is now on the mobile
   critical path. `post-header` is used by EVERY post, so this wants its own lane.

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
