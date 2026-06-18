# Feature briefing — Anonymous posting, rebuilt in the new stack (2026-06-07)

**Goal (Ian):** users can toggle a forum post (topic/reply) **anonymous or not**. Anonymous posts render as
**"Anonymous"** to everyone; **admins/mods see the real author**. Controlled from the **profile** (a default)
and/or **per-post** in the composer. Built in the new stack (Hub + profile-app) — **retire the legacy
FluentForm/snippet hack** (`lg-snippets` #93/95/96 + the Form-38 author #1517 path).

This is a cross-lane feature. Coordinator holds the **anon-flag contract**; lanes own their slice below.

## The 5 pieces

### 1. Per-post toggle — composer (hub-coord desktop · Buck mobile)
A **"Post anonymously"** checkbox in the Hub composer (`#ntm-form`), defaulting to the user's profile
setting (piece 5). Sends the flag with the topic/reply write.

### 2. Store the anon flag at write (hub-coord — `bb-mirror/api`)
Forum writes still ride bbPress (`reply.php` → `buddyboss/v1/reply`). So at write:
- set post meta on the bbPress topic/reply, e.g. **`_lg_anon = 1`**.
- the bb-mirror **`_sync.php`** carries it into the `forums` schema — add an **`is_anon BOOLEAN`** column on
  `forums.topic` + `forums.reply`. The Hub renders from PG, so the flag must land there.
- (When the native write-path replaces the bbPress door later, it sets `is_anon` directly — same column.)

### 3. Anon render — mask the author (hub-coord — `_feed.php` / `_reply-render.php` / `_topic-replies.php`)
When `is_anon` AND the viewer is **not** an admin/mod:
- render **"Anonymous"** + a generic avatar, **no profile link**.
- 🔴 **LEAK-SAFE (the bar = "secure from the inspector"):** the real author's **name, avatar URL,
  `user_uuid`, and `/u/<slug>` link must be ABSENT from the DOM/JSON** for non-admin viewers — masked
  **server-side**, not CSS-hidden. Same discipline as the gated-teaser model. The author identity still
  comes from profile-app ([[project_profile_app_identity_source]]); the mask suppresses it before render.

### 4. Admin / mod reveal (hub-coord — same render)
When the viewer **is** admin/mod (`current_user_can('moderate_comments')` / `manage_options`, server-side —
same authz pattern as comment-delete): render the **real author** + a small **"(posted anonymously)"**
marker. Replaces the legacy "reveal in BB edit" snippet (#96).

### 5. Profile default setting — Phase 2 (profile-app + profile-page lane)
A per-user **"post my forum posts anonymously by default"** preference in profile-app (the user record /
preferences), exposed on the profile/settings page. The composer reads it to pre-check the toggle. Per-post
choice always overrides. (Phase 1 can ship with the toggle defaulting to off; this adds the default.)

## Contract (coordinator-governed)
- **Flag field:** `_lg_anon` (WP meta) → `is_anon` (forums.topic/reply). One name, both lanes.
- **Reveal authz:** admin/mod only, **server-enforced** (never trust client).
- **Masking is server-side absence**, leak-safe — announce the masking rule to Buck (mobile render mustn't
  re-expose identity) and the SURFACE lane.

## Scope / phasing
- **Phase 1 (hub-coord + Buck):** per-post toggle → store flag → anon render (leak-safe) → admin reveal.
  Ships the whole user-visible feature.
- **Phase 2 (profile-app):** the profile default setting.
- **Then retire:** the legacy anon snippets (`lg-snippets` #93/95/96) + the Form-38 anon path, once Phase 1
  is dev-proven. Leave them folded/working until the rebuild lands (they're the current behavior + fallback).

## Report back (to coordinator)
`DONE · FILES · VERIFIED (toggle + flag persists + anon masks leak-safe @ anon AND non-admin + admin reveal) · NEEDS-OTHER-LANE · BLOCKED`.
Report session ID + outliner title for CHATS-MENU + lineage.
