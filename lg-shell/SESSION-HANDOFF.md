# lg-shell — Session Handoff (2026-05-28, P3 shipped)

> ⚠️ **SNAPSHOT — verify every open/queued to-do against `git log` before working it (flagged 2026-06-15).** Items marked open/TODO/next here may already be shipped or ruled out — e.g. the **Follow** modal was DROPPED (`ff23ba4`, connections are mutual-only). Source of truth = `git log` + `tools/gates/run-all.sh`, not these bullets.

> Prior stub: `handoffs/2026-05-28-scaffold.md` (first-session scaffold, no code yet).
> This handoff covers P3: shared header partial landed + wired into archive-poc and bb-mirror.

## What this project is

The shared visual shell + modal layer. Owns:

- **Shared header partial** (P3, DONE — see below)
- Notification bell + popover, message icon + popover (P1, next)
- Modal layer: notifications, friends, follow, messages, photos (P1/P2)
- Auth reskin (`/wp-login.php`) (P2)
- Canonical design tokens

Charter: `/home/ubuntu/projects/docs/briefing-lg-shell.md`
Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
BB decommission picture: `/home/ubuntu/projects/docs/BB-DECOMMISSION-INVENTORY.md`

## P3 — shared header partial — SHIPPED 2026-05-28

### What was built

**`/srv/lg-shared/`** — new shared service directory.

| File | Purpose |
|---|---|
| `site-header.php` | Canonical header partial. `lg_shared_render_site_header(array $ctx)` |
| `site-footer.php` | Canonical footer partial. `lg_shared_render_site_footer(array $ctx)` |
| `site-header.css` | Design tokens + all `.lg-chrome*` + footer rules. Served at `/lg-shared/site-header.css` |

**`/etc/nginx/snippets/lg-shared.conf`** — nginx location for `/lg-shared/` static assets (CSS/JS). Included in `dev.loothgroup.com.conf` alongside the other strangler snippets.

**`nginx-snippet.conf`** in this project — source-of-truth copy of the above (per §3g pattern).

**archive-poc `web/_chrome.php`** — rewritten as thin adapter. Computes viewer-state from `lg_archive_poc_whoami()` + `$GLOBALS['LG_VIEWER_TIER']`, passes `$ctx` struct to `lg_shared_render_site_header()`. Keeps `search_id='chrome-q'` (archive.js listens for it) and the archive-poc `before_nav` back-link.

**bb-mirror `web/_chrome.php`** — replaced placeholder header. Now calls `lg_shared_render_site_header()` with viewer state from `lg_bb_mirror_whoami()`. Links `/lg-shared/site-header.css` from `<head>`.

**bb-mirror `config.php`** — added `lg_bb_mirror_whoami()` function (same loopback curl pattern as archive-poc, HTTP/1.1, 5s timeout, `tier_unavailable→public` fallback).

### Include API

```php
require_once '/srv/lg-shared/site-header.php';
lg_shared_render_site_header([
    'authenticated'      => true,
    'tier'               => 'pro',          // 'public'|'lite'|'pro'
    'display_name'       => 'evan-gluck',
    'avatar_url'         => 'https://…/bpfull.jpg',   // optional
    'capabilities'       => [
        'manage_options'   => false,
        'edit_archive_poc' => false,
    ],
    'msg_unread'         => null,           // null = lazy-load via REST; 0 = badge hidden
    'notif_unread'       => null,
    'logo_url'           => 'https://…/logo.png',     // optional
    'search_id'          => 'my-search-input',         // optional; default 'lg-chrome-q'
    'search_placeholder' => 'Search…',                 // optional
    'profile_url'        => '/members/me/',            // optional
    'before_nav'         => '<a class="…">…</a>',     // optional raw HTML between logo and nav
]);
```

Footer:
```php
require_once '/srv/lg-shared/site-footer.php';
lg_shared_render_site_footer([
    'logo_url' => 'https://…/logo.png',   // optional
]);
```

CSS link (in `<head>`):
```html
<link rel="stylesheet" href="/lg-shared/site-header.css">
```
Note: archive-poc uses its own `archive.css` which already contains the `.lg-chrome` rules — it does NOT need the extra link (no regression). bb-mirror links it explicitly.

### File ownership + permissions

```
/srv/lg-shared/               www-data:www-data 755
  site-header.php             www-data:www-data 644  + ACL r-- for archive-poc, bb-mirror
  site-footer.php             www-data:www-data 644  + ACL r-- for archive-poc, bb-mirror
  site-header.css             www-data:www-data 644  + ACL r-- for archive-poc, bb-mirror
```

PHP files are read server-side via `require_once` — not served via HTTP (nginx returns 403 on .php requests under /lg-shared/).

### Verified

```
archive-poc  HTTP 200 — <header class="lg-chrome" id="site-header"> present
bb-mirror    HTTP 200 — /lg-shared/site-header.css linked, lg-chrome__wordmark present
/lg-shared/site-header.css  HTTP 200
placeholder banner ⚠️ is GONE from bb-mirror
```

### Design decisions carried forward from archive-poc mockup

- Tier pill inside account cluster: sage **Lite**, amber **Pro**, charcoal **Admin**
- Anonymous CTA: "Join" → `/lgjoin/` (no free tier)
- Admin Edit button → `/wp-admin/` new tab, gated on `manage_options`
- Avatar: `<img>` when `avatar_url` set, initials fallback otherwise
- `msg_unread` / `notif_unread` = null → badge hidden, lazy-loaded by consumer JS

## Files

```
/srv/lg-shared/site-header.php
/srv/lg-shared/site-footer.php
/srv/lg-shared/site-header.css
/etc/nginx/snippets/lg-shared.conf
/home/ubuntu/projects/lg-shell/nginx-snippet.conf
/home/ubuntu/projects/archive-poc/web/_chrome.php
/home/ubuntu/projects/bb-mirror/web/_chrome.php
/home/ubuntu/projects/bb-mirror/config.php     (added lg_bb_mirror_whoami)
```

## Critical constraint

Live is Claude-free. Coordinator + Ian handle live deploys.

## What's next for lg-shell

P1 items per briefing priority table:

1. **Notification bell REST + popover** — `GET /api/v0/notifications`, `POST /:id/read`
   - Reads from `wp_bp_notifications` directly (pure proxy first — no postgres cache needed yet)
   - Popover JS driven by the existing `data-lg-notif-link` + `data-lg-notif-count` hooks in the shared header
   - 31k unread / 269 last-30d active — real P1

2. **Friends modal** — `GET /profile-api/…/friends` or direct `wp_bp_friends` read
   - 7346 confirmed friendships

3. **Follow modal** — `GET /profile-api/…/following`
   - 9002 follow relationships

4. **Messages inbox** (P2) — verify live usage first before full build

**P9 modals** (from the briefing's "P9" reference in the coordinator message):
Looking at `BB-DECOMMISSION-INVENTORY.md`, the items listed as P8-P11 are:
- P8: lg-bp-mirror modals (messages + notifications) live on dev — this IS lg-shell's work
- P9: Group landing + directory composed surfaces — archive-poc's work
- P10: BP friends/photos/docs/videos kill list verified
- P11: Auth pages reskinned

So P8 === lg-shell notification + message modals. P9 is archive-poc's. This session completed the prerequisite (shared header = P3 in the cutover sequence).

## Next-session opening move

1. Read this handoff + `docs/STRANGLER-COORDINATION.md` §2 + `docs/BB-DECOMMISSION-INVENTORY.md` §Modal candidates.
2. Read the coordinator's `docs/CHATS-MENU.md` for current workstream status.
3. Start the notification bell/popover REST endpoint. Decision: pure proxy (read `wp_bp_notifications` via a WP-booted `_sync`-style endpoint, no local postgres needed for v1).
4. Wire up the `data-lg-notif-count` badge that's already in the shared header.

## Pointers

- Shared partial: `/srv/lg-shared/`
- nginx snippet deployed: `/etc/nginx/snippets/lg-shared.conf`
- nginx snippet source: `/home/ubuntu/projects/lg-shell/nginx-snippet.conf`
- Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
- Charter: `/home/ubuntu/projects/docs/briefing-lg-shell.md`
- BB decommission: `/home/ubuntu/projects/docs/BB-DECOMMISSION-INVENTORY.md`
- Prior stub: `handoffs/2026-05-28-scaffold.md`
