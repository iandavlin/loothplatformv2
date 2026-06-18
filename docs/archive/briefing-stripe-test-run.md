# Briefing — full Stripe-integration test run (admin + user)

**Paste into a fresh terminal Claude. You are the lg-stripe / poller QA-run lane.**

## 0. Orient (do this first)
1. **Confirm you're ON the dev box:** `curl -s ifconfig.me` → `50.19.198.38` and `whoami` → `ubuntu`.
   If both match you ARE dev.loothgroup.com — act locally with sudo, do NOT SSH anywhere. Read `~/.claude/CLAUDE.md`.
2. **Read the lane state before touching anything:** `/home/ubuntu/projects/docs/SESSION-HANDOFF.md`
   (poller lane), then `/srv/lg-stripe-billing/PICKUP.md` and `/srv/lg-stripe-billing/docs/purchase-scenarios.md`.
3. **This is a QA execution run, not a build.** Your job: drive every test-packet item end-to-end, in
   TEST mode, and record pass/fail/bug with repro. Do NOT refactor. If you find a bug, log it; only make a
   fix if it's small AND inside the poller/billing lane — otherwise file it for the coordinator (route via Ian).

## 1. The system under test
Two-repo billing stack, both on this box, in **Stripe TEST mode** (`pk_test_`, 2 live products: looth2 LITE,
looth3 PRO, 3 prices each):
- **Slim billing API** — `https://dev.loothgroup.com/billing/v1/*` (FPM pool `php8.3-fpm-lg-billing-dev.sock`),
  source `/srv/lg-stripe-billing/`. Endpoints: `/config /products /checkout /portal /return /redeem`.
- **WP poller plugin** — `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` (pollers, Arbiter,
  capability writer, shortcodes, the TestChecklist).
- Surfaces (all 200 via the membership router as of 2026-06-04): `/lgjoin/`, `/manage-subscription/`,
  `/lggift-buy/`, `/lggift/`, `/my-gifts/`, `/membership-guide/`, plus `/test-checklist/`.

## 2. The test packet (authoritative source)
The canonical packet is `TestChecklist::SECTIONS` in
`.../lg-patreon-stripe-poller/src/Wp/TestChecklist.php` (~line 454), rendered by the `lg_test_checklist`
shortcode at **`/test-checklist/`** (admin-bypass on the page password for `manage_options`). Each item has
a `desc` (what to do), an `expect` (pass criteria), sometimes a `url`, and `audience:'admins'` for admin-only
items. The eight sections:

1. **Auth & sign-up** (`auth-*`) — new signup, welcome email, existing-acct right/wrong password, auth throttles (6×/email→429, 21×/IP→429).
2. **Subscribe → checkout → return** (`sub-*`) — LITE monthly, PRO annual, promo `PATREON5`, double-click spam, close-modal, regional block.
3. **Manage subscription** (`mgr-*`) — render, cancel period-end/immediate, plan switch up/period-end/past-due/cooldown, add/set-default/delete PM, delete-only-PM block, invoices, **IDOR** (other user's sub_id → 403).
4. **Gift purchase** (`gb-*`) — anon, managed, qty rules (DevTools), bulk 10/20/50 discounts, spam.
5. **Gift dashboard `/my-gifts/`** (`mg-*`) — buckets, send/resend/reassign/void, wrong-account gate, **IDOR**.
6. **Gift redemption `/lggift/`** (`rd-*`) — new recipient, sign-in variant, wrong-user, stapled-email override, tier-conflict picker, active-gift warn.
7. **Membership Guide `/membership-guide/`** (`mg-*` guide) — anon vs member vs **admin preview bar** (Visitor/Member toggle, test-email form), elders/events/shows widgets, Loothalong gating.
8. **Admin tools (wp-admin)** (`ad-*`) — user-edit membership panel, Cancel & Refund, Block, re-create/sync pages.

**Run it in two passes so the audience split is clean:**
- **USER pass** — every non-`admins` item, as an anonymous visitor and as logged-in members.
- **ADMIN pass** — every `audience:'admins'` item + section 8 (wp-admin) + the membership-guide admin bar, as a `manage_options` admin.

## 3. How to drive it
- **Browser:** load the `chrome-dev-login` skill — it drives the local headless Chrome over CDP as a
  fully-logged-in WP admin (cookie gate + WP auth + editor mode). Use it for the ADMIN pass and for any
  member-state UI. For the USER pass, create real test members through the `/lgjoin/` signup flow itself
  (that IS test `auth-new`); use `+tag` Gmail-style addresses so you can run many (e.g. `looth.qa+sub1@…`).
- **Stripe TEST cards:** success `4242 4242 4242 4242` (any future expiry, any CVC/ZIP). For the specific
  items use Stripe's documented test cards — declined `4000 0000 0000 0002`, 3DS-required
  `4000 0027 6000 3184`, and a non-eligible-country card for `sub-regional-block`. (Confirm current numbers
  at stripe.com/docs/testing if a flow misbehaves.)
- **Email:** dev mail is caught by **mailpit**, NOT real inboxes — UI at `https://dev.loothgroup.com/mailpit/`.
  Verify welcome / gift / cancellation emails there (check for the `[TEST]` prefix where the packet calls it out).
- **Security/IDOR + DevTools items** (`mgr-idor`, `mg-idor`, `gb-qty-rules`, `rd-stapled`): replay the POST
  with `curl` against `/billing/v1/*` or the WP REST routes, swapping IDs/fields — assert the 403/400/override.
- **Throttle items** (`auth-throttle-*`): these leave rate-limit state behind — run them LAST in the auth
  section, and note the 15-min / 1-hour cooldowns so you don't false-fail later auth items.
- **Reset between runs:** the TestChecklist has an admin-only **wipe-user** tool (email-keyed; refuses
  user_id=1 and the last admin) to clear a test account's WP+BP+billing rows. Use it to re-run signup/gift
  flows cleanly. Never wipe real members.

## 4. Record results
For each item: `PASS` / `FAIL` / `BUG` / `QUESTION` with the item id (e.g. `mgr-idor`), what you observed vs
`expect`, and exact repro for any FAIL/BUG (URL, payload, response, screenshot path, Stripe/mailpit evidence).
You may file directly through the `/test-checklist/` feedback widget (writes `lg_test_feedback`), but ALSO
write a consolidated report to `/home/ubuntu/projects/docs/stripe-test-run-RESULTS.md` (uncommitted) so it
survives the session. Cross-check the result against `purchase-scenarios.md` — items there marked ⚠️/❌
(no upgrade/downgrade path, gift qty UI-only, promo $0 untested) are KNOWN gaps, not new bugs.

## 5. Constraints & guardrails
- **TEST mode only.** Verify `/billing/v1/config` returns a `pk_test_` key before paying anything. If you
  ever see `pk_live_`, STOP and tell Ian.
- **Dev = fixtures.** Test accounts you create are fine; clean them with the wipe tool. Don't bulk-import.
- **Lane scope:** poller + billing code is yours; header/whoami/nginx and other lanes are read-only →
  coordinator. (See the poller-lane scope note in memory.) Replies/escalations route via Ian.
- **No silent pushes.** Leave any code change uncommitted; present commits + diffstat to Ian before pushing.
- **Cookie gate:** every dev URL is behind the loothdev gate — the chrome-dev-login skill handles it; for
  curl, mint via `/claim?t=<token>` (token in `/etc/nginx/sites-available/dev.loothgroup.com.conf`).
- **Work autonomously through the packet** — batch by section, don't ping Ian per item; surface a single
  consolidated report at the end (plus anything that blocks the whole run).

## 6. Deliverable
`docs/stripe-test-run-RESULTS.md`: a table of all ~50 items × {pass pass / fail} for both passes, every
FAIL/BUG with repro, and a short "ready / not ready" verdict on the Stripe integration for launch.
