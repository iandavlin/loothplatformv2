# /weekly-email-sign-up/ — STEP 1 findings (what actually serves it)

Lane: `weekly-digest-recap`, 2026-07-31. **Survey only. Nothing changed.**
Modelled on `docs/shop-planner-step1-findings.md`, which is why that lane's rest was small.

---

## 0. The BEFORE, recorded first

Keeper: *"this is the only before you get, and it is the half a new-page check cannot see."*
Captured from the LAN IP with the dev cookie, saved to `/tmp/wd-before/`:

```
GET /weekly-email-sign-up/   200   152,537 bytes   0.42s
sha256 b479382803…c552574
title  "Weekly Email Sign Up – The Looth Group"
```

| signal | value |
|---|---|
| our signup form (`lgws-form`) | present |
| sample-email iframe | present (keeper enabled `LG_WD_SIGNUP_EMAIL_PREVIEW` on dev2) |
| frame width | **992px — variant B is live on the serve** |
| `wp-content/themes/` refs | **4** |
| `wp-includes/` refs | **37** |

Those last two rows are Ian's complaint, measured.

---

## 1. What serves it today

WordPress page **68595**, slug `weekly-email-sign-up`, whose entire `post_content` is the
shortcode `[lg_weekly_signup]`. The shortcode is registered by
`lg-weekly-digest/includes/class-lg-wd-signup-page.php` and renders
`lg-weekly-digest/templates/signup-page.php`.

The page is then wrapped by the **active WordPress theme, `twentytwentyfive`** — the stock
default. That is where the 4 theme refs, the 37 `wp-includes` refs and most of the 152KB
come from. **Ian, twice: *"nothing renders from the theme. We do standalone rendering."***

Stale-metadata note carried over from the earlier survey: page 68595 holds
`_wp_page_template = page-fullwidth.php`, which `twentytwentyfive` does not offer, and
**live holds the identical value**. `wp_update_post()` therefore returns
`WP_Error('invalid_page_template')` *after already writing the content* — proven on a
throwaway page. Not blocking this work; it matters if anyone edits 68595.

---

## 2. THE STANDALONE CONTROLLER ALREADY EXISTS AND IS PROVEN

This is the finding that makes the job small. `lg-weekly-digest/dev/preview/signup-preview.php`
— written for lane-preview — already renders this page with **no WordPress theme at all**.
Measured on the dev2 serve, same moment, same cookie:

| | bytes | `themes/` | `wp-includes/` | form | iframe |
|---|---|---|---|---|---|
| WP-rendered `/weekly-email-sign-up/` | 152,449 | **4** | **37** | yes | yes |
| the preview controller | **20,863** | **0** | **0** | yes | yes |

**86% lighter, zero theme, and the surface is intact.** The standalone conversion is
therefore a *promotion* of an existing, working controller — not a rebuild.

### Why the hard part was already paid for

The two things that usually make a WP page hard to lift out are already done here:

- **The form POST never needed WordPress to render the page.** It goes to
  `wp_ajax_nopriv_lg_weekly_signup` in `platform/mu-plugins/lg-event-reminders.php`. A
  cross-pool HTTP POST does not care what drew the page.
- **The sample-email preview is on `admin-ajax`** (`fca65ac`), which WordPress routes
  unconditionally. It was moved there *because* it had been coupled to page routing — and
  that change is exactly what lets the page stop being a WP page without the section
  vanishing. Had it still resolved from `get_permalink()`, this conversion would silently
  delete the sample email.

---

## 3. ⚠️ A DEFECT IN MY OWN PREVIEW CONTROLLER, found by reading the reference

`signup-preview.php:47` does `require '/var/www/dev/wp-load.php'` — an absolute path
hardcoded to **dev2's docroot**. Correct for a dev2-only preview; **fatal on live**, which
has a different docroot.

`webroot/lg-wp-load.php` exists for exactly this and must be used instead:

> *"PHP resolves `__DIR__` through the symlink to the REPO directory — where wp-load.php
> does not and will never live. … The docroot is the only place that knows where WordPress
> is; ask the request."*

The promoted controller uses `require __DIR__ . '/lg-wp-load.php'`. **This is the single
most important correction in this survey** and I would not have found it by testing on dev2,
because on dev2 the hardcode works.

---

## 4. What the conversion needs (shop-planner pattern, unchanged)

| item | new? | how it deploys |
|---|---|---|
| `webroot/weekly-email-sign-up.php` | new | `webroot/install-symlinks.sh --new-only` — **a pull does NOT link it**, and it must be run so the link is owned correctly (as `looth-dev`; as `ubuntu` you get EACCES, which reads as "not there") |
| `platform/nginx/strangler-weekly-signup.conf` | new | **symlink into `/etc/nginx/snippets/` pointing at `~/loothplatformv2-clean`** — a pull does not create it, and per keeper's finding it must NOT point at a worktree |
| page 68595 | untouched | **leave it published — it is the rollback** |

nginx shape, copied from `strangler-shop-planner.conf` with its two load-bearing details:

- `location = /weekly-email-sign-up` → 301 to the slashed form (exact match, cannot shadow assets)
- `location ^~ /weekly-email-sign-up/` → `SCRIPT_FILENAME $document_root/weekly-email-sign-up.php`
  - `^~` because **a regex location outranks a plain prefix one** (box trap 6)
  - the **trailing slash** so a same-named `.css` is not swallowed into PHP

**ROLLBACK:** comment out the include and reload. The slug falls straight back to the
WordPress render of 68595. That is why 68595 must not be edited or unpublished.

---

## 5. Traffic — UNMEASURED, not zero

I could not measure it and will not imply otherwise. Live's readable access log had a
**one-second window** when I looked (`31/Jul/2026:00:34:10 .. 00:34:10`) — freshly rotated.
`shop-layout-planner` reads 0 in that same window while that lane measured **1,331** hits,
which proves the window, not the traffic.

**Treat the URL as load-bearing until someone measures it over a real window.** It is linked
from the site and is the destination for every non-member signup.

---

## 6. For the sweep keeper mentioned

**149 published WP pages on dev2.** The membership router claims 13
(`membership-guide|manage-subscription|connect-your-patreon|lgjoin|lggift-buy|lggift|my-gifts|affiliate-earnings|request-refund|test-checklist|welcome|regional-pricing-not-available|join`),
leaving **137 unclaimed by it**. An unknown subset of those is additionally claimed by
archive-poc's strangler at nginx level (`/archive`, `/all-forums-all-topics`, `/calendar`
and friends); the remainder fall through to WordPress and inherit whatever theme is active.

**I have NOT walked the nginx locations against all 137** — that is the sweep's step 1, not
mine, and a number I had not checked is worth less than none. Handing the 137 to keeper
rather than doing it here, as instructed.
