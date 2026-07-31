# Serving /shop-layout-planner/ standalone — the integration plan

Companion to `docs/shop-planner-step1-findings.md` (what serves it today).
Everything here is **common to both mock options** and does not depend on which
layout Ian picks — the pick changes the arrangement of the `<main>`, not any of
the couplings below.

Status: **plan only, nothing built.** Measured on live read-only, 2026-07-31.

---

## The one real technical question, answered

*How does a page that does not boot WordPress render a modal that today only a WP
plugin can emit?*

**Because the markup has no WordPress in it.** `lgapps_shop_planner_render_modal()`
(`lg-apps/apps/shop-planner/app.php:55-233`) is a function whose entire body is
static HTML — no `esc_*`, no `get_*`, no `is_user_logged_in()`, no translation
calls. I grepped the whole span; the only PHP token inside is the closing `<?php`
at :232.

So the fix is an **extraction, not a reimplementation**:

```
lg-apps/apps/shop-planner/partials/planner-markup.php   ← the HTML, verbatim, WP-free
    ├── included by lgapps_shop_planner_render_modal()  (plugin path, unchanged behaviour)
    └── included by the standalone front controller     (new path)
```

One source of truth, so the two renders cannot drift. **Do not copy the markup** —
a copy is how the two pages start disagreeing three months from now.

## What the standalone page must supply that WordPress supplies today

| # | Supplied today by | What it is | Standalone plan |
|---|---|---|---|
| 1 | `lg-apps.php:38-45`, `app.php:13-38` | `lgapps-base.css`, `shop-planner.css`, `shop-planner.js` | Link by URL. **All three verified 200** on live under `/wp-content/plugins/lg-apps/…`. Add `?v=` from `filemtime`. |
| 2 | `app.php:23-29` | jsPDF from `cdnjs.cloudflare.com` | Keep as-is for parity now; **vendor it** as a follow-up (see risks). |
| 3 | `lg-apps.php:99-106` | `window.lgapps_gating` | Emit the same object. JS degrades safely without it (`shop-planner.js:18` — `if (!g) return false`), and `gated_features` is **empty on live today**, so nothing is actually gated. Low risk, but emit it rather than rely on the fallback. |
| 4 | `class-lgapps-ads.php:21` (`wp_footer` prio 51) | **Rotating StewMac affiliate ads in the planner sidebar** | **Must be reproduced — this is revenue.** See below. |
| 5 | `class-lgapps-ads.php:24` (prio 52) | operator custom CSS (`lgapps_settings.custom_css`) | Empty on live today. Emit for parity. |

### #4 is the one that would quietly cost money

`LGApps_Ads::render_ads()` injects a script that builds an ad slot into the
planner's sidebar and rotates it. Live config, read from `wp_options.lgapps_settings`:

- **3 StewMac affiliate ads** (Ghost Drive pedal kit, Prop-Jack, Mojotone winder)
- `placement: sidebar`, `visibility: logged_out`, `rotate_sec: 8`

A standalone page that renders the planner perfectly and drops this is a silent
revenue regression that no HTTP-200 check would ever notice. The front controller
therefore needs to read that option and emit the same rotation. `lgapps_settings`
is a serialized PHP array in `wp_options` — readable with `unserialize()` over the
same PDO handle the membership pages already use.

**Also note the page-level ad**: the `[lgapps_logged_out]` StewMac block inside
page 68840's own content is separate from the sidebar rotation. Both exist; both
need to survive.

## Auth state without booting WordPress

Needed only for #3 and #4 (ads are logged-out-only). The established pattern is
already in the repo — `membership-pages/web/manage-subscription.php:41-64` reads
the `wordpress_logged_in_<hash>` cookie, takes the first pipe-segment as
`user_login`, and looks up the ID with one prepared query. Reuse it; do not invent
a second auth path.

## nginx

```nginx
location ^~ /shop-layout-planner/ {
    # ^~ is load-bearing: a REGEX location outranks a plain prefix one, so without
    # it the static-asset regex takes the page's own CSS and the page renders naked.
    ...
}
```

Trailing-slash and non-slash must both land (today `/shop-layout-planner` 301s to
the slashed form — preserve that, it is what Google has indexed).

## `__DIR__` is a trap here, specifically

The front controller will be **symlinked** into the docroot, and `__DIR__` resolves
*through* the symlink to the repo — where `wp-load.php` is not. `manage-subscription.php`
sidesteps this by requiring shared code by **absolute path**
(`membership-pages/web/manage-subscription.php:31-32`: `require '/srv/lg-shared/site-header.php'`).
Do the same. `__DIR__`-relative requires are fine only for files that travel with
the controller inside the repo.

## Deploy order (said back, from `docs/runbooks/deploy-symlink-couplings.md`)

```
1. merge to main, push
2. git -C ~/loothplatformv2-clean pull --ff-only origin main
3. webroot/install-symlinks.sh --new-only     ← NEW webroot file; the pull does NOT link it
4. sudo nginx -t                              ← new location block
5. sudo systemctl reload nginx
6. verify the WORKER start time changed, not the master's:
       ps -eo lstart,cmd | grep "[n]ginx: worker"
7. bash tools/gates/shop-planner-url-gate.sh                       (dev2)
   LG_SP_EXPECT_STANDALONE=1 bash tools/gates/shop-planner-url-gate.sh
```

Steps 3 and 5 are the two couplings a plain pull silently misses. **Live is Ian's
to run**, handed over as literal commands.

## Verification — and a piece of good news

dev2 bounces **every** anonymous WP-rendered page into the BuddyBoss members gate
(measured: `/privacy/`, `/terms/`, and this page all 302 to
`bp-auth=1&action=bpnoaccess`), while its rows for 68840/63845 are byte-identical
to live's. So **today this URL cannot be verified anonymously on dev2 at all.**

Once nginx serves it ahead of WordPress, it becomes anon-reachable on dev2 exactly
as `/manage-subscription/` and `/hub/` already are (both measured **200 anon**).
**Going standalone is what makes this page verifiable before merge** — which is
normally the thing that forces a feature flag. Worth Ian knowing when he weighs
whether this one needs flagging.

`tools/gates/shop-planner-url-gate.sh` (gate 11/11) encodes the invariant and
already goes green against live today, so we have the "before" proof on record.

## Risks I am carrying, in order

1. **Ads dropped silently** (#4). Highest-consequence, lowest-visibility. Must be
   in the build and should get an assertion, not a spot-check.
2. **The planner is desktop-only.** Mouse-only canvas handlers, sidebar hidden
   under 600px. Pre-existing, not caused by this work; both mocks show an honest
   small-screen state rather than a broken tool. Real fix is separate work.
3. **jsPDF from a third-party CDN** (`app.php:25`). PDF export is advertised on
   this page and currently depends on cdnjs staying up and unblocked.
4. **Option A needs an inline container.** `_lgapps_opener_shop_planner`
   (`shop-planner.js:61-66`) sets `display:flex` on the fixed overlay and locks
   `body.overflow`. Mounting inline needs a container variant that doesn't lock
   scroll — a small, contained change, but it is the one code change Option A
   needs that Option B does not.
5. **Page 63845 stays untouched.** Members-gated, 1 hit, not the SEO surface. No
   reason to go near it, and live writes are Ian's regardless.
