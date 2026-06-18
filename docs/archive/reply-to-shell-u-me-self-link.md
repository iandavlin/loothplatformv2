# → lg-shell: the `/u/me` "not found" is the header self-link

Ian clicked the header avatar-menu "Edit Profile" link and landed on `/u/me` →
bare **"not found"**. Diagnosis (so you don't re-derive it):

## Chain
- `site-header.php:82` defaults `profile_url` to **`/members/me/`** (the `me`
  convention = "current user").
- It's emitted at `:244` as the **"Edit Profile"** menu item. The same `me`
  convention is also hardcoded at `:182` (`/members/me/messages/`) and `:198`
  (`/members/me/notifications/`).
- nginx hijack rewrites `^/members/<name>/ → 302 /u/<name>`, so `/members/me/`
  → `/u/me`.
- profile-app `u.php` does `SELECT … WHERE slug='me'`, finds no such user (and
  `me` isn't numeric) → **404 "not found."** There is no `me`→current-user
  resolution on the profile-app side.

## Scope (it is NOT every link)
Every *other-user* link uses the real slug and is fine (`directory-members.php`,
`_render_practice.php`, `_chrome.php` all emit `/u/<slug>`). The only breakage is
the **`me` self-link convention** — 3 spots, all in the shared header.

## Your call (header-side fix, no backend change needed)
The header already has whoami (each adapter computes viewer state incl. `slug`).
Cleanest options:
- **"Edit Profile" item** → point at **`/profile/edit`** directly (the link literally
  says Edit Profile; the editor resolves identity via its own WP-session→mint hop).
- **A public self-view** (if you want one) → emit **`/u/<whoami-slug>`** from whoami
  instead of the `/members/me/` default. (Self-view of `/u/<own-slug>` already
  bounces to `/profile/edit` anyway.)
- **messages / notifications** (`:182`, `:198`) → same `/members/me/` issue; resolve
  from whoami or repoint as you settle the messaging UI.

If instead you'd rather profile-app learn the `me` convention (so `/u/me` resolves
server-side for any caller), say so and I'll do that in `u.php` — but the header-side
fix needs nothing from us.

## Paths
```
/srv/lg-shared/site-header.php
/home/ubuntu/projects/profile-app/web/u.php
/etc/nginx/snippets/strangler-profile-app.conf
```

— coordinator
