# Relay — port the WHOLE Stripe operation to standalone + menu it (admin-gated)

**From:** coordinator (ubuntu), 2026-06-02 · **Source of truth:** the poller's
`lg-patreon-stripe-poller/src/Wp/Pages.php` `PAGES` registry + `src/Wp/Shortcodes.php`
render methods.

## What & why (Ian, 2026-06-02)

Port the **entire** Stripe membership operation out of WordPress into standalone
surfaces (the `membership-pages` repo pattern), so Ian can work on it privately
before launch. **Each page should look IDENTICAL to its current WP-shortcode render —
the only change is the chrome: drop the BB theme, wrap in the shared shell
header/footer (`/srv/lg-shared/site-header.php` + `site-footer.php`).** Verbatim body,
new chrome.

**Visibility: ALL stripe pages are admin-only** (`$caps['manage_options']`) so Ian can
build against them unseen — **EXCEPT `/manage-subscription/`**, which stays
**members-visible** because it shows the member their live Patreon-or-Stripe status
(confirmed: `lg_manage_subscription` renders Stripe state + a unified Patreon/comp
card). Flip the rest to member-visible at Stripe go-live.

## The full page set (poller PAGES registry + connect-your-patreon)

| shortcode | slug | nav label | standalone? | menu visibility |
|---|---|---|---|---|
| `lg_manage_subscription` | `manage-subscription` | Manage Subscription | DONE ✅ | **MEMBERS** (the exception) |
| `lg_patreon_onboard` | `connect-your-patreon` | Connect Your Patreon | TODO | admin-only |
| `lg_join` | `lgjoin` | Join | TODO | admin-only |
| `lg_gift` | `lggift-buy` | Gift Memberships | TODO | admin-only |
| `lg_redeem_gift` | `lggift` | Redeem a Gift | TODO | admin-only |
| `lg_my_gifts` | `my-gifts` | My Gifts | TODO | admin-only |
| `lg_affiliate_portal` | `affiliate-earnings` | Earnings | TODO | admin-only |
| `lg_refund_request` | `request-refund` | Request a Refund | TODO | admin-only |
| `lg_test_checklist` | `test-checklist` | Test Checklist | TODO | admin-only |
| `lg_membership_guide` | `membership-guide` | Membership Guide | DONE ✅ | admin-only (was always) |
| `lg_subscription_success` | `welcome` | — | TODO | not menued (transient) |
| `lg_regional_fail` | `regional-pricing-not-available` | — | TODO | not menued (transient) |

**Connect Your Patreon is its own discrete page, split out of `/join/`.** `/join/`
already contains the Patreon-connect funnel as its primary action; lift that funnel
into a clean `/connect-your-patreon/` page so `/join/` is free to become the Stripe
checkout page later without conflating the two. `/join/` stays as-is (live, public)
and remains the public Patreon entry meanwhile.

---

## → TO THE STANDALONE LANE

```
TASK: Port the ENTIRE Stripe membership operation to standalone surfaces in the
membership-pages repo (/home/ubuntu/projects/membership-pages). Each page must
render VERBATIM identical to its current WP-shortcode output — ONLY the chrome
changes: no BB theme, wrap in /srv/lg-shared/site-header.php + site-footer.php
(same pattern as the already-shipped manage-subscription.php /
membership-guide.php).

SOURCE OF TRUTH: Wp/Pages.php (PAGES registry) + Wp/Shortcodes.php (render methods).

ALREADY DONE (skip): membership-guide, manage-subscription.

NOT DONE — DO NOT SKIP /lgjoin/. It is the real outstanding work: the lg_join
Stripe tier-picker + checkout, which has NO standalone file and still renders as
the full WP/Elementor page. The existing join.php is the /join/ PATREON FUNNEL
ONLY and DELIBERATELY OMITS the checkout (its own header: "checkout is dormant
now and is intentionally NOT built here"). /join/ (funnel) != /lgjoin/ (Stripe
checkout). Treating "join" as done is wrong — corrected 2026-06-03.

PORT THESE (slug — shortcode):
  connect-your-patreon            — lg_patreon_onboard   (SPLIT the Patreon-connect funnel out of join.php into its own page; don't touch join.php's funnel yet)
  lgjoin                          — lg_join
  lggift-buy                      — lg_gift
  lggift                          — lg_redeem_gift
  my-gifts                        — lg_my_gifts
  affiliate-earnings              — lg_affiliate_portal
  request-refund                  — lg_refund_request
  test-checklist                  — lg_test_checklist
  welcome                         — lg_subscription_success   (transient, no nav)
  regional-pricing-not-available  — lg_regional_fail          (transient, no nav)

ROUTING (Ian 2026-06-03 — NO nginx birds-nest, NO iframes):
- Do NOT add a per-page nginx location. Use ONE front-controller `web/router.php`
  + ONE nginx location matching all membership slugs (regex alternation) that
  fastcgi-passes to router.php with the slug as a param — mirror archive-poc's
  single render.php pattern. router.php holds the slug→{file,visibility} registry,
  applies the admin-gate, and `include`s the matching page file. One assets
  location + one PHP-deny. Fold the existing per-page blocks (membership-guide,
  manage-subscription) INTO the single location.
- NO new iframes — every page is a native verbatim port of its lg_* shortcode body.
  (manage-subscription's existing Stripe-panel iframe is the one tolerated legacy
  exception until that panel is itself ported.)
- coordinator applies the single nginx location via the sudo-queue.

DATA: read page/subscription data via the existing lib/*-data.php pattern
(lg_membership_db / lg_membership_poller_db). Identity via lib/whoami.php
(looth_id mints again as of 2026-06-02; tier still comes from WP — read, don't recompute).

STRIPE: live in TEST mode in the sandbox (sk_test_, charges_enabled, same key both
sides) — wire buttons to the standalone /billing/v1/* API and exercise end-to-end.

Report back per standard format as each slug lands + smoke-tests.
```

## → TO THE SHELL LANE (lg-shell)

```
TASK: Add the Stripe-operation pages to the user ACCOUNT menu in
/srv/lg-shared/site-header.php.

VISIBILITY: every item ADMIN-ONLY (gate on the existing $caps['manage_options']
flag already used for the WP Admin link) EXCEPT Manage Subscription, which is
MEMBERS-visible.

  Manage Subscription  → /manage-subscription/    MEMBERS (logged-in)
  Connect Your Patreon → /connect-your-patreon/   ADMIN ONLY
  Join                 → /lgjoin/                 ADMIN ONLY
  Gift Memberships     → /lggift-buy/             ADMIN ONLY
  Redeem a Gift        → /lggift/                 ADMIN ONLY
  My Gifts             → /my-gifts/               ADMIN ONLY
  Earnings             → /affiliate-earnings/     ADMIN ONLY
  Request a Refund     → /request-refund/         ADMIN ONLY
  Test Checklist       → /test-checklist/         ADMIN ONLY

Do NOT menu the transient pages (welcome, regional-pricing-not-available).
WHY admin-only: Ian is building the Stripe operation privately pre-launch; only
Manage Subscription needs to be live for members (shows their Patreon/Stripe status).

Report back per standard format; verify the admin-only items are absent for a
normal member and present for an admin.
```
