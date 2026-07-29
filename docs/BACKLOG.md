# Backlog

Items Ian has asked for that no lane owns yet. Newest on top. When a lane picks
one up, move the line into that lane's charter and note the lane name here.

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
