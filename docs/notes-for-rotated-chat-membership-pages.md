# Notes for the rotated poller chat — membership pages onto shared shell

Tacit knowledge the prior chat paid for that won't be in any other doc.
Read this after the briefing + before diving into the code.

---

## Pages.php — what it actually does (with line refs)

[Pages.php](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Pages.php)

### `const PAGES` (line 60) — 11 entries, not "~15"

The briefing rounds up. The current registry has **11 pages**:

| Tag (key) | Slug | `visibility` | `in_nav` | `template` |
|---|---|---|---|---|
| `lg_join` | `lgjoin` | `guests` | yes | `page-fullwidth-content.php` |
| `lg_gift` | `lggift-buy` | `always` | yes | `page-fullwidth-content.php` |
| `lg_redeem_gift` | `lggift` | `always` | yes | `page-fullwidth.php` |
| `lg_manage_subscription` | `manage-subscription` | `members` | yes | `page-fullwidth.php` |
| `lg_regional_fail` | `regional-pricing-not-available` | (none) | no | `page-fullwidth.php` |
| `lg_subscription_success` | `welcome` | (none) | no | `page-fullwidth.php` |
| `lg_my_gifts` | `my-gifts` | `gift_buyers` | yes | `page-fullwidth.php` |
| `lg_membership_guide` | `membership-guide` | `always` | yes | `page-fullwidth.php` |
| `lg_affiliate_portal` | `affiliate-earnings` | `affiliates` | yes | `page-fullwidth.php` |
| `lg_test_checklist` | `test-checklist` | `admins` | yes | `page-fullwidth.php` |
| `lg_refund_request` | `request-refund` | `members` | yes | `page-fullwidth.php` |

`visibility` is consumed by `Pages::navItems()` (line ~180) to filter the nav, NOT to gate page access. Each shortcode does its own access gating internally.

The `template` values reference **BuddyBoss theme files**. When BB theme decommissions those page-template assignments become dangling. The membership-pages mu-plugin's `template_include` hook will replace them entirely — the registry `template` field becomes vestigial info, you can leave it for documentation or strip it during the cleanup pass.

### `ensureAll()` (line 292) → `ensureBuddyBossAllowlist()` (line 342)

`ensureAll()` runs on plugin activation. Iterates PAGES; for each, calls `pageHostingShortcodeExists($tag)` — a `wpdb LIKE '%[$tag%'` search over `wp_posts.post_content`. If no page hosts the shortcode and the slug is free, `createPage()` builds the page with body:

```php
$body = ( $includeNav ? '[lg_member_nav]' : '' ) . '[' . $info['shortcode'] . ']';
```

**Every auto-seeded page has `[lg_member_nav]` baked into its post_content** unless `include_nav=false` is set in the registry entry (none of the current entries opt out). When you decide to fold `lg_member_nav` into the shared header (briefing's open decision #1), the cleanup is two-fold:
1. Remove `[lg_member_nav]` from the auto-seed body in `createPage()`.
2. **Existing pages already have it in their content.** Either strip via one-shot `wp post update` SQL, or leave `lg_member_nav` shortcode registered as a no-op so existing content doesn't render literal `[lg_member_nav]` text. The no-op route is safer for the live cutover.

### BuddyBoss public-content allowlist — the coupling that breaks at decommission

`ensureBuddyBossAllowlist()` reads/writes WP option `bp-enable-private-network-public-content` — a **newline-separated string of slugs**. BB consults this option to decide whether to serve a page to anonymous visitors when "Private Network" mode is on.

**When BB theme/plugin is decommissioned, this option becomes meaningless** — the consumer is gone. Two questions for the rotated chat:
1. Does the new anon-visibility mechanism (post-BB) want to keep reading this option as the canonical source-of-truth and just have the new gate consult it? Cleaner — preserves the existing per-page flag.
2. Or replace it with something else (nginx-level allow-list, in-template `is_user_logged_in()` check, mu-plugin filter on `template_redirect`)? More work; better if the BB option's semantics don't match the new model exactly.

If you keep using the option, `ensureBuddyBossAllowlist()` doesn't need changing (it just keeps populating an option nobody enforces yet). If you drop it, also drop the call from `ensureAll()`.

There's a **targeted BB cache invalidation** at line 325-330 inside `ensureAll()`. When BB goes, that cache delete is harmless but dead — strip it during the cleanup.

---

## Shortcodes.php — what's actually inside the 388KB

[Shortcodes.php](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Shortcodes.php) — registers 11 of the 12 shortcodes (line 21-31). `lg_membership_guide` is in [MembershipGuide.php](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/MembershipGuide.php) instead (registered line 97, render method `MembershipGuide::render`).

### `[lg_member_nav]` (line ~5905)

`memberNav()` outputs `<style>` + `<nav class="lg-member-nav">` inline. CSS is opacity-fade + border-bottom-on-current. Wraps each item in `<a class="lg-member-nav__link[ is-current]">`.

Auto-discovers pages by `wpdb LIKE '%[lg_join%'` for each registry tag. Hides items whose hosting page doesn't exist. Pulls labels via `Pages::navItems()` (so visibility filtering happens there).

**Folding into shared header:** the shared header (`/srv/lg-shared/site-header.php`) takes a `tier` + capability bundle — it's chrome, not membership-aware navigation. If the rotated chat decides to fold `lg_member_nav` into the shared header, it'll likely live as a **separate secondary strip below** the shared header, conditional on the page slug being a membership page. Pushing nav items into the shared header itself muddies the lg-shell contract (the briefing is explicit: "do NOT modify it — you consume it").

### Fragile vs. clean — which shortcodes will fight the shared shell

**Clean (will render fine in a generic content area):**
- `lg_membership_guide` — pure presentational HTML, no JS modals (uses its own admin preview bar though — read [MembershipGuide.php](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/MembershipGuide.php) for what that bar expects from theme chrome).
- `lg_refund_request` — short form.
- `lg_regional_fail` — informational only.

**Fragile (have inline JS that interacts with WP cookies / DOM structure assumptions):**
- `lg_join` — has `loadProducts()` JS at ~lines 980 / 2456 / 3488 (three separate definitions across handlers — surprising, suggests the same logic was inlined three times). Does WP REST POST to `/wp-json/lg-member-sync/v1/*` and Stripe Checkout redirect. The PoC page can sidestep this: pick `/membership-guide/` first per the briefing's hint.
- `lg_my_gifts` — modal-driven (send/resend/reassign/void) via REST. Modal opens against `data-lg-*` attributes.
- `lg_manage_subscription` — payment-method UI, plan-switch confirm modal, cancel-timing radio. The "existing-account modal" at ~line 3389 was edited by the prior chat (dropped inline "Incorrect password." string).
- `lg_subscription_success` — has welcome modal at ~line 4247-4317 (`showWelcome()` triggers `<h3 id="lg-welcome-title">`). Reads `_lg_pending_welcome` user meta. The welcome modal CTAs got renamed in [Plugin.php:555-556](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Plugin.php#L555) ("Take the tour → Member Guide", "Jump to the feed → See What's New").
- `lg_affiliate_portal` — admin-facing rate table + withdraw form. **Save rates uses `submit_button()` which renders `<input name="submit">` — that shadows the form's `submit()` method in JS.** This is the CDP gotcha caught earlier and documented in memory ([feedback_cdp_form_submit.md](file:///home/ubuntu/.claude/projects/-home-ubuntu-projects/memory/feedback_cdp_form_submit.md)). If you CDP-drive this page for the PoC, use `requestSubmit()` or click the button — never `form.submit()`.

### Existing assumptions about theme chrome

Some shortcodes assume body classes set by BB's `body_class` filter (e.g. `lgms-mg-anon` / `lgms-mg-member` — Plugin.php adds those via `addCustomerBodyClass`). If your mu-plugin's `template_include` replaces the page entirely, **call `body_class()` in the new template** so those filters still fire — otherwise `[lg_membership_guide]`'s admin preview toggle breaks.

The mu-plugin template should also:
- `wp_head()` and `wp_footer()` (so plugin-registered scripts/styles still load, including the welcome-modal JS Plugin.php enqueues)
- Match the body opening pattern from `_chrome.php` so `the_content()` lands inside a CSS-compatible wrapper (the shared header's CSS expects a specific structure — read `_chrome.php:325-410` per the briefing)

---

## CDP gotchas (saved in memory but worth in-doc)

[feedback_cdp_form_submit.md](file:///home/ubuntu/.claude/projects/-home-ubuntu-projects/memory/feedback_cdp_form_submit.md) — WP `submit_button()` renders `<input type="submit" name="submit">` which **shadows `form.submit()` on every WP admin form** (and the affiliate page, and likely a few of the shortcode forms too). Always use `form.requestSubmit()` or click the button element in CDP `Runtime.evaluate`.

The chrome-dev-login skill is the canonical way to drive these pages — it mints WP auth cookies for `claude_admin` (uid=1904) and the cookie-gate `loothdev_auth` cookie. **Critical cookie-mint detail** the skill captures: `is_ssl()` returns `false` in wp-cli, so naive `wp_generate_auth_cookie($uid, $exp, $secure ? 'secure_auth' : 'auth')` mints the wrong scheme. Force `secure_auth` because the request hits over HTTPS — the dev box uses `wordpress_sec_<hash>` not `wordpress_<hash>` as the SECURE_AUTH cookie name. The skill's snippet handles this correctly.

---

## Other landmines

### Welcome email + welcome modal trigger meta

`_lg_pending_welcome` user meta is set in `Arbiter::sync()` on upgrade-to-paid transitions and consumed (deleted) by the `dismiss-welcome` REST endpoint. The welcome modal's footer JS hook reads it on next page load. If your PoC template swap drops the wp_footer call, the modal won't fire and the meta will pile up.

### Pages.php's pageHostingShortcodeExists vs. ensureAll idempotency

`ensureAll()` is called on plugin activation. If you migrate a page's body content (e.g. strip `[lg_member_nav]` from existing pages), ensureAll still considers the page "existing" because the search is `LIKE '%[lg_join%'` on the shortcode tag, not on the full body. Safe to run repeatedly.

### Suggested PoC sequence

Briefing says: start with `/membership-guide/`. Why it's the right pick:
- The shortcode (`MembershipGuide::render`) is one of the cleanest — no Stripe interactions, no checkout modals, no welcome triggers.
- It's `visibility=always` so anon + member rendering both need to work.
- It already has the `lgms-mg-anon` / `lgms-mg-member` body-class dependency, which is exactly the case that proves your `body_class()` call is wired right.
- The DOM selectors in [TestChecklist.php:547](file:///var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/TestChecklist.php#L547) (the `mg-elders` item tightened last session) describe the expected page structure — you can re-run those selectors against the shared-header-rendered page to verify nothing in the content layer broke.

---

## P8 (poller dormant-mode dev smoke) — still on the lane's plate

Inherited alongside the membership-pages task. Not started. Brief description from coordinator's checklist:
> P8 ⏳ Poller dormant-mode dev smoke

Likely: smoke the poller with Stripe creds absent / poller disabled to confirm nothing crashes the WP request path. Worth scoping after the membership PoC lands; both are pre-cutover gates.

---

## Sysadmin role reminder

This ubuntu user has full sudo + nginx config write access. Edits to `/etc/nginx/sites-available/*.conf` or `/etc/nginx/snippets/*.conf` are in-lane when they serve poller-owned routes (e.g. `^~ /wp-json/looth-internal/`). The prior chat added the `/wp-json/looth-internal/` exempt directly. Same authority applies if the membership-pages mu-plugin needs a nginx tweak — though for shared-shell rendering, no nginx change should be required (it's a WP `template_include` hook).
