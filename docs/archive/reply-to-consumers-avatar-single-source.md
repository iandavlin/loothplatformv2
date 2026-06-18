# Coordinator → archive-poc · lg-layout-v2 · lg-shell: avatar = single source

Ian locked a platform-wide contract (2026-05-29): **one avatar per user,
identical on every surface, editable in exactly one place.** Source of truth is
the **profile spine** (`users.avatar_url`, profile-app — NOT WordPress/BuddyBoss,
NOT Gravatar). profile-app stores + serves a canonical, stable, **versioned**
per-`user_uuid` URL. Full contract: `STRANGLER-COORDINATION.md` → "Avatar /
author-identity — SINGLE SOURCE." Per-lane:

## archive-poc — author banner
Resolve the **author** avatar via the batch users lookup
(`GET /profile-api/v0/users?uuids=`, already returns `avatar_url`). Render the
image on the author banner; **initials circle** when empty. Don't snapshot the
image bytes — store the author `user_uuid` + resolve the (versioned) URL. You
already key identity off `user_uuid`, so this is one render swap + the lookup.

## lg-layout-v2 — post author-header + author-footer bylines
The post **author-header** and **author-footer** must show the post author's
avatar from the SAME source — resolve via the batch lookup keyed on the post's
author `user_uuid` (not WP `get_avatar`, not Gravatar). Image + initials fallback.
This is the content-side consumer of the identity card; the avatar there must
match what the forum/header/archive show for the same person.
(Routing note: this lands with whoever owns lg-layout-v2 author-byline render —
coordinator to route to the content/layoutv2 lane when active.)

## lg-shell — shared header (current viewer)
Already reads `avatar_url` from `/whoami` ✔. Two confirms: (a) **initials-circle
fallback** when `avatar_url` is empty (don't render a broken img), (b) once
profile-app serves the versioned URL, just pass it through — no Gravatar default.

## Common rules
- **Read, don't copy:** reference the user (`user_uuid`); resolve the URL via
  `/whoami` (viewer) or the batch lookup (authors). The versioned URL +
  identity-purge fan-out keeps everyone current when a user edits their picture.
- **Initials circle = universal empty-state.**
- **Pre-cut dependency:** profile-app must ship the avatar store + versioned URL +
  batch-lookup `avatar_url` first. Until then, current behavior can stay — just
  don't entrench Gravatar/BuddyBoss as the source.
- §0: repo copy → deploy → commit by pathspec → push.

— coordinator
