# Stripe + Patreon Poller Audit — dev2 sandbox safety, poller verification, email lockdown
**Lane:** `stripe-poller-audit` off `main` · dev2 only · own worktree `~/worktrees/stripe-poller-audit`
**Date:** 2026-06-20 · read-only audit + controlled probes (no live actions, no main writes, no email sent)
**Cross-ref:** `docs/EMAIL-AUDIT.md`, `INCIDENT-A-FIX.md`, `INCIDENT-B-REPORT.md` (email path map — not re-done here)

---

## TL;DR (the three outcomes)
1. **DEV2 STRIPE SANDBOX — already test-mode, two config gaps.** `/srv/lg-stripe-billing/.env` is
   `STRIPE_MODE=test` with `sk_test_`/`pk_test_` keys; the live app serves `pk_test_51LJOi5…` via
   `/billing/v1/config` and a real **test** product catalog via `/billing/v1/products`. **No live
   Stripe key exists anywhere on dev2** (scanned `/srv`, `~/worktrees`, `/var/www/dev` — every
   `sk_live_`/`pk_live_` hit is placeholder/comment text). Blast radius for Stripe build = zero today.
   Two gaps before a clean end-to-end test: (a) every URL in `.env` still points at the **old**
   `dev.loothgroup.com` box, not dev2; (b) the test webhook endpoint must be (re)pointed at dev2 and
   its signing secret confirmed. Cloudflare JS-challenges curl to `/billing` — must confirm Stripe's
   webhook POSTs aren't challenged.
2. **PATREON POLLER — sound design, fixes already landed, one config gap + one operational gap.**
   The ANON-after-onboard gap (memory `project_patreon_onboard_findings`) is **FIXED** in both main
   and the served copy (`wp_set_auth_cookie` + adopt-existing). Tier mapping + Arbiter are coherent.
   Gaps: `lgpo_redirect_uri` in the DB is the **live** domain (the briefed `pre_option` mu-override
   does **not** exist on dev2), and **`DISABLE_WP_CRON=true` with no system cron → the sweep never
   fires automatically** (manual-only).
3. **EMAIL LOCKDOWN — proven gated, and dev2 has no MTA at all.** Every poller/Stripe send path is
   `wp_mail()`; FluentSMTP `simulate_emails=yes` swaps in a `Simulator\Handler` that only logs and
   `return true` — **never transmits** (proved live). Belt: `/usr/sbin/sendmail` does **not exist**
   and postfix/msmtp are inactive, so even a raw-`mail()` bypass can't leave the box. Recommend a
   hard lock: `define('FLUENTMAIL_SIMULATE_EMAILS', true)` in `wp-config.php` so the admin UI can't
   flip simulate off, plus an env guard on the poller.

---

## 1. DEV2 STRIPE SANDBOX

### 1.1 Where keys live (two separate Stripe surfaces — don't conflate)
- **`lg-stripe-billing`** (Slim app, served `/srv/lg-stripe-billing/public`, route `/billing/*`, own
  FPM pool `php8.3-fpm-lg-billing-dev.sock`). Reads keys from **env vars** via
  `src/Adapters/EnvSettingsStore.php`: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`,
  `STRIPE_WEBHOOK_SECRET` (`getSecretKey/getPublishableKey/getWebhookSecret`). Env is loaded by
  `src/App.php:15` (`Dotenv::createImmutable($rootDir)->load()`) from **`/srv/lg-stripe-billing/.env`**.
  This is the **active** Stripe surface on dev2.
- **Poller's own Stripe client** (`lg-patreon-stripe-poller/src/Stripe/{Client,Poller,EventHandler}.php`).
  `Client.php:27` reads `get_option('lgms_stripe_secret_key')` — **EMPTY in `wp_options` on dev2** →
  the poller's Stripe polling is **dormant** (it throws "Stripe secret key not configured" if invoked).
  `lgms_stripe_pages_live=0`. So the poller is Patreon-only on dev2 right now; the billing app owns Stripe.

### 1.2 Current state (verified)
- `/srv/lg-stripe-billing/.env`: `STRIPE_MODE=test`, `STRIPE_SECRET_KEY=sk_test_…`,
  `STRIPE_PUBLISHABLE_KEY=pk_test_…`, `STRIPE_WEBHOOK_SECRET=whsec_…`. `.env.bak-20260602-233115` is
  also test. `APP_DEBUG=true`.
- App live-check (loopback, bypassing Cloudflare): `GET /billing/health` → **200**;
  `GET /billing/v1/config` → `{"publishableKey":"pk_test_51LJOi5Hg6gcIV22b…"}`;
  `GET /billing/v1/products` → real test catalog (e.g. *Looth LITE*, `ref:looth2`, `price_…`,
  `unit_amount_cents:500`, `interval:month`, `trial_days:7`). **Test-mode Stripe connectivity works.**
- Webhook route exists: `config/routes.php:27` `POST /v1/webhook → WebhookController::handle`, which
  **verifies the signature** (`WebhookController.php:41` `constructWebhookEvent($payload,$sig,$secret)`;
  `:42-43` returns **400 `Invalid signature.`** on `SignatureVerificationException`). Secret source =
  `STRIPE_WEBHOOK_SECRET` env. So a Stripe-test webhook must use the secret in `.env`.

### 1.3 Gaps to a clean dev2 sandbox
1. **All `.env` URLs point at the OLD box** (`dev.loothgroup.com`, not dev2):
   `APP_BASE_URL`, `APP_HOME_URL`, `APP_RETURN_SUCCESS_URL` (`/activity/`), `APP_REGIONAL_FAIL_URL`,
   `LGMS_SYNC_URL`, `LGMS_GIFT_MAIL_URL`, `LGMS_CUSTOMER_NOTICE_URL`. Effect: checkout return URL and
   the WP↔billing sync/gift loopbacks resolve to the wrong host → a fake-card checkout would complete
   at Stripe but the **return + member-sync would land on the old box**. Fix = `s/dev\.loothgroup\.com/dev2.loothgroup.com/`
   in those 7 lines (keep `/billing` base path).
2. **Test webhook endpoint** must be created in the **Stripe test dashboard** → URL
   `https://dev2.loothgroup.com/billing/v1/webhook`, then set its signing secret as
   `STRIPE_WEBHOOK_SECRET` in `.env`. (Ian's action — needs Stripe login. Keeper/Ian-held.)
3. **Cloudflare challenge:** curl to `https://dev2.loothgroup.com/billing/*` returns a CF
   "Just a moment…" JS challenge (403). Real browsers solve it; **Stripe's webhook POSTs may be
   challenged too** → verify a test webhook actually reaches the app (Stripe dashboard "Send test
   webhook" → expect 200/400-sig in the app log, not a CF 403). If challenged, add a CF WAF skip rule
   for `/billing/v1/webhook` (and `/billing/v1/return`).

### 1.4 Fake-card proof + flip-back
- **Why a 4242 test checkout is safe:** in test mode Stripe **cannot** charge a real card or email a
  real customer — test keys only touch the Stripe test ledger. Combined with the email lockdown (§3),
  no member-facing mail can leave dev2 regardless.
- **Exercise (after §1.3 fixes, Ian with Stripe test dashboard + a browser past CF):**
  `GET /billing/v1/products` → pick a test `price_…` → `POST /billing/v1/checkout` → complete with
  `4242 4242 4242 4242`, any future expiry/CVC → Stripe fires `checkout.session.completed` /
  `invoice.paid` to the dev2 webhook → `EventHandler` syncs entitlement. Expect: **no live charge**
  (test ledger), **no real email** (sim ON), member role updated via the sync loopback.
- **One-command flip back to clean state** (test data only — wipes nothing live):
  `cp /srv/lg-stripe-billing/.env.bak-20260602-233115 /srv/lg-stripe-billing/.env` (restores known-good
  test env), then in the **Stripe test dashboard** delete the test webhook + clear test customers if
  desired (test-mode "Delete all test data"). Nothing on dev2 holds live state to roll back.

---

## 2. PATREON POLLER

### 2.1 Architecture (verified)
- **Arbiter** (`src/Arbiter.php`) is the **sole writer** of `wp_capabilities` looth1..4 roles
  (`TIER_ROLES = ['looth1','looth2','looth3','looth4']`). `Arbiter::sync($wpUserId)` computes the
  *winning* tier = highest non-null across payment-source rows (`:148-169`, falls back to `looth1`),
  writes the role, syncs BB Profile Type (`:69-74`), and fires the welcome on upgrade-to-paid (`:96`).
- **Tier map:** `wp_options.lgpo_tier_map` maps **15 Patreon tier IDs → looth1..4**
  (e.g. `10455112→looth1` "Free ($0)", `5735635→looth2`, `6401900→looth3`, `24295274→looth4`);
  `lgpo_tier_labels` holds the human labels. `looth1` = free starter, **reported as null** to Arbiter
  and **never** triggers the welcome; `looth2/3/4` = paid.
- **Sweep / reconcile callers of `Arbiter::sync`:** `includes/class-lgpo-sync-engine.php:647`
  (`LGPO_Sync_Engine::run`, the cron sweep), `src/UserLifecycle.php:282`, `src/Sync.php:47`, and
  `src/Wp/AdminRoleCapture.php:74` (fires instantly when an admin edits a user's roles).
- **Cron:** `includes/class-lgpo-sync-cron.php` schedules `LGPO_Sync_Engine::run` via
  `wp_schedule_event` at `lgpo_sync_frequency` (DB = `hourly`; default `daily`). `lgpo_auto_sync_enabled=1`.
- **OAuth connect/callback:** `lg-patreon-onboard.php` — settings page stores `lgpo_client_id` /
  `lgpo_client_secret`; authorize URL built at `:503-524`; callback route `^patreon-callback/?$`
  registered (`:541`), token exchange `:589-645`, identity fetch `:917-980`.

### 2.2 Onboard identity — the known gaps, re-checked
- **ANON-after-onboard is FIXED.** `onboard.php:80-81` calls `wp_set_current_user()` **and**
  `wp_set_auth_cookie($id,true)` (the helper `lgpo_login_user`), so a self-connector is fully logged
  in (lifecycle G1). Both `existing_by_patreon` (`:1084`) and `existing_by_email` (`:1100`) now
  `lgpo_adopt_existing_user()` and reach an "adopted / you're logged in now" terminal **before** any
  welcome — the repeat-connect re-welcome (incident a #1) cannot fire here. The served copy and the
  inline set-password page (`mu-plugins/lgpo-set-password.php:33`) also `wp_set_auth_cookie`. **The
  memory note "onboard leaves them ANON" is now stale — verify-and-close.**
- **SERVED ONBOARD IS AHEAD OF main — flag to keeper.** `diff` of the running
  `/var/www/dev/.../lg-patreon-onboard.php` vs this branch's `main` copy shows the served version:
  (a) for a **new** account sends **no email** — it `wp_safe_redirect('/patreon-password/')` to the
  inline set-password page; **main still does `wp_mail()` welcome+set-password** at `:1174-1190`;
  (b) drops `&fields[campaign]=` from the identity fetch because **"Patreon 400s on an empty
  fieldset"** — main still sends `&fields%5Bcampaign%5D=`, which per the served comment **breaks the
  identity fetch → `?onboarded=fail`**. **If Ian tests OAuth against a freshly-deployed `main`, the
  callback may 400.** Recommend the keeper reconcile served→main (it's the login-poller/`pwpage`
  work that landed on the served plugin after the `e5d466d` reseed but was never committed back).
- **Other known gaps (still open, confirm/fix later):** splits identity from the sweep; orphans rows
  on delete; needs a `/whoami` bridge. A DB reload breaks poller/whoami 4 ways
  (`project_cf_reload_whoami_casualties`): poller deactivated, `lgms_db_*` creds wiped, BB REST gate
  re-armed, bridge gaps — re-check after any reload. **Arbiter re-welcome (incident a #2) is still
  open in `main`** — `WelcomeMailer::sendIfNeeded` only checks the `_lg_welcome_email_sent_at`
  sentinel; the account-age guard is in the `email-audit` worktree (committed, not merged).

### 2.3 Config gaps before OAuth testing
1. **`lgpo_redirect_uri` = `https://loothgroup.com/patreon-callback` (LIVE).** The briefed
   `pre_option_lgpo_redirect_uri` mu-override that forces
   `https://dev2.loothgroup.com/patreon-callback` **does not exist** on dev2 (grepped all of
   `wp-content` + the serve clone — no such filter). So the stored value wins. **Fix:** set
   `lgpo_redirect_uri` to `https://dev2.loothgroup.com/patreon-callback` (poller settings page or
   SQL `UPDATE wp_options`), **or** the keeper adds the mu pre_option filter. Must match the new
   Patreon app's redirect exactly (no trailing slash).
2. **New Patreon OAuth app:** put its `lgpo_client_id` / `lgpo_client_secret` in the poller settings
   page (currently an old app's creds are present). `lgpo_campaign_id=4833198`,
   `lgpo_contact_email=ian.davlin@gmail.com` (alert recipient — gated, see §3).
3. **OPERATIONAL: the sweep never auto-fires on dev2.** `wp-config.php:78` `define('DISABLE_WP_CRON',
   true)` and there is **no system cron** hitting `wp-cron.php` (checked `looth-dev` + `ubuntu`
   crontabs). So despite `lgpo_auto_sync_enabled=1`/`hourly`, `LGPO_Sync_Engine::run` only runs when
   triggered manually (admin "Run Sync Now") — and `wp-cli` is fatal, so the CLI path is out. To
   exercise sweep/reconcile on dev2, trigger it from the admin UI, or (keeper) add a system cron
   `* * * * * curl -s https://dev2…/wp-cron.php?doing_wp_cron`. Last recorded sweep:
   `lgpo_last_sync_results` shows real applied transitions (e.g. "… looth3 → looth2"), so the engine
   works when run.

---

## 3. EMAIL LOCKDOWN (proven)

### 3.1 The gate, proved live
`fluentmail-settings.misc.simulate_emails = yes` (verified in DB; `log_emails=no`). Mechanism:
FluentSMTP's provider factory (`app/Functions/helpers.php:145`) returns a **`Simulator\Handler`**
whenever `simulate_emails=='yes'` **OR** the constant `FLUENTMAIL_SIMULATE_EMAILS` is truthy.
`Simulator\Handler::send()` (`app/Services/Mailer/Providers/Simulator/Handler.php`) only writes a log
row *if logging is on* (it's off → pure no-op) and `return true` — **it never transmits**. FluentSMTP
intercepts every `wp_mail` via the `pre_wp_mail` filter (`helpers.php:263`).

**Live probe (bootstrapped `/var/www/dev/wp-load.php` as `looth-dev`, called `wp_mail()` to a
sentinel address):**
```
simulate_emails option            = yes
FLUENTMAIL_SIMULATE_EMAILS const  = (undefined)
wp_mail() returned                = true        ← handled (simulated), not queued to a transport
MTA /usr/sbin/sendmail exists?    = NO          ← belt: even a bypass can't send
```
(`phpmailer_init` does fire — that's PHPMailer being *configured*; FluentSMTP then swaps the transport
to the Simulator, so configuration ≠ transmission.)

### 3.2 Every poller/Stripe send path is wp_mail (so all gated)
From `EMAIL-AUDIT.md` §1, re-confirmed against this branch — all are `wp_mail()` → Simulator:
- `WelcomeMailer::sendIfNeeded` (`src/Wp/WelcomeMailer.php:53`) + `sendTest` (`:95`)
- Onboard: **main** welcome+set-password (`onboard.php:1186`); **served** = inline page, no mail
- `lgpo_alert_failure` (`onboard.php:797`) → `lgpo_contact_email`; `lgpo_notify_admin` (`:1254`)
- `GiftMailer` (buyer/recipient/dashboard, `src/Wp/GiftMailer.php`)
- `RestController` self-action/gift mails (`:459/:1141/:1793`)
- **Stripe `EventHandler`**: `payment_failed` (`src/Stripe/EventHandler.php:258`), `trial_will_end`
  (`:294`) → customer email
- sync-engine admin summary (`includes/class-lgpo-sync-engine.php:784`)
- **`lg-stripe-billing`**: `WpGiftMailer::sendGiftCodes/sendOneRecipient`, `ReturnHandler`/`WpSync`
  mail — all via the **WP loopback → wp_mail** (`LGMS_*_URL` endpoints), so also gated by the same
  Simulator. (On dev2 those loopbacks currently target the old box — §1.3 — i.e. they don't even
  reach dev2's WP yet.)

### 3.3 Idempotency nuance worth knowing
`EventHandler` **already has** an `lg_processed_events` dedup table (`:46`, `INSERT … ON DUPLICATE KEY
UPDATE dup_count+1`) — newer than EMAIL-AUDIT.md recorded. **But it is observation-only**: the comment
says *"On a duplicate, we still process the event normally — the goal is visibility, not gating."* So
a redelivered `invoice.payment_failed` **still re-sends** the mail (caught today by sim). EMAIL-AUDIT
P1 #4 (make it no-op on replay) still stands.

### 3.4 No poller/Stripe path bypasses wp_mail
Confirmed: the poller and lg-stripe-billing send **only** via `wp_mail` (or the WP loopback, which is
wp_mail). The two known bypasses are **NOT poller/Stripe** and are **out of scope** here (noted for
completeness): Showrunner Apps Script (`GmailApp` from Google — incident b) and the archive-poc
feedback modal (raw PHP `@mail()`). On dev2 even the raw-`mail()` one is inert — **no MTA**.

### 3.5 Recommended belt-and-suspenders hard lock (for the dev period)
Keep `simulate_emails=yes` AND add independent guards so Stripe/onboard testing can never leak even if
someone toggles the UI or a reload perturbs settings:
1. **Code-level kill-switch (strongest, one line):** add to `/var/www/dev/wp-config.php`
   `define('FLUENTMAIL_SIMULATE_EMAILS', true);` — `helpers.php:145` honors it regardless of the DB
   toggle, so the admin UI **cannot** turn real sending on. Survives DB reloads (it's in code, not the
   `wp_options` row that a reload overwrites).
2. **Poller-side env guard:** gate `WelcomeMailer::sendIfNeeded` (and the onboard welcome) behind a
   `LGPO_ALLOW_MEMBER_EMAIL` env/constant defaulting to off on dev2, so the poller refuses member
   mail even if FluentSMTP is misconfigured. (Code change — stays behind the gate; do **not** flip
   simulate off.)
3. **Keep no MTA installed on dev2** — the absent `/usr/sbin/sendmail` is itself a backstop for any
   raw-`mail()` path; don't install postfix/msmtp on dev2 during the dev period.
> Do **not** flip `simulate_emails=no` on dev2. The live re-enable plan (backfill sentinel, merge
> login-poller, Arbiter guard, Showrunner fix, staged flip) lives in `docs/EMAIL-AUDIT.md` §4 and is
> Ian/keeper-held.

---

## 4. Out-of-scope / held by Ian or keeper (referenced, not run)
- Live welcome-sentinel backfill (`backfill-20260620`) and the **SES 6/17 delivery check** —
  Ian/keeper-held (`INCIDENT-A-FIX.md`).
- Creating/pointing the Stripe **test webhook** + confirming its secret — needs Stripe login (Ian).
- Reconciling the **served onboard.php → main** (§2.2) and adding the `lgpo_redirect_uri` override —
  keeper.
- `wp-cli` fataling on dev2 (WP_CLI Runner eval error) — blocks CLI sweeps; fixing it would help but
  is not required for this audit.

## 5. Cross-lane / contract notes for the keeper
- **Served onboard ahead of main** (no-email inline page + Patreon `fields[campaign]` 400 fix) — the
  `lane/login-poller` / `pwpage` work landed on the served plugin but not on `main`. Deploying `main`
  as-is would regress onboard (re-introduce the empty-fieldset 400 + a member welcome email). Sequence
  a served→main reconcile.
- **`pre_option_lgpo_redirect_uri` mu-override is assumed in briefs but absent** — either add it or
  set the option; OAuth callback will fail without a dev2 redirect URI.
- **Poller sweep is manual-only on dev2** (`DISABLE_WP_CRON` + no system cron) — anyone "verifying the
  sweep" must trigger it from the admin UI.
