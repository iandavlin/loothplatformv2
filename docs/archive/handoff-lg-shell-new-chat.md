# Handoff — lg-shell lane (fresh chat boot)

The previous lg-shell chat malfunctioned. The **code is intact** (live header +
footer + jwt-verify all pass `php -l`), so you're inheriting a clean tree — just
resume the open items below. **Ian drives and supervises**; work inline where he can
see and stop you. Keep this chat small and focused (don't spin up autonomous turns).

Full prior context: `lg-shell/SESSION-HANDOFF.md` (P3, 2026-05-28) +
`docs/briefing-lg-shell.md` (charter). This doc is the delta + current state.

## What lg-shell owns
The shared visual shell + modal layer:
- **Shared header/footer partial** (P3, SHIPPED) — `lg_shared_render_site_header($ctx)`
- **Modal layer** (P1/P9, NOT built) — notification bell + popover, message icon +
  popover, friends/messages/notifications modals (tied to the profile-app social backend)
- **Auth reskin** of `/wp-login.php` (P2)
- **Canonical design tokens**

## ⚠️ Where the code actually lives (read this)
The live, deployed lane code is in **`/srv/lg-shared/`** — `site-header.php`,
`site-footer.php`, `site-header.css`, `jwt-verify.php` — all **`www-data:www-data`**
owned and served at `/lg-shared/`. **These files are NOT in the git repo.** The
git-tracked `lg-shell/` project dir only holds `SESSION-HANDOFF.md`, `handoffs/`, and
`nginx-snippet.conf`. So there's no version control on the primary artifacts — edit
carefully, and consider mirroring changes into `lg-shell/` if you want history.

To edit `/srv/lg-shared/*`: `sudo chown ubuntu:ubuntu <file>`, edit, then
`sudo chown www-data:www-data <file>` back. (FPM/nginx read them as www-data.)

## Consumers depend on your contract
`lg_shared_render_site_header($ctx)` is called by **archive-poc** (`web/_chrome.php`)
and **bb-mirror** (`web/_chrome.php`). Don't change the `$ctx` signature without
relaying to both lanes via Ian. Full `$ctx` table is in `lg-shell/SESSION-HANDOFF.md`.
Test surfaces: archive-poc `/front-page/`, bb-mirror `/forum/` (both gated — use the
`loothdev_auth` cookie = `$loothdev_token` from `dev.loothgroup.com.conf`).

## State + open items
**DONE:** P3 shared header shipped + wired into archive-poc + bb-mirror. The header
avatar-menu **"Edit Profile" → `/profile/edit`** self-link is fixed (default was the
broken `/members/me/` → hijacked to `/u/me` → 404; see
`docs/reply-to-shell-u-me-self-link.md` for the full diagnosis).

**OPEN:**
1. **Sibling self-links still on the `me` convention** — `site-header.php:85-86`,
   `msg_url`/`notif_url` default to `/members/me/messages/` + `/members/me/notifications/`.
   Resolve the same way (point at real routes, or whatever the messaging/notif UI lands on).
2. **Modal layer (P1/P9) — not built.** Notification bell + message icon popovers and
   the friends/messages/notifications modals. These consume the profile-app **social
   backend** (Notifications.php / me-notifications.php + connections) — coordinate the
   contract with that lane via Ian before building UI against it.
3. **Auth reskin** (`/wp-login.php`, P2) and **design-token canonicalization** — backlog.

## Coordination
The strangler **coordinator chat** holds the contract + handoff
(`docs/STRANGLER-SESSION-HANDOFF.md`). Route cross-cutting changes (the `$ctx`
contract, social-backend shape) through Ian. Lane roster: `docs/CHATS-MENU.md`.
