# membership-pages — standalone surface

Per coord §0b launch invariant: the auto-seeded membership pages from the
`lg-patreon-stripe-poller` plugin move from WP-templated rendering to
**standalone PHP** served on a dedicated FPM pool with NO WP boot per request.

Pattern lifted from:
- [`/home/ubuntu/projects/events/`](../events/) — closest sibling (reads
  wp_options + `event` CPT via direct PDO)
- [`/home/ubuntu/projects/archive-poc/`](../archive-poc/)
- [`/home/ubuntu/projects/bb-mirror/`](../bb-mirror/)

## Layout

```
membership-pages/
├── README.md                ← this file
├── config.php               ← env detection + DB connection + esc helper
├── lib/
│   ├── whoami.php           ← cached /whoami loopback + §0a-compliant ctx builder
│   └── guide-data.php       ← read-only loader for lgms_guide_* wp_options
├── web/
│   ├── membership-guide.php ← /membership-guide/ front controller (PoC)
│   └── membership-guide.css ← lightweight styles for the PoC layout
└── nginx-snippet.conf       ← /etc/nginx/snippets/strangler-membership-pages.conf
```

## Surfaces

| Slug | File | Status |
|---|---|---|
| `/membership-guide/` | `web/membership-guide.php` | **Built** — first standalone surface (delivery shape locked) |
| `/manage-subscription/` | `web/manage-subscription.php` | **Built (read-only Patreon)** — launch-critical; "Manage on Patreon" linkout, no Stripe |
| `/lgjoin/` | _to add_ | JS+REST-heavy — page shell standalone, REST stays on poller pool |
| `/lggift-buy/` | _to add_ | JS+REST-heavy |
| `/lggift/` | _to add_ | JS+REST-heavy |
| `/my-gifts/` | _to add_ | JS+REST-heavy |
| `/affiliate-earnings/` | _to add_ | Admin form heavy |
| `/request-refund/` | _to add_ | Static-ish |
| `/welcome/` | _to add_ | Static-ish (welcome modal still fires from WP-side hook on next page load — see notes) |
| `/regional-pricing-not-available/` | _to add_ | Static-ish |
| `/test-checklist/` | _to add_ | Admin-only; defer until last |

## Deploy checklist (sysadmin / coordinator)

1. **DB secret:** create `/etc/lg-membership-db` (mode 0640, owner root, group www-data) with `DB_NAME=looth_dev`, `DB_USER=…`, `DB_PASSWORD=…`, `DB_HOST=localhost`. On dev, `config.php` falls back to `/etc/lg-events-db` automatically if the membership-specific secret isn't present.
2. **FPM pool:** provision `/etc/php/8.3/fpm/pool.d/membership.conf` matching the events pool shape, listening at `unix:/run/php/php8.3-fpm-membership.sock`. Restart `php8.3-fpm.service`.
3. **nginx snippet:** copy `nginx-snippet.conf` to `/etc/nginx/snippets/strangler-membership-pages.conf` and add an `include` line in `dev.loothgroup.com.conf` near the other strangler includes. `nginx -t` then reload.
4. **Verify:** `curl https://dev.loothgroup.com/membership-guide/` (with dev cookie) returns the shared shell + PoC content.

## Notes — what this does NOT do yet

- **Full feature parity** with the legacy WP-templated guide. The 707-line template at `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/templates/page/membership-guide.php` includes recurring shows carousel, demo clips (YouTube + image + MP4), screenshot grid, forums image card, admin preview bar with Visitor/Member toggle, inline-edit hooks for admins. Those port in subsequent turns once the standalone delivery is approved on the PoC subset (preview cards + elders + loothalong CTA).
- **Welcome modal** (`_lg_pending_welcome` user meta consumed by JS on next page load) — fires from the WP-side `wp_footer` hook, NOT from this standalone surface. As long as the user's NEXT navigation lands on a still-WP-templated page, the modal still fires. Once all WP pages are standalone, the welcome modal needs its own port (small REST call to consume the meta + an inline modal in the standalone surface).
- **`[lg_member_nav]`** — folds into the shared header account dropdown per coord §3k. Lives in lg-shell's lane; this surface doesn't render a secondary nav strip.
- **BB/Elementor CSS dequeue** — not relevant here. Standalone surface doesn't boot WP at all, so the theme's enqueue chain never runs. This is one of the §0b wins.

## Coexistence with the `template_include` mu-plugin

Per coord directive: leave [`lg-membership-chrome.php`](/var/www/dev/wp-content/mu-plugins/lg-membership-chrome.php) installed as the fallback render reference. Once nginx routes `/membership-guide/` to the standalone PHP here, the WP catch-all never receives that URL — so the mu-plugin's `template_include` filter never fires for the standalone slugs. Both can coexist; only the nginx-matched location wins.

The mu-plugin remains useful for:
- Any slug not yet ported to standalone
- Emergency rollback if a standalone surface has a bug (remove the nginx location, the slug falls back to the WP-templated render automatically)
