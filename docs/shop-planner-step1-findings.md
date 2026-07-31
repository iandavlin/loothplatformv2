# Shop Layout Planner — STEP 1 findings (what actually serves it)

Lane: `shop-planner`, 2026-07-31. All live reads via `ssh live-ro`, read-only.
**No live rows were touched.** Nothing here is a change; this is the survey Ian asked
for before anything gets rebuilt.

---

## 1. Which page traffic hits — settled, and it is not close

| Page | Slug | HTTP | Hits in log window | Verdict |
|---|---|---|---|---|
| **68840** | `shop-layout-planner` | **200** | **1331** | **THE live URL. This is the one.** |
| 63845 | `shop-planner-page` | **302** | 1 | Superseded + members-gated. Not public. |

`/shop-planner-page/` 302s to
`wp-login.php?...&bp-auth=1&action=bpnoaccess` — that is the BuddyBoss members-only
gate, so page 63845 is not reachable by the public at all and never was the SEO
surface. `/shop-planner/` (the slug the page's own authoring comment *claims*) is a
**404** — the comment block in `post_content` is stale and must not be trusted.

**Neither row should be edited or deleted.** 63845 is inert but it costs nothing to
leave alone, and live writes are Ian's regardless.

## 2. What renders the planner — it is a MODAL, not page content

The attachment name `shop-layout-planner-modal` (61556) was the right clue.

The chain, with file:line:

1. `lg-apps/includes/class-lgapps-registry.php:44-52` — the `[shop_planner]` shortcode
   returns **only a button**:
   `<button class="lgapps-open-btn" onclick="window.lgapps_open('shop-planner')">`.
   It renders **no planner markup at all**.
2. `lg-apps/lg-apps.php:84-107` — on `wp_footer` (priority 50) the framework walks the
   queued apps and calls each one's `render_modal`.
3. `lg-apps/apps/shop-planner/app.php:55` — `lgapps_shop_planner_render_modal()` echoes
   the full planner markup: `#lgapps-modal-shop-planner`, the control rows, the
   `<canvas id="lgsp-layoutCanvas">`, and the editor sidebar.
4. `lg-apps/apps/shop-planner/assets/shop-planner.js` (62,776 bytes) is the engine;
   the opener is `shop-planner.js:61` (`window._lgapps_opener_shop_planner`).
5. Page 68840's `post_content` is a single `wp:html` block: hand-pasted markup with an
   inline `<style>` for `.sp-page`, plus two `[shop_planner text="…"]` buttons and a
   `[lgapps_logged_out]` StewMac affiliate block.

So the page is **SEO/marketing copy + a button**; the app itself is injected into the
footer by the plugin and only becomes visible when the button is clicked.

## 3. Is it in the monorepo? YES — zero drift

`lg-apps/lg-apps.php` is in live's `active_plugins`. All **10** files of the plugin are
**byte-identical** (md5) between live's `/var/www/dev/wp-content/plugins/lg-apps/` and
this branch's `lg-apps/`. Nothing to reconstruct, nothing untracked.

One real exception: **jsPDF is not vendored.** `app.php:25` registers it from
`https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js`. PDF export — an
advertised feature of this page — depends on a third-party CDN at runtime.

## 4. The app is ALREADY theme-independent. The wrapper is what's broken.

`lgapps-base.css:57-76`: `.lgapps-modal` is `position:fixed; inset:0; z-index:999999`
and `.lgapps-modal-inner` is `width:100%; height:100%`. **Once open, the planner
already covers the entire viewport** and the twentytwentyfive theme is not visible.

This matters for scoping: what Ian saw rendering in the stock theme is the **marketing
page around the button** — the `.sp-page` copy — not the planner. Rebuilding the
wrapper standalone is therefore low-risk for the app itself, but it is exactly where
the SEO value lives (see §5), so the copy must survive the move.

## 5. Traffic is real but much smaller than 1331 — read this before quoting a number

Referrers: **1259 of 1331 from `https://www.google.com/`** — organic search. That part
is genuine and it is why the copy on this page earns its keep.

But the user agents do not look like 1331 people:

- **1252 of 1331 hits (94%) share one byte-identical UA:**
  `Mozilla/5.0 (X11; Linux x86_64) … Chrome/149.0.0.0`.
- Every other UA is a normal long tail (Windows/Mac Chrome, Googlebot, bingbot,
  facebookexternalhit) totalling ~79 hits.

A single identical desktop-Linux UA carrying a `google.com` referer, at 450 hits on one
day (18/Jul) decaying to ~5/day now, is the shape of an automated scraper, not of human
search traffic. **Inference, not proof** — client IPs are all Cloudflare edge addresses
(`104.22.x`, `172.70.x`), so nginx cannot see real clients and I cannot settle it from
these logs. Cloudflare analytics would.

**Honest statement of the traffic: roughly 5-10 human visits a day, from Google, plus a
scraper that inflated the raw count.** That is still traffic on a URL we should not
break — the mandate does not change — but "1331 visitors" should not be repeated to
anyone as if it were people.

## 6. Defect found while surveying: the planner is desktop-only

Not asked for, but it lands squarely on the phone-width mock Ian will be shown, so it
would be dishonest to draw a phone mock without saying it.

- **The canvas has zero touch handlers.** `shop-planner.js` binds only
  `mousedown` (:1539), `mousemove` (:1678), `mouseup` (:1761). The single `pointer`
  match in the file is `cursor:pointer` inside a CSS string — not an event. Tap-drag on
  a touchscreen does not reliably emit `mousemove`, so **drag-to-move — the core
  interaction of a drag-and-drop planner — does not work on a phone or tablet.**
- **The editor sidebar is hidden outright below 600px.** `lgapps-base.css:371-373`:
  `@media (max-width:600px) { .lgapps-sidebar { display:none; } }`. So even where a tap
  selects something, there is no rename / resize / rotate / colour / **delete**, and
  "Clear All & Start Fresh" is gone with it.
- The on-screen hint (`app.php:224-228`) instructs "Right-click+drag = pan. Scroll =
  zoom" — no touch equivalent exists.

I have **not** driven this in a real mobile browser yet; the above is read from source
and is a code-level claim. It is consistent with the 1% mobile share in the logs, but
that share is contaminated by the scraper so I am not offering it as corroboration.

---

## Deploy order for what comes next (from `docs/runbooks/deploy-symlink-couplings.md`)

Standalone render means a new webroot file **and** a new nginx location, so both
couplings a plain pull misses are in play:

```
1. merge to main, push
2. git -C ~/loothplatformv2-clean pull --ff-only origin main
3. webroot/install-symlinks.sh --new-only      ← new webroot file; pull does NOT link it
   (mu-plugins would be cutover/symlink-farm.sh with REPO= set explicitly — not needed here)
4. sudo nginx -t                                ← new location block
5. sudo systemctl reload nginx
6. verify — WORKER start time, not the master's:
   ps -eo lstart,cmd | grep "[n]ginx: worker"
7. verify the URL on the box, both before and after:
   curl -sk -o /dev/null -w "%{http_code}\n" --resolve loothgroup.com:443:127.0.0.1 \
     https://loothgroup.com/shop-layout-planner/
```

Two traps that apply directly to this job:

- **`location ^~ /shop-layout-planner/`**, prefix-with-caret. A regex location outranks
  a plain prefix one, so without `^~` the static-asset regex steals the page's own CSS.
- **`__DIR__` resolves through the symlink into the repo**, where `wp-load.php` is not.
  The standalone controller must not use `__DIR__`-relative requires that assume the
  docroot — `manage-subscription.php` avoids this by requiring `/srv/lg-shared/…` by
  absolute path (`membership-pages/web/manage-subscription.php:31-32`).

Live deploy steps are Ian's to run, handed over as literal commands.

## What I still owe

- Mocks: recommendation + one alternative, **phone width included**, committed and
  published behind the dev gate, URL to keeper. Build only after Ian picks.
- A gate that asserts `/shop-layout-planner/` answers **200 before AND after** — a gate
  that only checks the new page renders cannot see the old URL break.
