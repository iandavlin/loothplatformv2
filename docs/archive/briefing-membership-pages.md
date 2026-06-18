# Coordinator → poller: next task — membership pages onto the shared shell

This is poller-lane work — you own `Shortcodes.php` + `Pages.php` and drove
these pages through the test-checklist. Read this, then the pointers.

> If this chat's context is getting full, say so — coordinator will rotate you
> with a handoff carrying this task rather than cram it in.

## The task in one sentence

Get the Stripe/membership WP pages (your plugin's auto-seeded pages) rendering
on the **unified `/srv/lg-shared/` header** instead of BuddyBoss theme chrome —
testable on dev, ready to ride the blue-green cutover.

## What these pages are

The `lg-patreon-stripe-poller` plugin auto-seeds ~15 WP pages, each wrapping
`[lg_member_nav][shortcode]`. They render today inside the **BuddyBoss theme**.
All return 200 on dev. The pages:

| Page | Slug | Shortcode |
|---|---|---|
| Join (Stripe checkout) | `/lgjoin/` | `lg_join` |
| Gift Memberships | `/lggift-buy/` | `lg_gift` |
| Redeem a Gift | `/lggift/` | `lg_redeem_gift` |
| Manage Subscription | `/manage-subscription/` | `lg_manage_subscription` |
| My Gifts | `/my-gifts/` | `lg_my_gifts` |
| Request a Refund | `/request-refund/` | `lg_refund_request` |
| Membership Guide | `/membership-guide/` | `lg_membership_guide` |
| Affiliate Earnings | `/affiliate-earnings/` | `lg_affiliate_portal` |
| Subscription success / regional-fail / elder-bio / events | various | — |

Source: `lg-patreon-stripe-poller/src/Wp/Shortcodes.php` (388KB — the render
logic) + `src/Wp/Pages.php` (the auto-seeder; PAGES registry maps shortcode →
page metadata, `ensureAll()` creates them on plugin activation).

## The header you're integrating with (do NOT modify it — you consume it)

`/srv/lg-shared/site-header.php` exposes one function:

```php
require_once '/srv/lg-shared/site-header.php';
lg_shared_render_site_header([
    'authenticated' => true,
    'tier'          => 'pro',          // 'public' | 'lite' | 'pro'
    'display_name'  => 'evan-gluck',
    'avatar_url'    => 'https://…/bpfull.jpg',   // optional
    'capabilities'  => [ 'manage_options' => false, 'edit_archive_poc' => false ],
    'msg_unread'    => 0,    // optional; null → lazy-load via REST
    'notif_unread'  => 0,    // optional; null → lazy-load via REST
]);
```

CSS: `<link rel="stylesheet" href="/lg-shared/site-header.css">` (nginx maps
`/lg-shared/` → `/srv/lg-shared/`). Footer: `/srv/lg-shared/site-footer.php`.

The partial is intentionally dumb — it renders what it's handed. **lg-shell
owns it.** If you need a header change, that routes through lg-shell via
coordinator, not a direct edit.

## The mechanism (suggested, you decide)

These are WP pages, not standalone PHP apps. The clean path is a small
**mu-plugin** that hooks `template_include` for the membership page slugs (or
pages whose content contains the membership shortcodes) and swaps in a custom
template that renders:

```
lg_shared_render_site_header($viewer)  →  the_content()  →  site-footer.php
```

…bypassing the BuddyBoss theme entirely. This travels with code (same
philosophy as Pages.php) and works on the new box at cutover. Confirm the
approach yourself — `template_include` filter vs. page-template file vs.
theme-level swap.

## Viewer state — where the header's array comes from

Profile-app's **WP-session auth bridge is live**, so a server-side call to
`/wp-json/looth/v1/whoami` returns the exact array shape for a logged-in WP
user. Two gotchas if you call it from PHP/FPM:
- **`CURLOPT_HTTP_VERSION = CURL_HTTP_VERSION_1_1` + `CURLOPT_TIMEOUT = 5`** —
  HTTP/2 ALPN handshake times out from a fresh FPM worker (profile-app hit this).
- Forward the caller's cookies, or use the trusted-header path.

Alternative: build the viewer array directly from `wp_get_current_user()` +
role→tier mapping (looth1→public, looth2→lite, looth3→pro, looth4→pro). Pick
whichever is simpler; `/whoami` keeps you consistent with every other surface.

## Open decisions — surface to coordinator, don't guess

1. **Fate of `[lg_member_nav]`.** The pages currently wrap content in a
   membership sub-nav. With the unified header on top, does `lg_member_nav`
   stay as a secondary nav strip, or fold into the shared header? Design call
   — bring it to coordinator/Ian.
2. **Dormant-Stripe behavior.** At cutover Stripe ships dormant (B-now/A-later,
   coord §3h). `/lgjoin/` checkout won't transact until Stripe creds land
   later. Decide per-page: which render as informational vs. which show a
   "coming soon" state. Not blocking the header work.

## Coordination map

- **lg-shell** (`1d248347`) — owns `/srv/lg-shared/`. Header changes route
  through coordinator. You're a consumer — if the shared header needs a tweak
  to host your pages, ask; don't edit it.
- **cutover** (`c4e655f8`) — your mu-plugin + any nginx must be cutover-ready
  (works on the fresh EC2). Note the BuddyBoss public-content allowlist
  dependency in Pages.php — that mechanism changes when BB theme goes.
- Shortcode markup is yours — no cross-chat boundary there (that's why this is
  your task, not a separate chat).

## Critical constraints

- **Live is Claude-free.** Build + test on dev. Coordinator + Ian handle the
  new-box build and DNS swing.
- **Cookie gate:** dev pages need the `loothdev_auth` cookie. Claim via
  `/claim?t=<token>` (token in `dev.loothgroup.com.conf`).

## Read first

1. This file
2. `/srv/lg-shared/site-header.php` — the contract (read the docblock + the function)
3. `/home/ubuntu/projects/bb-mirror/web/_chrome.php` — reference integration (lines ~325, ~410)
4. `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Pages.php` — the page registry
5. `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md` §2 (`/whoami`), §3h (dormant Stripe), §4 (cutover model — blue-green)
6. `/home/ubuntu/projects/docs/CHATS-MENU.md` — peers

## First moves

1. Pick one page (`/membership-guide/` — pure informational, no Stripe) and
   get it rendering on the shared header end-to-end as the proof of concept.
2. Confirm the viewer-state source works (whoami shim vs. direct).
3. Generalize the mechanism to all membership slugs.
4. Report back the PoC + the `lg_member_nav` decision before doing all 15.

## Report-back format

```
**membership-pages → coordinator:** <one-line status>

<absolute path to your SESSION-HANDOFF.md>
```

— coordinator
