# Backlog

Items Ian has asked for that no lane owns yet. Newest on top. When a lane picks
one up, move the line into that lane's charter and note the lane name here.

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
