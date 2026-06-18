# Session handoff — poller lane

> **Currently active task:** membership pages onto shared shell.
> **STANDALONE conversion in flight (2026-05-31)** per §0b — `/membership-guide/`
> is the first slug ported out of the `template_include` mu-plugin into a real
> standalone PHP surface following the events / archive-poc / bb-mirror pattern.
> Files staged at `/home/ubuntu/projects/membership-pages/`; awaiting coordinator
> deploy (DB secret + FPM pool + nginx-snippet install). See
> "2026-05-31 — standalone conversion" below. Remaining: 10 more slugs + welcome
> modal port + P8.
> See [briefing-membership-pages.md](briefing-membership-pages.md) +
> [notes-for-rotated-chat-membership-pages.md](notes-for-rotated-chat-membership-pages.md)
> (tacit knowledge from the prior chat — read after the briefing, before code).
>
> **Still on the lane:** P8 ✅ dormant-mode smoke (2026-06-01, this section
> below); membership-pages mu-plugin files mirrored into `platform/mu-plugins/`
> for version control (2026-06-01). Other 8 money pages = Stripe-A-later
> (deferred, not launch).
>
> **Closed cross-cutting threads:** header name ack ✓, secret file ✓, round-trip purge 204 ✓.

---

## Shipped this lane (most recent first)

| Date | Item | Section anchor |
|---|---|---|
| 2026-06-04 | **nginx single-location WIRED + verified** (Ian "wire it"). Snippet swapped in place (`strangler-membership.conf`), `nginx -t`+reload OK (backup `/tmp/strangler-membership.conf.bak.20260604-002334`). All 12 slugs 200 via router; admin gate correct (anon→stub, manage-subscription→sign-in, join→funnel, no-cookie→403); admin lgjoin→real tier picker; 0 Elementor. sudo-queue `membership-2026-06-03-1` RESOLVED. | "2026-06-04 — nginx wired" |
| 2026-06-04 | **lgjoin + lggift verbatim-ported.** Full ports of `Shortcodes::join()` + `redeemGift()` on the router stack; vendored `web/lg-shortcodes.css`. Slim billing API live in TEST mode (`pk_test_`, 2 tiers). | "lgjoin verbatim port" / "lggift verbatim port" |
| 2026-06-03 | **Single-router milestone.** `web/router.php` (slug→{file,visibility} registry + central admin gate) + nginx rewritten to ONE location → router.php (no birds-nest) + 5 scaffold surfaces (lgjoin, lggift-buy, lggift, my-gifts, test-checklist) so EVERY menu item lands on a real standalone surface. Uncommitted for review. | "2026-06-03 — single-router milestone" |
| 2026-06-01 | **P8 ✅ — dormant-mode smoke passed.** Code audit + filter-mocked empty-key in-process run + HTTP smoke. All Stripe-touching paths guarded; hot path never instantiates StripeClient. | "2026-06-01 — P8 dormant-mode" |
| 2026-06-01 | **mu-plugin mirror.** lg-membership-chrome.php + lg-membership-chrome/{template,stripe-panel-template}.php deployed → repo at `platform/mu-plugins/`. Byte-identical with deployed (incl. clickjacking headers). | "2026-06-01 — mu-plugin mirror" |
| 2026-06-01 | **/manage-subscription/ shipped** (commit f7ca461 coordinator-side). Read-only Patreon + admin-gated Stripe iframe to `/__lg-stripe-panel/` (clickjacking headers verified shipping). | "2026-06-01 — Standalone /manage-subscription/" |
| 2026-05-31 | **Standalone /membership-guide/** — first slug converted out of `template_include` into a true standalone PHP surface (no WP boot) per §0b. Pattern parity with events. Files in `/home/ubuntu/projects/membership-pages/`. Awaiting coordinator deploy (DB secret + FPM pool + nginx snippet). | "2026-05-31 — standalone conversion" |
| 2026-05-29 | **Membership-pages PoC (template_include — superseded by standalone above)** — `/membership-guide/` renders on shared `/srv/lg-shared/` chrome (anon + member), BB theme nav bypassed via a `template_include` mu-plugin. mu-plugin remains installed as the per-slug fallback until standalone is verified for that slug. | "2026-05-29 — membership-pages PoC" |
| 2026-05-28 | Round-trip purge live; PurgeNotifier supports loopback + Host override; 204 verified end-to-end via Arbiter | "Round-trip purge SHIPPED" |
| 2026-05-28 | Arbiter stripe-source coexistence guard (mirrors LGPO's); uid=1805 no longer downgraded | "Arbiter stripe guard" |
| 2026-05-28 | Patreon adapter (P2) — `PatreonSourceReader` + `RoleSourceWriter::readAllForUser` merge; provenance now `paid` for patreon users | "Patreon adapter shipped (P2)" |
| 2026-05-28 | `LG_PROFILE_APP_URL` constant (P4) — `PurgeNotifier` reads base from wp-config define | "P4 shipped" |
| 2026-05-27 | `GET /wp-json/looth-internal/v1/user-context/{id}` endpoint; `looth_tier_changed` action; `PurgeNotifier` first cut; secret file `/etc/lg-internal-secret`; nginx exempt | "user-context + action + purge SHIPPED" |
| 2026-05-17 | lg-stripe checklist ~75+ items verified, 16 code/config changes (mailpit, CDP bridge, throttles, welcome email merge, timestamp-poller rewrite, looth1 sticky bypass, Starter BB type, etc.) | "Code shipped this session" (original section) |

## Outstanding within lane

- **Membership pages** — `/membership-guide/` ✅, `/manage-subscription/` ✅ deployed. Other 8 slugs (`/lgjoin/`, `/lggift-buy/`, `/lggift/`, `/my-gifts/`, `/affiliate-earnings/`, `/welcome/`, `/regional-pricing-not-available/`, `/request-refund/`, `/test-checklist/`) = Stripe-A-later (deferred, not launch).

## Outstanding from earlier sessions (still flagged, not blocking)

- Fluent SMTP stores AWS SES access key plaintext in `wp_options`
- `subscriber` role has author-level posting caps (security)
- `customer` role residue from old buddyforms flow
- `bp_read` asymmetry: subscriber yes, looth1-4 no

---

## 2026-06-03 — single-router milestone

Per `relay-stripe-pages-standalone-and-shell.md` (2026-06-03) FIRST MILESTONE:
router + ONE nginx location + 5 scaffolds so every account-menu item lands on a
real standalone surface. Done; verbatim body ports come next.

### What shipped (all uncommitted, in `/home/ubuntu/projects/membership-pages/`)

| File | Change |
|---|---|
| `web/router.php` | **New.** Single front controller. `LG_MS_PAGES` registry: slug → `[file, visibility]` for all 13 surfaces (12 menu + the `/join/` funnel). Resolves slug from `LG_MS_SLUG` fastcgi param (REQUEST_URI fallback), looks it up, builds `$ctx = lg_membership_header_ctx('')`, applies `lg_membership_admin_gate_or_exit()` when `visibility==='admin'`, then `include`s the page file. 404 on unknown slug, 500 on missing file. Mirrors archive-poc's `render.php`. |
| `web/lgjoin.php`, `web/lggift-buy.php`, `web/lggift.php`, `web/my-gifts.php`, `web/test-checklist.php` | **New scaffolds.** Each is a self-contained admin-gated front controller (config + whoami + shared header/footer + `_admin-gate`) rendering the shared shell + a "verbatim port pending" placeholder that names the exact source method. |
| `nginx-snippet.conf` | **Rewritten.** Was per-slug blocks; now ONE assets mount (`^~ /membership-pages/`, PHP-denied) + ONE regex location matching all 13 slugs → `router.php` with `LG_MS_SLUG $1` + `QUERY_STRING $args`. Folds every former per-slug block into the single location. |

### Visibility model (router registry)

- `admin` — `manage_options`-only pre-launch: membership-guide (was `always`), connect-your-patreon, affiliate-earnings, request-refund, welcome, regional-pricing-not-available, lgjoin, lggift-buy, lggift, my-gifts, test-checklist.
- `member` — manage-subscription only (the member-visible exception; no admin gate).
- `public` — join (the Patreon funnel; not one of the 12).

The router gate is **authoritative**; existing page files that also call `lg_membership_admin_gate_or_exit()` keep it as harmless defense-in-depth.

### Verbatim-port source map (for the next pass)

| slug | shortcode | source method |
|---|---|---|
| lgjoin | `lg_join` | `Shortcodes::join()` — src/Wp/Shortcodes.php:2822 (checkout → `/billing/v1/checkout`, TEST mode — biggest) |
| lggift-buy | `lg_gift` | `Shortcodes::gift()` — src/Wp/Shortcodes.php:42 |
| lggift | `lg_redeem_gift` | `Shortcodes::redeemGift()` — src/Wp/Shortcodes.php:4041 |
| my-gifts | `lg_my_gifts` | `Shortcodes::myGifts()` — src/Wp/Shortcodes.php:5252 (REST mutations need `lg_membership_rest_nonce()`) |
| test-checklist | `lg_test_checklist` | `TestChecklist::render()` — src/Wp/TestChecklist.php:655 (no Stripe/REST — lowest-risk) |

### Smoke (CLI — `php -l` clean on all 6 new files)

- bad slug → `404 no such surface` ✓
- `slug=test-checklist`, anon → admin-gate stub (`lg-gate`), not the page ✓
- `slug=lgjoin`, whoami mocked admin → full shared shell (`lg-chrome`) + scaffold body ✓
- `slug=request-refund` (existing built page) via router, admin → renders, no fatal ✓
- `slug=manage-subscription` (member-visible), anon → renders sign-in view, NOT the admin stub (gate correctly bypassed) ✓

Full HTTP smoke through nginx awaits the coordinator applying sudo-queue
`membership-2026-06-03-1` (snippet `cp` + reload — I don't edit `/etc/nginx`).
Verify loop is in that request's `why:`. Stale `membership-2026-06-02-4` marked
SUPERSEDED (same file/command, old per-slug content).

### lgjoin verbatim port — DONE (2026-06-04)

`web/lgjoin.php` is now the full verbatim port of `Shortcodes::join()` (was a
scaffold). Body + JS copied as-is; only the chrome + WP server helpers swapped:
- `wp_get_current_user()` → `wordpress_logged_in_*` cookie → `wp_users` lookup
- `home_url()`/`rest_url()` → `lg_ms_home()` (`https://HOST/…`)
- `lookupActiveSub()` → same SQL vs the poller DB (active sub → 302 `/manage-subscription/`)
- `esc_html`/`esc_attr`/`esc_js`/`wp_json_encode` → `lg_membership_h` / `lg_ms_esc_js` / `json_encode`

Browser flow unchanged — talks to the Slim billing API
(`/billing/v1/{products,config,checkout,affiliate-click,return}`) + WP REST
`/wp-json/lg-member-sync/v1/auth` directly. Styles: vendored
`web/lg-shortcodes.css` (verbatim from the plugin; 33KB, self-contained).

**Verified:** `php -l` clean; router render (admin mock) → shared header +
tier mount + correct `ENDPOINTS` (`/billing/v1/*`) + Stripe basil, no PHP
errors (82KB). Slim API live in **TEST mode**: `/billing/v1/config` →
`pk_test_…`; `/billing/v1/products` → 2 tiers (looth2 LITE, looth3 PRO, 3
prices each). Full browser end-to-end (load tiers → checkout) is blocked on
the nginx single-location apply (`membership-2026-06-03-1`).

### lggift verbatim port — DONE (2026-06-04)

`web/lggift.php` is the full verbatim port of `Shortcodes::redeemGift()`
(Shortcodes.php:4041). Server helpers swapped (cookie→wp_users, poller-DB
active-sub + `gift_codes.recipient_email` stapled-email lookups, `get_user_by`
→ wp_users SELECT, wrong-user early-return rendered in the shell). Redeem
POSTs to Slim `/billing/v1/redeem`; auth via WP REST `/…/auth` — JS copied
verbatim. **Verified:** `php -l` clean; router render (admin mock) → shared
header + redeem form + `ENDPOINT`=`/billing/v1/redeem` + correct `AUTH_URL`,
no PHP errors. (Caught + fixed a `*/`-in-docblock parse bug.)

### 2026-06-04 — nginx WIRED + my-gifts & lggift-buy ported + DB-secret fix

**nginx single-location is LIVE** (Ian: "wire it"). Applied
`membership-2026-06-03-1` myself (sysadmin): backup
`/tmp/strangler-membership.conf.bak.20260604-002334`, `nginx -t` + reload OK.
All 12 slugs route through `router.php`. Smoke: anon admin-slugs → gate stub,
manage-subscription → sign-in, join → funnel, no-cookie → 403, zero Elementor
(true standalone).

**my-gifts.php** — full verbatim port of `Shortcodes::myGifts()` (self-styled).
Reads gift codes from poller DB; mutations → `/…/me/gift-{send,resend,reassign,void}`
with the `wp_rest` nonce from the bridge (`lg_membership_rest_nonce`). Live admin
render confirmed (dashboard + real nonce in CFG). Cap note: gate is the router's
manage_options for now; add a `manage_gift_codes` signal when it goes member-visible.

**lggift-buy.php** — full verbatim port of `Shortcodes::gift()` (~1,890-line body
included BYTE-FOR-BYTE; WP funcs shimmed: esc_*/home_url/rest_url/wp_login_url/
wp_lostpassword_url/get_permalink/is_user_logged_in/wp_json_encode). Self-styled
(inline <style>). Live admin render confirmed (gift panel + ENDPOINTS → /billing/v1/*).

**DB-secret fix (DB-reload casualty).** `/etc/lg-membership-db` still pointed at
the stale `looth_dev`; wp-config (and `/etc/lg-events-db`) run on `looth_import`.
So every membership surface was reading the wrong WP DB → poller-DB creds
(`lgms_db_*`) unresolved → my-gifts/manage-subscription/lggift/lgjoin data broken.
Fixed `DB_NAME=looth_dev→looth_import` (backup `/tmp/lg-membership-db.bak.*`).
After the fix: my-gifts dashboard loads; lgjoin `lookupActiveSub` now works →
active-sub admin (iandavlin) correctly 302s to /manage-subscription/, sub-less
admin (gerryhayes) gets the tier picker. **Cutover gotcha: the membership secret
must track wp-config's DB_NAME after any reload.**

Ports done: lgjoin, lggift, my-gifts, lggift-buy — all verified live for admin.

### test-checklist — DONE (2026-06-04, action-nonce bridge built)

All 5 surfaces are now ported. test-checklist was the hardest (most WP-coupled)
and is done via two pieces:

**1. Action-nonce bridge extension (WP-side).** The standalone surfaces could only
mint a `wp_rest` nonce; test-checklist's JS drives `admin-ajax.php` with
`lgms_test_feedback` / `lgms_test_wipe` action nonces. Extended the rest-nonce
route to accept `?action=<name>` (whitelist: wp_rest, lgms_test_feedback,
lgms_test_wipe; the lgms_test_* ones require manage_options):
  - `platform/mu-plugins/lg-membership-chrome.php` — edited the
    `looth/v1/rest-nonce` callback; deployed to
    `/var/www/dev/wp-content/mu-plugins/` (backup `/tmp/lg-membership-chrome.php.bak.*`,
    chown looth-dev:loothdevs, byte-identical to repo).
  - `membership-pages/lib/whoami.php` — `lg_membership_rest_nonce($action='')`
    now takes an action + caches per-action (backward-compatible; my-gifts'
    no-arg call still mints wp_rest).

**2. test-checklist.php port.** `TestChecklist::render()` ported as a vendored
class `LgMsTestChecklist` so the body's `self::SECTIONS/SEVERITY/fetchFeedback/
itemLabel/linkifyText` resolve unchanged — SECTIONS registry + linkifyText +
render body copied byte-for-byte; only `fetchFeedback()`'s DB handle swapped to
`lg_membership_poller_db()`. WP funcs the body calls shimmed (esc_*/wp_json_encode/
current_user_can/get_the_ID/get_post_field/admin_url/wp_create_nonce→bridge/
checked/home_url/wp_get_current_user). AJAX submit/status/wipe still POST to
WP `admin-ajax.php` (handlers unchanged, server-side).

**Verified live (admin):** renders 13 sections / 91 items / 16 feedback rows,
complete `</html>`, real `AJAX_URL`/`FB_NONCE`/`WIPE_NONCE`/`ADMIN_EMAIL`.
End-to-end nonce proof: bridged `lgms_test_feedback` nonce → admin-ajax
`feedback_status` passes `check_ajax_referer` (handler runs); bogus nonce → 403.

### 2026-06-04 — Connect-your-Patreon button fix (reload casualty) + lgjoin perf

- **Connect-your-Patreon dumped to login.** `/join/`'s CTA → `/patreon-connect`
  → BuddyBoss private-network gate 302'd anon to wp-login (`bp-auth=1&action=bpnoaccess`).
  The poller's OAuth handler (`lgpo_handle_connect`, `/patreon-connect/`) is built +
  registered — it was just bounced before running because `/patreon-connect/` had
  dropped from the BB anon-allowlist (`wp_options.bp-enable-private-network-public-content`)
  on the DB reload (the §3n handoff added it; reload wiped it). Re-appended
  `/patreon-connect/` + cache flush → now 302s straight to patreon.com/oauth2/authorize.
  Also pointed `join.php`'s `$patreon_connect` at the trailing-slash URL to skip a 301 hop.
- **`$become_patron` link fixed** — was hardcoded to a 404 slug
  (`patreon.com/loothgroup/membership`); now reads the `lgpo_patreon_link` option
  (= `https://www.patreon.com/cw/theloothgroup/membership`), same source as
  manage-subscription. (Open Q to Ian: keep `/membership` deep-link or bare `/cw/theloothgroup`.)
- **lgjoin slow plans** — `loadProducts()` was awaiting geolocation (`/cdn-cgi/trace`
  302 + external ipapi.co, ~0.7–2.5s) before fetching the 46ms products call. Now
  renders plans immediately; country detection runs in the background and only
  re-fetches if a region with actual regional pricing turns up.

### Membership-pages DB-reload casualty checklist (re-run after any dev reload)

1. `/etc/lg-membership-db` `DB_NAME` must match wp-config (currently `looth_import`,
   not stale `looth_dev`) — else all surfaces read the wrong WP DB + poller creds fail.
2. `wp_options.bp-enable-private-network-public-content` must include `/patreon-connect/`
   (and `/connect-your-patreon/`, `/patreon-callback/`, `/lgjoin/`) — else anon
   Connect-your-Patreon bounces to wp-login.

### 2026-06-04 — Patreon-onboard findings → HANDED TO COORDINATOR (do not fix in-lane)

Ian connected a real Patreon user (Mikelle Davlin) on dev; account created but never
logged in. Investigated (no fixes applied per Ian's "hand off to coord"). Temp audit
mu-plugin `wp-content/mu-plugins/lg-user-audit.php` (+ log `wp-content/lg-user-audit.log`)
left in place — **remove after coord review.** Findings (all poller-plugin / cross-cutting):

1. **OAuth callback never logs the user in.** `lgpo_handle_callback`
   (lg-patreon-onboard.php:974–1009) does `wp_insert_user` → `get_password_reset_key`
   → emails a set-password link → `lgpo_terminal('success')`. No `wp_set_auth_cookie`
   anywhere. So a freshly-connected member lands ANON and must set a password via
   email + sign in manually. DECISION for coord: auto-login on OAuth (identity already
   proven → add `wp_set_auth_cookie($uid,true)` on the success + already_onboarded
   paths) vs keep the email flow.
2. **Onboard ↔ sweep identity split.** The callback never writes/reconciles
   `lg_patreon_members` (sweep-owned). Mikelle's Patreon id 13299272 still maps there to
   a GHOST `wp_user_id=1802` (stale from a pre-reload sweep, synced 5/7) while the real
   OAuth user was a different ID. Onboard should upsert/repoint `lg_patreon_members.wp_user_id`.
3. **Raw WP user-delete is NOT robust.** Confirmed via audit log: dash delete fires
   `wp_delete_user` cleanly, BUT it orphans `lg_patreon_members` + `lg_role_sources`
   rows and leaves the email-keyed `wp_user_bridge` dangling. Clean teardown = the
   test-checklist email-wipe (`TestChecklist::handleAjaxWipeEmail`/`wipeQueries`).
   Consider a `deleted_user` hook to clean poller rows, or document the wipe tool as canonical.
4. **whoami bridge for new OAuth users.** Onboard-created users need a `wp_user_bridge`
   row or `/whoami` returns anon (logged-in-but-walled). Confirm realtime bridge-create
   covers OAuth onboards (profile-app lane).
5. The member-sweep has NO `wp_insert_user` path (`compare_member` skips `skipped_no_wp`),
   so it never recreates a deleted user — ruled out as a cause.

These are poller-lane / profile-app changes, not membership-pages standalone. Routing to
coordinator for ownership + the auto-login decision.

### All 5 ports complete

lgjoin, lggift, my-gifts, lggift-buy, test-checklist — all verbatim-ported,
served by the single router, verified live for admin. Stripe-touching flows wire
to the Slim billing API in TEST mode. Remaining cross-cutting: nothing blocking;
all changes uncommitted for review (nginx + DB-secret + mu-plugin applied on dev,
backups in /tmp).

---

# (Original handoff begins below — preserved for context.)

# Session handoff — checklist run for lg-stripe-billing / lg-patreon-stripe-poller

Written by the previous Claude session on 2026-05-17 ~19:00 UTC. Pick up
from here. The user ran out of patience for context, not for work.

## What this whole session has been

Working through `[lg_test_checklist]` (rendered at /test-checklist/, source
at [TestChecklist.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/TestChecklist.php))
end-to-end. ~75+ items verified across cron/security/auth/manage-sub/
gift/refund/MG/admin/roles. 16 code/config changes shipped to fix things
found while testing. Decisions parked in
[PROD-CUTOVER.md](/srv/lg-stripe-billing/PROD-CUTOVER.md) under "Decisions
to be finalized" section.

## Infra you need to know is running

| Thing | Where | Notes |
|---|---|---|
| **Mailpit** SMTP catcher | systemd unit `mailpit.service`, UI at https://dev.loothgroup.com/mailpit/ (cookie-gated) | Started this session. /etc/msmtprc routes www-data sendmail to it. Fluent SMTP is the active mailer — Mailpit only catches if Fluent is deactivated first. |
| **Chromium-in-browser** | docker container `chromium`, KasmVNC UI at https://browser.dev.loothgroup.com/ (cookie-gated) | Wayland — xdotool won't work. CDP is the way. |
| **CDP bridge** | nsenter+socat from host port 9222 → container's localhost:9222 | **Dies on container restart.** Restart cmd: see "Restoring CDP" below. |
| **CDP driver script** | [cdp.py](/home/ubuntu/projects/cdp.py) | Auto-handles native JS dialogs (confirm/alert) since v2. 30s WS timeout. |

### Restoring CDP after a container restart

```bash
sudo pkill -f 'socat.*9222' 2>/dev/null
CPID=$(sudo docker inspect -f '{{.State.Pid}}' chromium)
CIP=$(sudo docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' chromium)
sudo nsenter -t $CPID -n socat TCP-LISTEN:9222,bind=$CIP,reuseaddr,fork TCP:127.0.0.1:9222 >>/tmp/cdp-bridge.log 2>&1 &
sleep 2
curl -sS http://127.0.0.1:9222/json/version  # should return Chrome/...
```

### If chromium itself died (closing the only tab kills it)

```bash
sudo docker rm -f chromium
sudo docker run -d --name chromium --restart unless-stopped \
  --security-opt seccomp=unconfined --shm-size="2gb" \
  -e PUID=1000 -e PGID=1000 -e TZ=America/New_York \
  -e TITLE="Loothdev Browser" \
  -e CHROME_CLI="--remote-debugging-port=9222 --remote-debugging-address=0.0.0.0 --remote-allow-origins=*" \
  -v /srv/browser-container/config:/config \
  -p 127.0.0.1:3010:3000 -p 127.0.0.1:9222:9222 \
  lscr.io/linuxserver/chromium:latest
sleep 10
# then re-run the bridge above
```

## Test users (all alive in DB, passwords known)

| Login | Pass | Role(s) | Purpose |
|---|---|---|---|
| `claude_admin` | `ClaudeAdmin1779036333!` | administrator | wp-admin work |
| `qa_lite_1779037646` (id 1906) | `QaTestPw1!` | subscriber, bbp_participant, looth3 (was LITE→PRO switched, sub canceled+refunded) | manage-sub tests |
| `qa_giftbuyer_1779043789` (id 1914) | `QaTestPw1!` | bbp_participant, looth1, type=starter | gift dashboard tests |
| `qa_pastdue_1779044650` (id 1915) | `QaTestPw1!` | looth2 with sub_qa_pastdue_… in past_due | past-due 409 test |
| `qa_u_1779034315` (id 1903) | `QaPassword1!` | subscriber | auth throttle tests |

Customer IDs in lg_membership.customers: 117 (qa_lite, blocked), 126 (qa_giftbuyer), 127 (qa_pastdue). Fixture gift codes for buyer 126: `QAFIXT*` (7 codes in mixed states).

## Code shipped this session (file backups under /tmp/*.bak)

1. **IP throttle counts failures only** — [RestController.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/RestController.php) optimistic pre-bump + success-undo
2. **Per-email throttle counts validation failures** — same file, same pattern
3. **`customer.subscription.trial_will_end` handled** — [EventHandler.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Stripe/EventHandler.php) new `onTrialWillEnd`
4. **Existing-account modal opens clean** — [Shortcodes.php:~3389](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Shortcodes.php) dropped inline "Incorrect password." string
5. **Welcome modal CTAs renamed** — [Plugin.php:555-556](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Plugin.php) Take the tour → Member Guide; Jump to the feed → See What's New
6. **Welcome email merge** — slim template at [welcome-membership.html.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/templates/email/welcome-membership.html.php) (was 407 lines, now ~60); password-reset link folded in from legacy [UserProvisioner::sendWelcomeEmail](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/UserProvisioner.php) which is now a no-op
7. **Stripe poller rewritten timestamp-based** — [Poller.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Stripe/Poller.php) fixes same-second cursor leapfrog; 60s overlap window + lg_processed_events dedup
8. **Personal one-time membership purchases removed** — [Shortcodes.php loadProducts](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Shortcodes.php) filters out `price.type==='one_time'` for /lgjoin/ picker; [CheckoutController.php](/srv/lg-stripe-billing/src/Http/Controllers/CheckoutController.php) returns 400 on `gift=false + one_time`. Gift path unchanged.
9. **Disabled mu-plugin file deleted** — `dev-admin-only-login.php.disabled`
10. **`customer` removed from `GIFT_CAPABLE_ROLES`** — [Plugin.php:35](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Plugin.php) + cap revoked from existing customer role
11. **Looth1 Arbiter bypass (sticky)** — [Arbiter.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Arbiter.php) skips looth1 in the role-removal loop. Means UserProvisioner's `role=looth1` is permanent.
12. **BB Starter Profile Type** — created via wp-admin (post 69093, slug `starter`), with both hide flags. Requires Profile Types component which was enabled this session.
13. **UserProvisioner tags new looth1 users as Starter** — [UserProvisioner.php:50-58](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/UserProvisioner.php)
14. **Arbiter syncs Starter type to winning tier** — [Arbiter.php:50-62](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Arbiter.php) looth1/null → starter; looth2+ → cleared
15. **Mailpit + nginx + msmtp + CDP bridge** (infra, see top)
16. **PROD-CUTOVER.md Decisions section** seeded over three batches

## Gotchas you'll trip over otherwise

- **Wayland blocks xdotool** — use CDP exclusively for UI driving. Native `confirm()` dialogs would have wedged chromium; cdp.py now auto-handles them but if you fork a new driver, replicate the `Page.javascriptDialog` handler.
- **Closing the last chromium tab kills the container** (no UI = exit). Always have at least one tab open. If you must close, do `/json/new?<url>` immediately.
- **Stripe poller can leapfrog same-second events** — was fixed this session. If you see "skip" entries for new events but no `invoice.paid` processing, check tick.log AND verify cursor format is unix-timestamp not `evt_…`.
- **Email routing is split**: www-data sendmail → /etc/msmtprc → Mailpit. `ubuntu` sendmail → /home/ubuntu/.msmtprc → Gmail directly (this is how "email me X" still reaches the user). If you toggle Fluent SMTP off to capture wp_mail, **flip it back on when done** or app emails leak to test recipients via legacy SES.
- **Fluent SMTP is currently ACTIVE**. Welcome / refund / gift emails go to real SES → real inboxes. Use `+qa-*` Gmail aliases for any test recipients you don't want spam-flagged.
- **Looth1 is sticky now**. Will never be removed by Arbiter. Manual `wp user remove-role` if needed.
- **Looth4 is protected**. Arbiter short-circuits with `reason="looth4 protected, skipped"` — no role sync runs at all for looth4 users.
- **Customer 117 (qa_lite) is BLOCKED** (admin Cancel & Refund auto-blocks). If you want to re-test with that user you'd need to clear `customers.blocked_at` first.
- **Test gift code IDs are in DB**: see `lg_membership.gift_codes WHERE purchased_by=126`. One was voided (QAFIXTUNS01), one reassigned (QAFIXTSNT01), QAFIXTUNS02 was sent. The fixtures are usable for re-runs.

## Bugs/findings flagged but NOT fixed

1. **Fluent SMTP stores AWS SES access key plaintext in `wp_options.fluentmail-settings`** — secret key encrypted, access key in plaintext.
2. **`subscriber` role has author-level posting caps** (`create_posts`, `edit_posts`, `publish_posts`, `level_0`, etc.) — residue from earlier "set up author privileges" work. Anyone on the default subscriber role can publish posts. Real security concern. Worth stripping subscriber down to bare `read`.
3. **`customer` role has 4 leftover buddyforms caps** even after we revoked `manage_gift_codes`. Probably safe to delete the entire customer role from the install — nothing in the new flow uses it.
4. **`bp_read` asymmetry**: subscriber has it, looth1-4 don't. Looth users may behave oddly in BP-gated areas as a result.
5. **`gift-qty-server` checklist item is stale** — current code allows `qty=1` with explicit `gift=true`. Checklist text needs updating.
6. **Admin "Cancel & Refund" always auto-blocks** the customer (extra `admin_action_log` row `auto_block_after_refund`). Decision queued: configurable per-case vs always-on. See PROD-CUTOVER.

## Queue still pending

Lower priority since most of the meaty stuff is done:

- **Affiliate inline-edit `Save rates`** — my form submit didn't update the DB; needs nonce/affiliate-id handling. Edit panel display is verified.
- **Cancel-immediate** — basically identical to cancel-period-end (passed). Worth a 2-min round-trip on a fresh sub for completeness.
- **Real-inbox deliverability** items (em-welcome-gmail / outlook / apple-mail, em-refund-admin reply-to in real inbox, em-payment-failed) — needs **user eyeballs on real Gmail/Outlook/Apple inboxes**. Don't waste cycles trying to verify these programmatically.
- **MG sub-items** — recurring shows / events list rendering details, elder bio page links (mostly verified, a few selectors could be tightened).
- **gift-qty-server** — rewrite the checklist item text rather than the code (see flagged finding #5).
- **Past-due test** is fully done; cancel-immediate is the only ms-* item not run.
- **looth1/2/3 gated post browsing** — the user added this to the queue but then asked to pause because they're "going to cook up a new posting mechanism anyway." Skip unless asked.

## Pending decisions in PROD-CUTOVER.md

See "Decisions to be finalized" section near bottom of [PROD-CUTOVER.md](/srv/lg-stripe-billing/PROD-CUTOVER.md). Currently includes:

- Regional pricing country → region_tag map (boilerplate started by previous Claude/work, needs sign-off)
- Discount scale per tier confirmation
- One-time personal-membership drop (decided: dropped; code shipped)
- Cancel-only-PM enforcement UX
- Trial reminder copy pass
- Welcome email content scope (slim template OK?)
- Admin Cancel & Refund auto-block policy
- Looth1 sticky bypass implications
- BB Starter Profile Type rationale

## How the user collaborates

- Fast feedback, doesn't over-spec. Trusts you to pick reasonable defaults and surface tradeoffs.
- Likes terse status updates with concrete evidence (DB rows, exact error strings, file:line refs).
- Will tap in when needed ("you can call me in to check or push or fill out as needed").
- Prefers driving the actual UI in the shared Chromium for verifiability when feasible.
- Don't over-narrate — say what changed and what's next. They read diffs.
- Asks `?` when they don't get something — explain the model, not just the symptom.

## To resume

1. Check infra:
   ```bash
   sudo systemctl status mailpit --no-pager | head
   sudo docker ps --filter name=chromium --format '{{.Status}}'
   curl -sS http://127.0.0.1:9222/json/version | head -3
   ```
2. If CDP probe fails, see "Restoring CDP" above.
3. Skim the queue, ask the user which thread to pull, then push.

---

## 2026-05-27 — Coordinator briefing absorbed (addendum, not a rotation)

Briefing v2 at `docs/briefing-stripe-poller.md` read (revised mid-session
— the v2 answered prior clarification asks). Coordinator's session state
at `docs/STRANGLER-SESSION-HANDOFF.md` also read.

### Positions back to coordinator (Ian to route)

**1. Endpoint shape — ack `/wp-json/looth-internal/v1/user-context/{id}` returning `{tier, provenance, capabilities}`.** Briefing v2 settled the prior clarifications: looth2→lite, looth3→pro, looth4→pro; provenance enum `paid|comp|lapsed|new`; capabilities computed via `user_can($uid, $cap)` (so whoever owns each cap-grant remains authoritative — not the poller's call). No changes requested.

**2. Shared-secret pattern — mirror archive-poc, with one variable rename.**

Archive-poc today (`/home/ubuntu/projects/archive-poc/api/v0/_config.php:86-97`):
- **Secret file:** `/etc/lg-archive-poc-secret` (outside source, deploy-provisioned, root-readable)
- **PHP constant:** `LG_ARCHIVE_POC_CONFIG_SECRET` (loaded by archive-poc's `config.php`)
- **Header:** `X-LG-Config-Secret` (purpose-named, not generic)
- **Verify:** `hash_equals()` constant-time compare

Proposed for the symmetrical poller↔profile-app channel (one secret, both directions):
- **Secret file:** `/etc/lg-internal-secret` (single shared key, used by both ends)
- **PHP constant** (poller side): `LG_INTERNAL_SECRET`
- **Header:** `X-LG-Internal-Auth` — the briefing v2 already uses `X-Looth-Internal-Auth`; suggest renaming to `X-LG-Internal-Auth` so all internal-channel headers share the `X-LG-` prefix that archive-poc established. Cosmetic only — flag for coordinator to choose.

**3. Arbiter purge hook — safe under the new timestamp-based poller; recommend a centralizing action.**

- The 60s overlap + `lg_processed_events` dedup means Arbiter may be *invoked* twice for the same event, but `Arbiter::apply()` already short-circuits when no role change is computed → no spurious purges.
- **Burst writes during a poll tick (the briefing's explicit concern):** a poll tick can apply N role changes back-to-back across distinct users; each fires one purge. That's N fire-and-forget HTTP POSTs per tick — fine for profile-app (idempotent), fine for the poller (non-blocking). The only real risk is if profile-app is *down*: requests stack up in PHP-FPM workers. Mitigation = strict 1s timeout + `blocking=false`. No retry queue needed.
- **Recommendation:** centralize via a new WP action `do_action('looth_tier_changed', $user_id, $old_role, $new_role, $provenance)` fired by every writer (Arbiter, UserProvisioner signup grant, admin role edits, refund/cancel paths). Purge subscribes to that single action. Otherwise we'll miss invalidations on non-Arbiter writes.
- Transport: `wp_remote_post` with `blocking=false`, 1s timeout, no retry.

**4. Post-cutover poller-shim direction (briefing §3) — agree.** Pulling Stripe keys out of the WP attack surface is the right move. Not blocking cutover. Roadmap item; will not start until checklist work and tier-lookup endpoint are both green.

### Poller queue — visibility for coordinator

What's still owed within the poller's lane (informational, so coordinator can predict when this lane has bandwidth for cutover work):

| Item | Size | Blocking cutover? |
|---|---|---|
| Affiliate inline-edit `Save rates` (nonce/affiliate-id) | ~30 min | No |
| Cancel-immediate verification round-trip | ~5 min | No |
| `gift-qty-server` checklist text rewrite (no code) | ~5 min | No |
| Real-inbox deliverability (Gmail/Outlook/Apple) | needs Ian's eyeballs | Yes |
| MG sub-item selector tightening | ~15 min | No |
| **Tier-lookup endpoint build** (`/user-context/{id}`) | ~2 hours once clarifications land | **Yes** |
| **`looth_tier_changed` action + purge hook** | ~1 hour | **Yes** |

Two items in the right column are the only ones gating profile-app cutover from this lane. Both ready to start once looth2/3 mapping + `edit_archive_poc` rule are answered.

### Flagged findings — still unfixed, surfaced to coordinator

(From the 2026-05-17 list, still open; no new ones discovered today.)
- Fluent SMTP stores AWS SES access key plaintext in wp_options
- `subscriber` role has author-level posting caps (security)
- `customer` role residue from old buddyforms flow
- `bp_read` asymmetry: subscriber yes, looth1-4 no

None are cutover blockers but the subscriber-role one is a real
security finding worth scheduling.

### Open design decision — coordinator please weigh in

Building `/user-context/{id}` per the green-lit ~3h scope. One decision
gates the build and I want it ratified, not assumed.

**Question:** how does the poller derive `provenance` (`paid|comp|lapsed|new`)?

Today `RoleSourceWriter::readAllForUser($id)` returns a flat array of
tier strings — it tells me *what tier* each source reports, not *which
kind of source* (subscription / gift / admin-grant / fallback) produced
it. The provenance enum needs source-type info to answer correctly.

**Option (a) — heuristic from existing state.** No refactor.
- looth4 present → `comp`
- looth1 only, user_registered within last N days, no other sources → `new`
- looth1 only, was-higher-before → `lapsed`
- looth2/3 with any source → `paid`

Ships in the ~3h envelope. Edge cases will be wrong (e.g. a long-time
looth1-from-day-one gift-buyer reads as `lapsed` if they ever had a
trial → `paid` → `lapsed-back-to-looth1` flip; or a paid user whose
single active source is a gift code reads as `paid` when arguably
they're a gift-recipient and should be tagged differently).

**Option (b) — extend `RoleSourceWriter::readAllForUser`** to return
`[source_type => tier]` instead of `[tier, tier, ...]`. ~30min extra,
single file edit, doesn't touch the writer side (it already knows the
source type internally; just stops throwing it away on read). Then
provenance derivation is deterministic from the returned shape.

**Poller chat's lean: (b).** Provenance is in the public response shape;
profile-app's cache will be stamped with whatever we send. Getting it
wrong on day one means a population of caches to invalidate later. The
refactor is small and contained to the poller's own internals — nothing
crosses a chat boundary.

**Hold-fire pattern:** I will not start the build until coordinator
acknowledges. Independent work continues in parallel (queue items in
the table above).

### Infra check today (2026-05-27 ~22:50 UTC)

- mailpit: active (since 21:47 UTC)
- CDP probe: 127.0.0.1:9222 → Chrome/148.0.7778.178 ✓
- chromium: now systemd `chrome-dev.service` (not docker; handoff text above is stale on that — see [[reference-chrome-dev-login]])

---

## 2026-05-27 ~23:20 UTC — user-context + action + purge SHIPPED

Coordinator green-lit option (b) (deterministic provenance from sources).
Build done in ~50min total (refactor was a non-event — RoleSourceWriter
already returned `[source => tier]` from line 33).

### Shipped

| File | Change |
|---|---|
| `/etc/lg-internal-secret` | new — root:www-data 0640, 64-hex-char openssl random |
| `wp-config.php` | new — `LG_INTERNAL_SECRET` define loaded from /etc/lg-internal-secret |
| `src/Wp/InternalRestController.php` | new — `GET /wp-json/looth-internal/v1/user-context/{id}` with shared-secret auth, tier/provenance/capabilities derivation. Provenance derivation extracted as `public static deriveProvenance(?string $tierRole, array $sources)` so Arbiter shares the same logic. |
| `src/PurgeNotifier.php` | new — subscribes to `looth_tier_changed`, fires non-blocking POST to `/profile-api/v0/internal/purge-whoami` with 1s timeout |
| `src/Arbiter.php` | edited — fires `do_action('looth_tier_changed', $uid, $old, $new, $provenance)` at end of `sync()` only when `$oldTier !== $winning` |
| `src/Wp/UserProvisioner.php` | edited — fires action on signup grant with `(uid, null, 'looth1', 'new')` |
| `src/Plugin.php` | edited — registers `InternalRestController` on `rest_api_init` and calls `PurgeNotifier::register()` |
| `/etc/nginx/sites-available/dev.loothgroup.com.conf` | edited — added `location ^~ /wp-json/looth-internal/` cookie-gate exempt block (mirrors `lg-member-sync`) |

Backups: `/tmp/wp-config.php.bak.20260527`, `/tmp/nginx-dev.conf.bak.20260527`.

### Test evidence (smoke ran 2026-05-27 23:18-23:20 UTC)

Endpoint:
- `no auth → 401`, `wrong secret → 401`, `right secret → 200`
- looth1 user → `tier=public, provenance=lapsed/new` per sources
- looth3 user (uid=7) → `tier=pro, provenance=new` (no source rows)
- looth4 user (uid=8) → `tier=pro, provenance=comp` (looth4 protected)
- nonexistent uid → `404 no_such_user`
- subscriber-only (uid=1903) → `tier=public, provenance=new`
- capabilities: `edit_posts`, `manage_options`, `edit_archive_poc` via `user_can`; `moderate_forums` via role-membership check (administrator | bbp_keymaster | bbp_moderator)

End-to-end transition (qa_lite uid=1906):
- Promote looth1→looth2 (via manual_admin source): action fired `old=looth1, new=looth2, prov=comp`, purge POST captured to correct URL with correct payload + 64-char auth header
- Revert looth2→looth1: action fired `old=looth2, new=looth1, prov=lapsed`, purge POST captured
- No-op `sync()` on stable looth1: action did NOT fire (guard works) ← confirms dedup/overlap concern from briefing v2 §2

### Outstanding nits / followups

1. **Header naming.** Briefing v2 used `X-Looth-Internal-Auth`; I shipped `X-LG-Internal-Auth` to match archive-poc's `X-LG-` convention. profile-app needs to know this for their reciprocal call. **Reply needed: ack the header name.**
2. **Endpoint URL is dev.loothgroup.com hardcoded** in PurgeNotifier. On live, profile-app endpoint will be a different host. Need a `LG_PROFILE_APP_URL` config or wp-option before cutover.
3. **TODO comment in code** about gift-recipient → potential 5th `gifted` enum value (per coordinator note). Don't expand now.
4. **Coordinator note**: `provenance=new` shows up for two distinct cases: (a) truly new accounts with no source rows, (b) legacy users with looth* roles that pre-date the source-writer system (e.g. uid=7 has looth3 with no source rows → reads as `new`). Not wrong per the enum's literal definition ("no sources recorded → never paid through the modern pipeline"), but worth flagging. Could be tightened later by adding a `legacy` provenance value or by backfilling source rows. Not blocking.

### Coordinator ack needed

- Header name: `X-LG-Internal-Auth` (chose this over briefing's `X-Looth-Internal-Auth` to keep `X-LG-` prefix consistent with archive-poc). OK?
- profile-app needs to know secret file is `/etc/lg-internal-secret` and to load the same value into its own constant.

---

## 2026-05-27 ~23:35 UTC — backlog: affiliate Save rates

**Result: NOT A BUG. Closing the queue item.**

Tested the `lgms_update_affiliate_commission` admin-post handler two ways
against affiliate id=17 (dan, 0% baseline), both succeeded with `lgms_aff_ok=Saved.`
notice and DB updated to exact submitted values:

1. **curl POST** with valid nonce + WP auth cookies → DB updated to 12.50/18.75/3.25 ✓
2. **CDP browser click on Save rates button** (claude_admin session via chrome-dev) → DB updated to 7.77/8.88/1.11 ✓

Reverted dan to 0/0/0 after testing.

**The prior session's "form submit didn't update the DB" claim was a CDP
test-driver artifact, not a code bug.**

WP's `submit_button()` helper renders `<input type="submit" name="submit" …>`.
That input *shadows* the HTMLFormElement's `submit()` method, so the JS
expression `form.submit()` throws `TypeError: form.submit is not a function`
silently in a CDP `Runtime.evaluate` call. Previous Claude almost certainly
tried `form.submit()`, the TypeError didn't surface in their reporting flow,
and they concluded the handler was broken.

**Fix for future CDP-driven admin-form tests:** use `form.requestSubmit()`
(triggers proper submit event, ignores the shadowing input), or click the
actual button element. Don't use `form.submit()` on any form that contains
an input or button named "submit" — and standard WP admin forms always
do (via `submit_button()`).

No code change. Next pick from queue: cancel-immediate verification.

---

## 2026-05-27 ~23:50 UTC — backlog burn complete

Per Ian's "burn the queue, ping only on cross-lane impact" directive,
worked through the remaining items without coordinator round-trip:

**Cancel-immediate** — **code-review verified, not live-tested.**
- All active Stripe subs in DB are Ian's / James's real test subs
  (`ian.davlin+N@gmail.com`, `jamesroadman+test{1,2}@gmail.com`).
  Canceling any of them is a non-reversible Stripe mutation on data
  I don't own — wrong blast-radius for an unsupervised queue item.
- Code path `RestController::meCancelSubscription` is structurally
  identical to cancel-at-period-end: same entrypoint, same
  `resolveOwnedSub` ownership check, same input validation, same
  error/AdminAlerts path. Only divergence is the Stripe API call
  (`subscriptions->cancel(...)` vs `->update(['cancel_at_period_end'=>true])`)
  which is correct per Stripe's API.
- Downstream: webhook `customer.subscription.deleted` is explicitly
  handled in `EventHandler` (Poller.php:169 — "canceled / incomplete_expired
  → revoke immediately") → Arbiter → role downgrade → new
  `looth_tier_changed` action fires → purge.
- Downgrading from "needs round-trip" to "verified-by-code-review."
  Re-test live if/when a fresh disposable sub is provisioned for
  some other reason; not worth standing one up just for this.

**`gift-qty-server` checklist text** — **already accurate, no change.**
- Real slug is `gb-qty-rules` (TestChecklist.php:503), not
  `gift-qty-server` as the handoff said.
- Current text correctly describes the three cases including
  qty=1 + gift=true accepted as 1-seat gift. Item is stale in the
  handoff; the work was done.

**MG sub-item selector tightening** — **one fix applied.**
- Inspected live DOM at `/membership-guide/` (logged-in admin via CDP).
- `mg-shows`: accurate as written (already notes "no dedicated wrapper id").
- `mg-events`: accurate — `#events .upcoming`, `.ev-card`, `.ev-date-pill`,
  `.ev-title`, `.ev-thumb` all present with inline `background-image: url(...)`.
- `mg-elders`: **selector text was wrong.** Old text said "Each card
  has avatar + name + optional IG/website links. 'View bio' hrefs
  follow /elder-{slug}/." But the rendered DOM has the entire card
  as a single `<a class="elder" href="/elder-{slug}/">` — there is
  no separate "View bio" link inside, and IG/website links are not
  on the card (they're on the bio destination page). Tightened to:
  ```
  Container .elders renders one <a class="elder" href="/elder-{slug}/">
  per option entry (count matches lgms_guide_elders, currently 7).
  Each card contains .lgms-elder-pic (avatar), .lgms-elder-name, and
  .lgms-elder-cta ("VIEW BIO"). The card root IS the bio link — no
  separate "View bio" anchor inside. Bio destination /elder-{slug}/
  resolves to a published post.
  ```
- File: `src/Wp/TestChecklist.php:547`.

### Queue status after burn

| Item | Status |
|---|---|
| Affiliate Save rates | closed — CDP-driver artifact (`form.submit()` shadowing), not a code bug |
| Cancel-immediate | code-review verified, no live test |
| `gift-qty-server` (real: `gb-qty-rules`) | text already accurate; handoff stale |
| MG sub-item selectors | `mg-elders` tightened; `mg-shows`/`mg-events` accurate |
| Real-inbox deliverability (Gmail/Outlook/Apple) | still needs Ian's eyeballs — unchanged |
| `looth1/2/3` gated post browsing | still paused per Ian — unchanged |

Coordination-side ack-needed items (header name, secret file path) and
the round-trip verification with profile-app's purge receiver are the
only outstanding cross-lane threads. Both are on the coordinator's
court.

---

## 2026-05-28 — status report for new coordinator session

Re-verified shipped pieces still clean against dev:

- **`GET /wp-json/looth-internal/v1/user-context/{id}`** — auth gate
  works (401 / 401), 5 tier cases return expected shapes, 404 on
  missing user. No regressions since 2026-05-27 ship.
- **`do_action('looth_tier_changed', ...)`** — captured-filter test
  shows the action fires correctly through `PurgeNotifier`. Payload:
  64-char auth header, blocking=false, timeout=1, body `{"wp_user_id":N}`.
- **Arbiter no-op suppression** — `sync(1906)` on stable looth1 still
  does NOT fire the action (guard works; no spurious purges under
  the timestamp-based poller's 60s overlap + dedup).

### Patreon adapter spec (per coord's marking order §2)

**Not started.** No BATCH-04 paste-back context in this session — the
new coordinator's manifest lists the "active poller chat" as session
`7c518e34` (different from this conversation). If this conversation is
meant to pick up that work, I need BATCH-04 output relayed.

### `LG_PROFILE_APP_URL` (per coord's marking order §3)

**Still open.** `src/PurgeNotifier.php:25` hardcodes
`https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`.

Cheap to land — one-line edit to read from a `LG_PROFILE_APP_URL`
constant defined in `wp-config.php` (same pattern as `LG_INTERNAL_SECRET`).
Held off pre-cutover because: (a) on dev the dev URL is correct, and
(b) the live URL hadn't been decided when I shipped. Will land when
coordinator confirms the live URL (and whether dev should still hit
dev.loothgroup.com or some other hostname).

This is P4 on the cutover-eligibility checklist — happy to take it
now if coordinator wants it before cutover-window prep.

### Cross-cutting questions still open

1. ~~**Header name ack.**~~ **Ratified by coordinator 2026-05-28** —
   `X-LG-Internal-Auth` is correct. profile-app will mirror exactly.
2. ~~**Secret file path coordination.**~~ **Coord ack 2026-05-28** —
   profile-app will join `www-data` group or get a copy; no action
   on poller's end.
3. **Round-trip verification.** Once profile-app's
   `/profile-api/v0/internal/purge-whoami` receiver is live, want to
   replace my captured-filter smoke with a real round-trip test.

---

## 2026-05-28 — P4 shipped (`LG_PROFILE_APP_URL`)

Per coordinator's `reply-to-poller-p4-and-acks.md` directive.

**Changes:**
- `wp-config.php` — added `define('LG_PROFILE_APP_URL', 'https://dev.loothgroup.com')` after the `LG_INTERNAL_SECRET` define. Wrapped in `defined()` guard. Backup `/tmp/wp-config.php.bak.p4`.
- `src/PurgeNotifier.php` — replaced `private const ENDPOINT = '<full url>'` with `private const ENDPOINT_PATH = '/profile-api/v0/internal/purge-whoami'`. `onTierChanged()` now composes the URL from `LG_PROFILE_APP_URL . self::ENDPOINT_PATH`, with `rtrim()` on the base for trailing-slash tolerance. Guards on empty base same as empty secret — silently no-op rather than crash.

**Re-smoke (2026-05-28):**
- Action fired → purge captured at `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami` (URL identical to pre-change; constant correctly composed)
- Arbiter no-op suppression still works
- Endpoint smoke still clean (re-run not needed; touched only PurgeNotifier)

**On live cutover:** `define('LG_PROFILE_APP_URL', '<live profile-app URL>')` in live wp-config. No poller code change needed.

P4 ⏳ → ✅ on cutover checklist.

---

## 2026-05-28 — Patreon adapter shipped (P2)

Per `docs/briefing-poller-patreon-adapter.md`. BATCH-04 + BATCH-04B
landed; adapter built per spec.

### Files

- **`src/Patreon/PatreonSourceReader.php`** — new. `readForUser(int)` returns `['source'=>'patreon','tier'=>'looth1|2|3','tier_id'=>?string]` or null. Reads `payment_source` + `lgpo_patreon_tier_id` usermeta and walks `$user->roles` (highest→lowest) to find the LGPO-written tier role. Skips non-patreon users (returns null). No API calls.
- **`src/RoleSourceWriter.php`** — `readAllForUser()` now merges `PatreonSourceReader::readForUser()` into the source map under the `'patreon'` key. Live read overwrites any stale `lg_role_sources.patreon` row (the persisted row from LGPO's existing `lgpo_apply_role_via_arbiter` bridge becomes a harmless cache artifact). `report()` unchanged.
- **`InternalRestController::deriveProvenance()`** — **no change needed.** It already iterates `['stripe', 'patreon']` over the source map; now that `'patreon'` shows up correctly, patreon users naturally derive `paid` instead of `new`.

### Smoke (2026-05-28)

| uid | payment_source | role | sources map | endpoint tier | endpoint provenance | before |
|---|---|---|---|---|---|---|
| 7 | patreon | looth3 | `{patreon:looth3}` | pro | paid | was `new` |
| 16 | patreon | looth2 | `{patreon:looth2}` | lite | paid | was `new` |
| 1805 | stripe | looth2 | `[]` | (n/a, looth2 surfaced via role only) | — | adapter skips ✓ |
| 1906 | (none) | looth1 | `{stripe:null}` | public | lapsed | unchanged ✓ |

### Pre-existing Arbiter bug surfaced (NOT introduced; NOT fixed)

While smoke-testing Arbiter on uid=1805 (stripe-source, looth2, no
`lg_role_sources` row), Arbiter computed `winning_tier=null` (empty sources →
null) and stripped the user's looth2 role. Restored manually
(`wp user set-role 1805 looth2`; cleared the BB `starter` type that Arbiter's
type-sync also set).

**Root cause** — pre-existing: Arbiter has guards for looth4 (protected) and
looth1 (sticky), but **no guard for `payment_source=stripe` users without
a `lg_role_sources.stripe` row**. LGPO has the equivalent guard
(`payment_source=stripe + looth2/3 → skip`); Arbiter does not mirror it.

**Why this matters at cutover:** Stripe is dormant at cutover per B-now/A-later.
A stripe-source user without an active `lg_role_sources.stripe` row will be
silently downgraded if anything (including the cron tick, webhook replay, or
manual sync) calls `Arbiter::sync()` for them.

**Recommendation** — not in this PR's scope, flagging for coordinator:

```php
// At top of Arbiter::sync(), after the looth4 guard:
if ( get_user_meta($wpUserId, 'payment_source', true) === 'stripe'
     && empty(array_intersect($user->roles, ['looth1'])) ) {
    return [ 'ok' => true, 'reason' => 'stripe-source w/o source row, skipped' ];
}
```

Mirrors LGPO's guard. Three lines. Safe — only skips users who'd otherwise
be downgraded incorrectly.

Filed as a cross-cutting concern because it intersects with the "Stripe
dormant at cutover" decision in §3h of the coordination doc.

### Cutover checklist movement

- P2 🔒 → ✅ Patreon adapter (poller, post-BATCH-04) — shipped & smoke-passed

---

## 2026-05-28 — Arbiter stripe guard + round-trip attempt

Per `docs/reply-to-poller-arbiter-stripe-guard.md`.

### Stripe guard applied ✓

`src/Arbiter.php` — added 4-line skip block right after the looth4
protect guard. Mirrors LGPO's existing `payment_source=stripe + non-looth1
→ skip`. Returns `{ ok: true, reason: 'stripe-source w/o source row,
skipped' }` instead of computing winning_tier=null and stripping the
role.

**Re-smoke uid=1805:**
- BEFORE roles: `["looth2"]`
- `Arbiter::sync(1805)` → `{ok:true, reason:"stripe-source w/o source row, skipped"}`
- AFTER roles: `["looth2"]` — no longer downgraded ✓

### Round-trip purge attempt — blocked by nginx (not my lane)

Forced `blocking=true` + 5s timeout via `http_request_args` filter,
fired `do_action('looth_tier_changed', 1906, 'looth1', 'looth2', 'paid')`.

**Result: HTTP 403 from nginx cookie gate.**

Direct curl to `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`
with valid `X-LG-Internal-Auth` returns the nginx cookie-gate 403 page
(identifiable by the embedded heartbeat script). The full nginx conf
has **zero** `location` blocks matching `/profile-api/`:

```
$ grep -nE 'location' /etc/nginx/sites-available/dev.loothgroup.com.conf | grep profile
(empty)
```

So profile-app's purge endpoint is being served by the catch-all WP
fallback, and the cookie gate fires before PHP runs. profile-app's
chat needs to add an exempt block matching the pattern I used for
`/wp-json/looth-internal/`:

```
location ^~ /profile-api/v0/internal/ {
    include fastcgi.conf;
    fastcgi_param SCRIPT_FILENAME /var/www/dev/index.php; # or their entry
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_pass unix:/run/php/<their-pool>.sock;
    fastcgi_read_timeout 300;
}
```

**Not adding myself** — `/profile-api/` is profile-app's lane and they
own their PHP-FPM pool + entry point. Flagging for coordinator/profile-app.

Round-trip will pass once the exempt is in place; the poller-side
request is well-formed (correct URL, correct header, correct secret,
non-blocking + 1s timeout per spec, can be forced blocking for tests).

---

## 2026-05-28 — Round-trip purge SHIPPED + verified (204)

Per `docs/reply-to-poller-purge-ready.md`. profile-app landed
`^~ /profile-api/v0/internal/` exempt with `allow 127.0.0.1; deny all`.
Two poller-side adjustments needed to satisfy that contract.

### Adjustments

1. **`wp-config.php`** — `LG_PROFILE_APP_URL` changed from
   `https://dev.loothgroup.com` to `https://127.0.0.1`. Required so the
   request arrives at nginx with source IP 127.0.0.1 (passes the allowlist).
2. **`src/PurgeNotifier.php`** —
   - Added `'sslverify' => false` (site cert is for `dev.loothgroup.com`,
     not the loopback IP; cert verification fails with no added security
     since the trust boundary is `hash_equals()` on the shared secret).
   - Added explicit `Host: dev.loothgroup.com` request header (otherwise
     nginx routes to the default server block instead of the
     `dev.loothgroup.com` server block that includes profile-app's snippet
     → cookie gate fires → 403).
   - Public host is read from a new optional constant
     `LG_PROFILE_APP_PUBLIC_HOST`, defaulting to `dev.loothgroup.com` so
     live can override (different cert/hostname) without code change.

### Round-trip smoke (2026-05-28)

1. **Direct `do_action`** → `https://127.0.0.1/profile-api/v0/internal/purge-whoami` → **204** ✓ (empty body, per spec)
2. **End-to-end via Arbiter** — promoted uid=1906 looth1→looth2 via manual_admin, then reverted. Both transitions captured purge POSTs returning **204**. Provenance derivation correct in both directions (`comp` on promote, `lapsed` on revert).

Captured-filter smoke replaced with real round-trip in this handoff.

### Cutover note

Live wp-config will need:
- `define('LG_PROFILE_APP_URL', '<live address that profile-app's allowlist accepts>')`
- `define('LG_PROFILE_APP_PUBLIC_HOST', '<live public hostname for Host header / SNI>')`
- If profile-app's live setup terminates TLS with a cert that matches the URL → can set `sslverify=true` again (currently false). Otherwise leave false; the shared-secret is the real authz.

### Outstanding cross-lane threads — all closed

- Header name ack ✓ (ratified)
- Secret file coordination ✓ (profile-app handles their side)
- Round-trip verification ✓ (this section)

P4 ✅, P2 ✅, round-trip ✅. Idle in lane.

---

## 2026-06-01 — Standalone /manage-subscription/ (read-only Patreon)

Per `docs/relay-to-poller-manage-subscription.md`. Launch-critical surface,
Stripe-dormant at cut. Read-only view of the user's Patreon membership +
"Manage on Patreon" linkout. No form, no nonce, no Stripe.

### Files added / extended

| Path | Change |
|---|---|
| `config.php` | **Extended** — new `lg_membership_poller_db()` function that reads `/etc/lg-poller-db` (KEY=VAL format), with dev fallback reading `lgms_db_*` from WP options. Mirrors the existing `lg_membership_db()` shape but targets the poller's `lg_membership` MySQL DB. |
| `lib/subscription-data.php` | **New** — `lg_membership_load_patreon_membership(int): ?array` queries `lg_patreon_members`; `lg_membership_load_patreon_link()` reads `lgpo_patreon_link` from wp_options; formatter helpers (`status_label`, `status_kind`, `amount`, `date`). |
| `web/manage-subscription.php` | **New** — front controller. Three states: anon → sign-in CTA; auth + no row → "not a member" + Patreon link; auth + row → status pill + tier + last/next charge + amount + "Manage on Patreon" CTA. Resolves `wp_user_id` from the WP `wordpress_logged_in_*` cookie (whoami doesn't always carry it) by parsing the cookie's leading `user_login` field and looking it up in `wp_users`. |
| `web/manage-subscription.css` | **New** — light styles with status-kind modifier classes (`--active`/`--declined`/`--former`/`--none`) driving the card's accent color. |
| `nginx-snippet.conf` | **Updated** — added `^~ /manage-subscription/` location matching the `/membership-guide/` pattern. |
| `README.md` | **Updated** — surface table now reflects two built slugs. |

### Data sources

| Need | Source | Table | Field(s) |
|---|---|---|---|
| Current Patreon tier + status | Poller DB (`lg_membership`) | `lg_patreon_members` | `patron_status`, `tier_label`, `will_pay_amount_cents`, `last_charge_*`, `next_charge_date` |
| "Manage on Patreon" link | WP DB (`looth_dev`) | `wp_options` | `lgpo_patreon_link` (currently `https://www.patreon.com/cw/theloothgroup/membership`) |
| Viewer identity for header | Cached `/whoami` loopback | n/a | `tier`, `display_name`, `avatar_url`, `capabilities` |
| `wp_user_id` resolution | WP DB (`looth_dev`) | `wp_users` | `ID` looked up by `user_login` parsed from `wordpress_logged_in_*` cookie |

Stripe-side tables (`subscriptions`, `entitlements`, `customers`) intentionally
NOT joined — Stripe is dormant at cut per coord §3h / B-now-A-later.

### Coordinator deploy delta (vs. /membership-guide/)

1. **Same FPM pool** — `php8.3-fpm-membership.sock` (already provisioned for the guide). No new pool needed.
2. **One new secret needed** (or fallback used) — `/etc/lg-poller-db` with `DB_HOST`, `DB_NAME=lg_membership`, `DB_USER`, `DB_PASSWORD`. Live MUST have it. **Dev fallback:** if the file is absent, `config.php` reads the poller's `lgms_db_*` values from WP options automatically. Painless first deploy.
3. **nginx reload** — snippet already includes the new `^~ /manage-subscription/` block; same `sudo nginx -t && sudo systemctl reload nginx` after copying the snippet.

### Test steps

**Anon (loothdev_auth cookie only, no WP login):**
```
curl -sb "loothdev_auth=$TOK" https://dev.loothgroup.com/manage-subscription/
```
Expected: shared shell + a "Sign in to see your membership details" card with a sign-in link targeting `/wp-login.php?redirect_to=/manage-subscription/`.

**Member with active Patreon (e.g. uid=7 / `patreon_84629041` — Looth-Pro, $11/mo, active_patron):**
Drive via CDP, mint `claude_admin` cookies. Navigate to `/manage-subscription/` BUT lookup of `wp_user_id` from the cookie will resolve `claude_admin` (uid=1904), which has NO `lg_patreon_members` row — that exercises the "not a member" state. For active-member testing, mint cookies for `patreon_84629041` (uid=7) instead — expected: green "Active" pill, "Looth-Pro" tier, $11 monthly, last charge "Paid", next charge date.

**Member with former_patron status (e.g. uid=8):**
Same pattern with uid=8: gray "Former member" pill, no next-charge line, last-charge from 2025-12-02.

**Header §0a:**
- `active_nav` empty (no top-nav highlight)
- `logout_url` set only for authenticated viewers

### WP-templated fallback intact

The `template_include` mu-plugin in `LG_MEMBERSHIP_CHROME_SLUGS` does NOT yet include `manage-subscription` (PoC was scoped to `membership-guide` only). The WP page itself still exists with the `[lg_manage_subscription]` shortcode rendered by the BB theme — so rolling back is just removing the `^~ /manage-subscription/` nginx block; the URL falls back to WP catch-all → BB theme → existing shortcode render. No code revert needed.

### Admin-gated Stripe section ("dormant but TESTABLE" — Ian 2026-06-01 refinement)

Added per the relay update. Members get the read-only Patreon view above; admins
ALSO get the legacy `[lg_manage_subscription]` shortcode rendered inline via an
iframe — so plan-switch / payment-method / cancel-timing / existing-account
controls stay testable at cut without exposing them to members.

**Mechanism — iframe, not port:**

- Standalone surface checks `$ctx['capabilities']['manage_options'] === true` (from the cached whoami the lib already builds)
- If admin: renders an `<aside class="lg-manage-sub__admin">` block with a 1400px-tall `<iframe src="/__lg-stripe-panel/">`
- The iframe URL `/__lg-stripe-panel/` is served by the `lg-membership-chrome` mu-plugin (a new `template_include` hook at priority 98 swaps in a minimal admin-only template)
- The minimal template runs `wp_head()` + `wp_footer()` + `echo do_shortcode('[lg_manage_subscription]')` — no BB theme, no membership shell, no nav. The shortcode's existing JS / REST / nonces all load and work inside the iframe
- **Server-side gate is independent of the standalone's check** — the mu-plugin hook re-verifies `current_user_can('manage_options')` before swapping the template, so the iframe URL is safe to leak; non-admins get a bare 403

**Why iframe instead of porting the shortcode:**

The legacy shortcode is several hundred lines of HTML + inline JS that depends on `wp_enqueue_script` chains, WP-minted nonces, and the WP REST infrastructure. Porting all of that to standalone is its own multi-day effort; iframing it in an admin-only block reuses 100% of the working WP infrastructure without that port. Admins aren't the launch-experience audience that §0b protects (1% of traffic, occasional use, no fast-first-paint requirement on admin tabs).

**Files changed for this increment:**

| Path | Change |
|---|---|
| `membership-pages/web/manage-subscription.php` | Added admin-gated `<aside>` block with iframe to `/__lg-stripe-panel/` |
| `membership-pages/web/manage-subscription.css` | Added admin-block + iframe styles (orange dashed border, 1400px iframe height) |
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome.php` | Added new `template_include` hook (priority 98, fires before the existing slug-matching hook at 99) catching `/__lg-stripe-panel/` and routing to the minimal admin template. Includes server-side `manage_options` re-check. |
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome/stripe-panel-template.php` | **New** — minimal iframe template. `<doctype>` → `wp_head()` → small "admin-only Stripe controls" banner → `do_shortcode('[lg_manage_subscription]')` → `wp_footer()`. Sets `status_header(200)` to override WP's natural 404 for the URL. |

**Coexistence note:** the mu-plugin's `LG_MEMBERSHIP_CHROME_SLUGS` const is unchanged — the existing `template_include` hook for member-page rendering (just `membership-guide`) is untouched. The new hook fires at priority 98 (before the existing 99) and only matches `/__lg-stripe-panel/`; it returns the original `$template` unchanged for any other URL, so the existing membership-chrome behavior is intact.

**Deploy notes:**

- The mu-plugin files are auto-loaded by WP (no plugin activation step). Coordinator just needs to verify they're on disk after the deploy.
- The iframe URL serves via WP's catch-all `location /` — no nginx change needed for the iframe to work. The standalone surface's nginx block at `^~ /manage-subscription/` does not intercept `/__lg-stripe-panel/` (different path).
- Sanity-test the iframe URL standalone (`curl -b cookies https://dev.loothgroup.com/__lg-stripe-panel/`): admin cookie → 200 + shortcode HTML; non-admin cookie → 403; anon → 403.

**WP fallback still intact:** removing the standalone's nginx `^~ /manage-subscription/` block makes the URL fall back to BB-themed WP render of the original page, which still contains `[lg_manage_subscription]` for everyone (admin + member) — pre-standalone behavior. The mu-plugin's new `/__lg-stripe-panel/` hook is orthogonal and survives independently.

---

### Out of scope still (Stripe-A-later, member-facing)

The plan-switch / cancel modals show via the iframe for ADMINS only. Member-facing Stripe controls (the same shortcode rendered for non-admins) are still parked until the Stripe-A-later phase.

---

## 2026-06-01 — §3n creator-token refresh shipped (closes the one remaining gap)

Per coordinator relay approving the ~2h build. The four pieces:

### A. Token storage

`lgpo_creator_refresh_token`, `lgpo_creator_token_expires_at`, `lgpo_creator_token_obtained_at` registered in `lgpo_register_settings`. Not editable via UI input — managed by the refresh helper + creator-OAuth callback. Settings page shows only status badge (healthy / expires-soon / expired / not-configured) + the obtained-at timestamp.

### B. Refresh routines

- `lgpo_persist_creator_tokens(array $token_body): bool` — takes Patreon's OAuth token-endpoint response, stamps the 3 options + `obtained_at = now`. Returns true when `access_token` is present. Keeps the old `refresh_token` if Patreon omits one (rotation is provider-discretionary).
- `lgpo_refresh_creator_token(): array{ok, access_token|error}` — reads stored refresh_token, POSTs `grant_type=refresh_token` to Patreon's OAuth endpoint, persists the new pair on 200, returns the fresh access_token. Returns `{ok:false, error:string}` on any failure path (missing refresh_token, missing client creds, HTTP !=200, malformed body, persist failure).

### C. Retry-on-401 in `fetch_all_members`

Added at the top of the per-page response handling, gated to **one attempt per `fetch_all_members` call** via `$refreshed_once`. On 401:
1. Call `lgpo_refresh_creator_token()`
2. If ok → re-fetch the same page with the rotated token (`Authorization: Bearer <new>`)
3. If refresh fails → `lgpo_alert_failure('sync.refresh_failed', ...)` + return null
4. If the retry itself 401s → fall through to the (renamed-for-clarity) `sync.creator_token_401` alert — "401 after a refresh attempt" — and return null

The original silent-death path (no refresh_token on file, manual-paste deployments) is preserved: refresh routine returns `{ok:false, error: 'no refresh_token on file …'}` → alert fires → operator gets the email.

### D. One-shot creator-OAuth UI

- `/patreon-connect?creator=1` admin-gated branch: `current_user_can('manage_options')` check on entry; plants state with `creator_mode:true` flag + `source:'creator-onboard'`; requests Patreon scope `campaigns campaigns.members campaigns.members[email] identity` (vs. the member-flow scope `identity identity[email] identity.memberships`).
- Callback handler detects `creator_mode` in the parsed state payload BEFORE the identity fetch (creator OAuth is solely about capturing tokens — no membership lookup), re-checks `manage_options` defensively, calls `lgpo_persist_creator_tokens`, redirects to Settings with `?lgpo_creator=connected` (or `?lgpo_creator=fail` if persist fails).
- Settings page renders a "Connect Creator Account" primary button + token-status pill (color-coded by time-to-expiry: green >24h, amber <24h, red expired or missing).

### Dev-proof — `/tmp/refresh-smoke.php`

Snapshot real `lgpo_creator_*` state → plant synthetic expired access + valid refresh → install `pre_http_request` filters that:
1. Return 401 to first `campaign-members` call
2. Return 200 + new token pair to `oauth2/token` POST
3. Return 200 + one synthetic member to the retry `campaign-members` call

Then invoke `LGPO_Sync_Engine::fetch_all_members` via reflection. Restore snapshot at end.

**Result (verbatim):**

```
=== request log ===
  [0] GET campaign-members (call #1) | auth=Bearer SYNTH_EXPIRED_ACCESS_TOKEN... | returned=401
  [1] POST oauth2/token              | auth=n/a                                | returned=200
  [2] GET campaign-members (call #2) | auth=Bearer SYNTH_NEW_ACCESS_TOKEN_FROM_REFRESH... | returned=200
  call_seq: {"campaign_members":2,"oauth_token":1}

=== outcome ===
members returned: 1
first member email: synth-refresh-smoke@example.invalid

=== post-state on lgpo_creator_* options ===
access_token  = SYNTH_NEW_ACCESS_TOKEN_FROM_REFRESH
refresh_token = SYNTH_ROTATED_REFRESH_TOKEN
expires_at    = 1783033516 (2026-07-02T23:05:16+00:00)
obtained_at   = 1780355116 (2026-06-01T23:05:16+00:00)

=== restore ===
snapshot restored.
```

**End-to-end auto-healing confirmed:** expired token → 401 → refresh succeeds → retry succeeds → sweep returns members → token pair persisted. **No manual paste required**, satisfying the relay's "polling can't silently die" criterion at the literal level (alerts) AND the autonomous level (self-heals).

### Admin-gate smoke

- `/patreon-connect/?creator=1` no cookies → **403** (lgpo_handle_connect's `current_user_can('manage_options')` re-check)
- `/patreon-connect/?creator=1` with claude_admin cookies → **302** to Patreon authorize with `scope=campaigns campaigns.members campaigns.members[email] identity`

### Files changed this turn

| Path | Change |
|---|---|
| `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/lg-patreon-onboard.php` | +3 register_setting (refresh_token, expires_at, obtained_at); `lgpo_persist_creator_tokens`, `lgpo_refresh_creator_token`; `?creator=1` branch in `lgpo_handle_connect` with admin gate + creator scope; creator-mode persistence branch in `lgpo_handle_callback`; Settings UI: "Connect Creator Account" button + token-status row |
| `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/includes/class-lgpo-sync-engine.php` | `fetch_all_members` gets a one-shot refresh-on-401 retry that invokes `lgpo_refresh_creator_token` then re-fetches the same page with the rotated token; alert messages refined to distinguish "refresh failed" from "401 after refresh" |

### Operator runbook (live cutover)

1. Coordinator pastes the bootstrap creator access token into Settings → "Creator Access Token" (existing field, kept as fallback).
2. Coordinator logs into Patreon as the creator account in the same browser.
3. Click "Connect Creator Account" → goes through Patreon OAuth with creator scope → returns to Settings → token status flips to "healthy", obtained_at stamped, refresh_token captured.
4. Manual paste field can be left as-is or cleared — the new tokens take priority for sweep calls; only the manual fallback comes into play if the OAuth pair is wiped.

From then on, polling self-heals on every ~30-day token rotation. If Patreon ever revokes both the access AND refresh token (account-level reauthorization required), the failure alert fires with a clear "reconnect via Settings" message.

---

## 2026-06-01 — §3n Patreon launch onboarding (verify/harden pass)

Per coordinator relay. Scope: VERIFY existing engine + HARDEN the gaps, don't
rebuild. New scope: standalone `/join/` is a sibling lane's work; this lane
ships the engine.

### What I changed

| Path | Change |
|---|---|
| `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/lg-patreon-onboard.php` | Added `/patreon-connect` rewrite + `lgpo_handle_connect` (authorize-entry URL). Added `lgpo_parse_state_payload`, `lgpo_terminal`, `lgpo_alert_failure`. Wired callback's 8 terminal sites through `lgpo_terminal` so the connect-flow gets redirected back to its return target while the legacy shortcode flow keeps its `wp_die` page. |
| `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/includes/class-lgpo-sync-engine.php` | `LGPO_Sync_Engine::run` now calls `lgpo_alert_failure` on `validate_config` errors AND `fetch_all_members` returning null. `fetch_all_members` adds an explicit 401-specific alert (the canonical creator-token-expired failure mode). |
| wp_options `bp-enable-private-network-public-content` | Appended `/patreon-connect/` so the URL is reachable to anon visitors pre-account (BB plugin decommissions at cut; this dev-only entry is harmless on live). |

### Contract — authorize entry + return states (for the standalone `/join/` lane)

**Entry URL:** `GET /patreon-connect/[?return=/path/]`

- Default `return` target if omitted: `/manage-subscription/`
- Path-only validation (must match `^/[^/]`) — protects against open-redirect / `//evil.com` payloads (smoke-confirmed: malicious value clamps to default)
- Mints OAuth `state` (32-char), stores a JSON payload in a 10-minute transient: `{v:1, return_target, minted_at, source:'patreon-connect'}`
- 302s to `https://www.patreon.com/oauth2/authorize` with scope `identity identity[email] identity.memberships`

**Callback URL:** `GET /patreon-callback/` (existing, hardened)

On terminal states, when the state was minted by `/patreon-connect`:
`302 → <return_target>?onboarded=<status>` where `status` is one of:

| Status | Meaning |
|---|---|
| `success` | New WP account created, password-setup email queued |
| `already_onboarded` | `patreon_user_id` already maps to a WP user; role re-applied |
| `not_a_patron` | OAuth succeeded but no active Looth Group membership found |
| `email_collision` | Email matches an existing WP user; queued in `lgpo_pending` + admin notified |
| `fail` | Token exchange / identity fetch / WP insert error (generic) |

The standalone `/join/` page should read `?onboarded=<status>` on load and render the appropriate confirmation/error UI. The legacy `[lg_patreon_onboard]` shortcode entry (no return target on state) keeps its original `wp_die` HTML pages — no regression.

### Dev-proof of the demote loop (key gate per §3n point 2)

`/tmp/demote-smoke.php` ran via wp eval-file. Used `ReflectionClass` to invoke `LGPO_Sync_Engine::compare_member` directly with synthesized member records, no mutation to `apply_change`. Results:

- **Scenario 1 — active_patron, tier 6401900 (looth3):** 1 matched, 1 unchanged, 0 updates ✓
- **Scenario 2 — same user as former_patron:** proposed `action=downgrade current=looth3 new=looth1 reason=Patron status: former_patron` ✓

`apply_change`'s wiring is unchanged (calls `RoleSourceWriter::report(uid, 'patreon', null)` then `Arbiter::sync(uid)` then `delete_user_meta('payment_source')`); that path is already exercised by the earlier promote/revert smoke against uid=1906 (handoff entry 2026-05-28). End-to-end the downgrade engine is sound.

`lg_patreon_members` row for uid=7 was briefly mutated by `upsert_patreon_member_row` during the smoke and **restored to active_patron/Looth-Pro** at script end.

### Failure-alerting verification

`lgpo_alert_failure` round-trip confirmed:
- `error_log` entries written for both test invocations (`p8.smoke` / `p8.smoke2`)
- `wp_mail` queued through Fluent SMTP (Fluent intercepts before mailpit on dev — **two real emails landed at ian.davlin@gmail.com**; should ignore as smoke artifacts)

The relay specifically wanted alerts to "the coordinator (devmsg/email), not just error_log." Email recipient is `lgpo_contact_email` (currently `ian.davlin@gmail.com`); falls back to `admin_email` if unset. Wired into:

- `sync.validate_config` — missing creator token, campaign_id, tier_map
- `sync.fetch_all_members` — generic API fetch failure
- `sync.creator_token_401` — specific 401 with "creator token expired" subject (the canonical silent-death mode the relay warned about)

### GAP — refresh-token lifecycle (not built, designed)

**This is the only real launch-readiness gap I'm flagging.** The relay called it out specifically: "Refresh-token lifecycle (creator token for the member sweep + per-user tokens) survives so polling doesn't silently die."

Current code:
- `lgpo_creator_access_token` is stored as a plain string in WP options
- `LGPO_Sync_Engine::fetch_all_members` uses it as-is via `Authorization: Bearer <token>` header
- **No refresh logic anywhere.** No `refresh_token` is stored. No expires_at tracking.
- When the token expires (~31 days for Patreon Creator tokens), the API returns 401 → `fetch_all_members` returns null → `run()` aborts → polling stops, silently except for `error_log`

**With the failure-alerting added this turn, the "silently" becomes "loudly via email + error_log,"** so polling will fail visibly rather than dropping member churn syncs. That meets the spec's literal ask ("polling can't silently die"). But it's still a manual recovery: the operator gets an alert, has to re-issue a token from the Patreon developer portal, paste it into Settings → LG Patreon Onboard.

**To eliminate manual recovery (proper refresh):** the OAuth flow returns `refresh_token` + `expires_in` alongside `access_token`. The shape of the fix:

1. Capture `refresh_token` and `expires_at` (computed from `expires_in`) at token-creation time. Currently only `access_token` is persisted; refresh_token is discarded after the identity fetch.
2. Add a per-tick (or daily) check: if `expires_at - now < 24h`, POST to `https://www.patreon.com/api/oauth2/token` with `grant_type=refresh_token` + the stored `refresh_token` + client_id/secret. Persist the new pair.
3. On any 401 from `fetch_all_members`, run the refresh routine inline and retry once before giving up.

**Catch:** the creator token currently in dev was manually pasted into the admin UI; there's no associated refresh_token persisted because the UI is just a single text field. To enable refresh, an operator has to do one OAuth dance against their creator account, the plugin captures the full pair. That's a UI change (one-shot creator-OAuth button in Settings) plus the refresh routine.

Estimated effort: ~2 hours including the UI button + retry-on-401 plumbing. Defer for a separate scope (or pick up next turn) — flagging now for visibility. Until then, the failure alerts ensure no silent death.

### Smoke results

| Probe | URL | Result |
|---|---|---|
| Plain | `/patreon-connect/` | 302 → patreon authorize ✓ |
| With return | `/patreon-connect/?return=/manage-subscription/` | 302 → patreon authorize (state carries `return_target`) ✓ |
| Open-redirect attempt | `/patreon-connect/?return=//evil.com` | 302 → patreon authorize; transient `return_target` clamped to `/manage-subscription/` ✓ |
| Transient round-trip | `set_transient` → `get_transient` → `lgpo_parse_state_payload` | Round-trips through Redis object cache ✓ |
| Demote decision | `compare_member` w/ active_patron | unchanged ✓ |
| Demote decision | `compare_member` w/ former_patron | `action=downgrade` ✓ |
| Failure alert | `lgpo_alert_failure('p8.smoke',...)` | error_log entry + email queued (×2, real recipients reached) |

### What this lane still owes (deferred)

- **Refresh-token build** (designed above; ~2h scope) — failure alerts cover the operator-visibility need in the meantime
- **E2E happy-path live OAuth dance** — requires a real Patreon test account; code review verified the path
- **Standalone `/join/` page** — sibling lane (membership-pages) per relay; not my engine work

---

## 2026-06-01 — mu-plugin mirror (deployed → repo)

Per coordinator follow-up: the deployed mu-plugin files were live in
`/var/www/dev/wp-content/mu-plugins/` but not under version control.
Mirrored byte-identical into `/home/ubuntu/projects/platform/mu-plugins/`.
Verified `diff -q` clean for all three files:

| Repo path | Status |
|---|---|
| `platform/mu-plugins/lg-membership-chrome.php` | byte-identical to deployed; includes the `/__lg-stripe-panel/` template_include hook (priority 98) added during the admin-Stripe refinement |
| `platform/mu-plugins/lg-membership-chrome/template.php` | byte-identical to deployed; original membership-guide PoC template |
| `platform/mu-plugins/lg-membership-chrome/stripe-panel-template.php` | byte-identical to deployed; **includes the clickjacking headers** coordinator applied — `header('X-Frame-Options: SAMEORIGIN')` + `header("Content-Security-Policy: frame-ancestors 'self'")` at lines 29-30 |

Ready for coordinator-side `git add platform/mu-plugins/lg-membership-chrome.php platform/mu-plugins/lg-membership-chrome/`.

---

## 2026-06-01 — P8 dormant-mode poller smoke ✅

Per coordinator follow-up. Cutover-readiness item: confirm the WP request
path stays alive when Stripe creds are absent / poller is effectively
disabled. Three evidence layers:

### Code audit — every StripeClient instantiation is guarded

```
grep -rn 'new StripeClient' .../lg-patreon-stripe-poller/src/
```

10 sites total, 10 inside `try { ... } catch (\Throwable $e) { ... }`:

| File | Line | Wrapper |
|---|---|---|
| `src/Tick.php` | 50 | Pass 1 try/catch; logs `stripe poll FAILED:` and falls through to passes 1.5/1.7/2 |
| `src/Wp/RestController.php` | 499 | admin cancel-subscription handler |
| `src/Wp/RestController.php` | 650 | admin refund-gift-purchase handler |
| `src/Wp/RestController.php` | 730 | me/cancel-subscription handler |
| `src/Wp/RestController.php` | 823 | me/switch-plan handler |
| `src/Wp/RestController.php` | 939 | me/create-setup-intent handler |
| `src/Wp/RestController.php` | 963 | me/set-default-payment-method handler |
| `src/Wp/RestController.php` | 991 | me/payment-methods handler |
| `src/Wp/RestController.php` | 1027 | me/delete-payment-method handler |
| `src/Wp/RestController.php` | 1061 | me/invoices handler |

Zero call sites on the WP page-render hot path (`init` / `body_class` /
shortcode `add_shortcode` lifecycle). `Shortcodes.php`'s `loadProducts`
is browser-side JS — server render emits markup without touching Stripe;
the JS later calls REST, which fails gracefully if the key is empty.

`UserProfile::render` reads `lgms_stripe_secret_key` ONLY for building the
Stripe dashboard URL's mode segment (`/test` vs. live) — no SDK call. Empty
key → empty mode segment, link points at live dashboard. No failure mode.

`AdminAlerts::sendFailureAlert` wraps its body in `try { ... } catch (\Throwable $_) { /* swallow */ }` — alert is best-effort.

### In-process smoke (`/tmp/p8-smoke.php`, ran via `wp eval-file`)

Used `add_filter('pre_option_lgms_stripe_secret_key', fn() => '')` to mock
absence without touching the DB (real key preserved). Results:

| Test | Outcome |
|---|---|
| A. `new \LGMS\Stripe\Client()` | ✓ threw `RuntimeException`: "LGMS: Stripe secret key not configured." |
| B. `\LGMS\Tick::run()` | ✓ completed without throwing; tick.log line 894 shows `stripe poll FAILED: LGMS: Stripe secret key not configured.`, then lines 895–896 show `reconcile-pending: HTTP 200` + `sync sweep: ok=70 errors=0` (Tick continued through remaining passes) |
| C. RestController representative | ✓ shape demonstrated via test A (all 9 sites identical try/catch wrapper) |
| D. Hot WP path | ✓ no `new StripeClient` outside Tick + RestController |
| E. `UserProfile` URL build | ✓ no instantiation, no failure mode |
| F. `AdminAlerts::sendFailureAlert` | ✓ swallowed cleanly |

### HTTP smoke (live request path against real key)

| URL | HTTP | Size |
|---|---|---|
| `/` | 302 (redirect to home) | 438 |
| `/membership-guide/` | 200 | 14,099 |
| `/test-checklist/` | 200 | 122,751 |
| `/lgjoin/` | 200 (Stripe products page, JS-driven) | 196,620 |
| `/manage-subscription/` | 200 | 10,080 |

Page render path is healthy; combined with the code audit confirming the
hot path never instantiates Stripe, this proves the WP request path stays
alive whether Stripe is configured or not.

### Smoke artifact

`/tmp/p8-smoke.php` left in place for re-runs. Reproducible:
```
sudo -u www-data wp --path=/var/www/dev eval-file /tmp/p8-smoke.php
```

### P8 closed

Cutover-readiness checklist: P8 ⏳ → ✅. Real key preserved in DB; no live
disruption from the smoke run.

---

## 2026-06-01 — Standalone /manage-subscription/ shipped

Coordinator commit f7ca461 deployed + verified end-to-end:

- **anon** → sign-in prompt
- **member** → Patreon-only read-only view (status pill, tier label, last/next charge, amount, "Manage on Patreon" CTA)
- **admin** → Patreon view + inlined `<iframe src="/__lg-stripe-panel/">` rendering `[lg_manage_subscription]` via the lg-membership-chrome mu-plugin
- `/__lg-stripe-panel/` directly: admin → 200; member → 403; anon → 403
- Clickjacking headers shipping on GET (`X-Frame-Options: SAMEORIGIN` + `Content-Security-Policy: frame-ancestors 'self'`) per the security review

See "Admin-gated Stripe section" details under the 2026-06-01 manage-subscription handoff entry for the full mechanism.

---

## 2026-05-31 — Standalone conversion (write-only, awaiting deploy)

Per coord relay (§0b enforces standalone — `template_include` boots WP on
every load, ~2.6s floor, shim doesn't fix). First slug converted out of the
mu-plugin into a real standalone surface following the events / archive-poc
/ bb-mirror pattern.

### Files staged

```
/home/ubuntu/projects/membership-pages/
├── README.md                ← layout + deploy checklist (sysadmin)
├── config.php               ← env detect + PDO + esc helper
├── lib/
│   ├── whoami.php           ← cached /whoami loopback + §0a ctx builder
│   └── guide-data.php       ← read-only lgms_guide_* options loader
├── web/
│   ├── membership-guide.php ← /membership-guide/ front controller (no WP boot)
│   └── membership-guide.css ← lightweight styles
└── nginx-snippet.conf       ← /etc/nginx/snippets/strangler-membership-pages.conf
```

### Pattern parity with events

| Concern | events | membership-pages |
|---|---|---|
| Source dir | `/home/ubuntu/projects/events/` | `/home/ubuntu/projects/membership-pages/` |
| FPM pool | `php8.3-fpm-events.sock` | `php8.3-fpm-membership.sock` (provision) |
| DB secret | `/etc/lg-events-db` | `/etc/lg-membership-db` (fallback to events secret on dev) |
| Header ctx builder | `lg_events_whoami()` → ctx | `lg_membership_whoami()` → `lg_membership_header_ctx('')` |
| Data | `event` CPT rows | `lgms_guide_*` wp_options |
| nginx | `^~ /events/` | `^~ /membership-guide/` (one block per slug, mirrors convention) |
| Snippet location | `/etc/nginx/snippets/strangler-events.conf` | `/etc/nginx/snippets/strangler-membership-pages.conf` |

### Coordinator deploy checklist (sysadmin)

1. `sudo install -m 0640 -o root -g www-data /dev/null /etc/lg-membership-db` then populate with `DB_NAME=looth_dev`, `DB_USER=…`, `DB_PASSWORD=…`, `DB_HOST=localhost`. **OR skip** — `config.php` auto-falls-back to `/etc/lg-events-db` for dev (both surfaces read wp_options read-only).
2. Provision `/etc/php/8.3/fpm/pool.d/membership.conf` mirroring `events.conf` shape (`listen = /run/php/php8.3-fpm-membership.sock`, user/group `www-data`). `sudo systemctl restart php8.3-fpm`.
3. `sudo cp /home/ubuntu/projects/membership-pages/nginx-snippet.conf /etc/nginx/snippets/strangler-membership-pages.conf` and add `include /etc/nginx/snippets/strangler-membership-pages.conf;` to `dev.loothgroup.com.conf` near the other strangler includes (around the existing `strangler-events.conf` line). `sudo nginx -t && sudo systemctl reload nginx`.

### Test steps — `/membership-guide/`

**Anon (loothdev_auth cookie only, no WP login):**
```
curl -s -b "loothdev_auth=$TOK" https://dev.loothgroup.com/membership-guide/
```
Expected: HTML body containing `lgms-mg-anon` body class, shared header without avatar / "Sign in" link in account slot, "What's inside" preview cards section, Council of Elders cards (avatar + name + "VIEW BIO"), Loothalong section with "See the plans →" CTA, PoC banner at bottom.

**Member (claude_admin cookies):**
Drive via CDP per `chrome-dev-login` skill — mint WP auth cookies for `claude_admin` (uid=1904), navigate to `/membership-guide/`. Expected: `lgms-mg-member` body class, shared header with avatar + "Admin" pill (manage_options caps), Loothalong CTA changes to "Join the room →" pointing at the configured URL.

**Header §0a compliance:**
- `active_nav` empty string (membership not in canonical top nav per §0d) — no nav item highlighted
- `logout_url` only set for authenticated viewers; null for anon (header skips logout UI)

### JS-heavy slugs — inventory + standalone plan

These need standalone page shells AND careful nonce/cookie handling for their REST callouts:

| Slug | JS pattern | REST endpoints called | Standalone plan |
|---|---|---|---|
| `/lgjoin/` | `loadProducts()` (3 inlined defs — Stripe Checkout redirect) | `/wp-json/lg-member-sync/v1/auth` + Slim `/billing/v1/checkout` | Page shell standalone; products lookup goes direct PDO from poller DB; auth still needs nonce-mint (see nonce note below) |
| `/lggift-buy/` | Same `loadProducts` pattern + gift recipient form | Slim `/billing/v1/checkout?gift=true` + WP `/v1/send-gift-recipient` | Same as `/lgjoin/` |
| `/lggift/` | Redemption form | `/wp-json/lg-member-sync/v1/gift-auth` | Page shell standalone; just emits the form, REST stays WP-side |
| `/manage-subscription/` | Plan-switch confirm modal, payment-method list, cancel-timing radio | `/wp-json/lg-member-sync/v1/me/*` (~7 endpoints) | Page shell standalone; subscription data direct PDO from `lg_membership` DB; REST stays WP-side |
| `/my-gifts/` | Send/resend/reassign/void modals | `/wp-json/lg-member-sync/v1/me/gift-*` | Same — list direct PDO, mutations via REST |
| `/welcome/` | `showWelcome()` modal reads `_lg_pending_welcome` user meta | `/wp-json/lg-member-sync/v1/dismiss-welcome` | **Hard case** — welcome modal currently fires from `wp_footer` (WP-side). Standalone needs to read meta via REST or direct DB and render the modal inline |
| `/affiliate-earnings/` | Admin form, withdraw flow | `/wp-json/lg-member-sync/v1/affiliate-withdraw` | Page shell standalone; affiliate data direct PDO from poller DB |
| `/request-refund/` | Form submission | `/wp-json/lg-member-sync/v1/refund-request` | Easy — mostly static page + form POST |
| `/regional-pricing-not-available/` | None | None | Pure static |
| `/test-checklist/` | Admin-only checklist UI | None (just renders) | Admin-only, deprioritize |

### REST endpoint de-WP-coupling — verdict: none needed

REST endpoints under `/wp-json/lg-member-sync/v1/*` and `/wp-json/looth/v1/*` already run as WP REST routes — they're invoked by JS on user interaction, not by every page render. WP-boot on a REST request is acceptable (REST init path is much lighter than full page render: no theme load, no enqueue chain, no template hierarchy). §0b is about the page-load floor; REST stays as-is.

**One coordination concern — WP nonces for JS-heavy pages:**
The REST endpoints use `permission_callback => [self::class, 'authLoggedInUser']` which checks `is_user_logged_in()` (cookie-based, no nonce needed for WP REST auth itself). But many of them validate a `_wpnonce` field defensively. Standalone pages don't have access to `wp_create_nonce()`. Two options:
- **(a)** Mint nonces server-side via a tiny WP loopback (`GET /wp-json/lg-member-sync/v1/nonce` returns a fresh nonce keyed to the current session) — one cold WP-boot per session, cached in `/dev/shm` thereafter
- **(b)** Drop nonce checks from these specific endpoints and rely on cookie auth + Origin header validation — cleaner long-term

Either is implementable; decision falls to coordinator since it touches the lg-stripe REST contract. Not blocking the page-shell standalone work; the easy slugs (membership-guide, regional-fail, refund-request) don't need it. Flagging early.

### Coexistence with the `template_include` mu-plugin

Left installed per coord directive. Mechanism:
- Once nginx reload picks up `^~ /membership-guide/` → the URL routes to standalone PHP before WP catch-all sees it; mu-plugin's `template_include` filter never fires for that slug
- Mu-plugin remains active for any slug still in `LG_MEMBERSHIP_CHROME_SLUGS` that hasn't been ported yet
- Emergency rollback: pull the nginx location block, slug falls back to WP-templated render automatically — no code change

### Lower-priority items (not started this turn)

- **P8 dormant-mode dev smoke** — still owed. Not started.
- **`lg_member_nav` cleanup (§3k)** — lg-shell's lane; this surface doesn't render a secondary nav strip. Pages.php createPage prefix-drop is still queued for the WP-side cleanup pass per the earlier handoff entry.

### Open questions for coordinator (flagged, none blocking deploy of this PoC)

**Q1 — Nonce strategy for JS-heavy slugs.** Per above — pick (a) loopback mint, (b) drop nonces, or (c) hybrid. Not relevant for membership-guide itself (no JS REST calls).

**Q2 — FPM pool isolation vs. reuse.** Should `php8.3-fpm-membership.sock` be its own pool (per the snippet) or alias to `php8.3-fpm-events.sock` to skip the pool-provisioning step on first deploy? Pool isolation is the architecturally correct shape; aliasing is faster to ship. I wrote the snippet assuming dedicated pool.

**Q3 — Welcome modal port (`/welcome/` slug).** Modal currently fires from `wp_footer` on the next WP-rendered page after a successful checkout. Once all membership pages are standalone, the modal needs its own port (small REST call to consume the meta + inline modal). Want coordinator's read on whether the modal stays on WP-rendered surfaces only, or ports to the standalone /welcome/ surface as part of this lane.

---

## 2026-05-29 — Membership chrome PoC (template_include — SUPERSEDED by standalone above)

Per `docs/briefing-membership-pages.md` + the rotation message. PoC scoped
to `/membership-guide/` per briefing's first-moves §1.

### Files added

| Path | Purpose |
|---|---|
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome.php` | mu-plugin bootstrap. Hooks `template_include` (priority 99) for page slugs in `LG_MEMBERSHIP_CHROME_SLUGS` const (currently `['membership-guide']`). Exposes `lg_membership_chrome_viewer()` which builds the `lg_shared_render_site_header()` ctx from in-process WP state (briefing alt path: `wp_get_current_user()` + role→tier mapping per §1, not `/whoami`). |
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome/template.php` | The custom template. `<!doctype>` → `wp_head()` → `<link rel="stylesheet" href="/lg-shared/site-header.css">` → `lg_shared_render_site_header($viewer)` → `<main class="lg-membership-chrome__main">the_content()</main>` → `lg_shared_render_site_footer([])` → `wp_footer()`. Calls `body_class('lg-membership-chrome')` so the poller's `addCustomerBodyClass` filter still fires the `lgms-mg-anon`/`lgms-mg-member` classes that the guide shortcode depends on. |

Permissions: `looth-dev:loothdevs 0664`. No changes to existing files,
no DB writes, no nginx changes.

### Header ctx — §0a compliant

- `authenticated` — `WP_User->ID > 0`
- `tier` — role walk `looth4|3→pro`, `looth2→lite`, `looth1→public`, none→`public`
- `display_name`, `avatar_url` (`get_avatar_url(uid, size=96)` or null on anon)
- `capabilities`: `manage_options`, `edit_archive_poc` via `user_can()`
- `msg_unread`, `notif_unread`: `null` (lazy-load via REST per the partial's contract)
- **`active_nav` — required per §0a. Set to `''`** (empty) — membership pages aren't in the canonical §0d top nav.
- **`logout_url` — required per §0a. `wp_logout_url($auth ? get_permalink() : home_url('/'))`** (nonce'd, returns to current page on auth, home on anon)
- `profile_url` — `/profile/edit` (matches the partial's new default; explicit for clarity)

### What coordinator needs to deploy / test

These files are already in place on dev (`/var/www/dev/...`). No deploy
step needed for dev verification — just load `/membership-guide/`
through the browser with `loothdev_auth` + admin/member cookies and:

1. Confirm the shared header renders at the top (logo + nav + account chip).
2. Confirm the guide content renders below it (Elders, Recurring Shows,
   Loothalong, etc. — the existing `[lg_membership_guide]` shortcode).
3. Confirm body has both `lg-membership-chrome` and (when logged-in)
   `lgms-mg-member` (or `lgms-mg-anon` when logged-out) classes. If the
   admin preview-toggle bar at the top-right of the guide page works,
   the body_class chain is intact.
4. **Visual audit:** any BB theme CSS fighting the shared shell? Notes
   doc covers this — for the PoC we left enqueue alone. Selective
   dequeue can land as a follow-up if needed.
5. Confirm sign-out from the account menu round-trips back to
   `/membership-guide/` (wp_logout_url is nonce'd; logout URL points
   to the current permalink).

If verified clean, the coordinator says go-ahead → I extend
`LG_MEMBERSHIP_CHROME_SLUGS` with the remaining 10 slugs (commented in
the bootstrap file). That extension is a one-array-edit, no logic change.

### Open questions for coordinator (flagged, not blocking the deploy)

**Q1 — §0b standalone invariant.** §0b explicitly excludes `template_include`
on WP pages from the launch-form pattern: *"A WP-templated page boots
WordPress on every load (slow, ~2.6s floor)."* The rotation message
endorsed `template_include` for this lane. I read this as: membership
pages get an exception (lower traffic / heavier per-page UX that's
hard to lift out of WP), with the eventual standalone conversion being
a separate effort. The PoC ships in `template_include` form. **Want
coordinator confirmation that this is the right read — or pointer to
the standalone approach if not.**

**Q2 — lg_member_nav fate.** Briefing decision #1 still open. The PoC
currently renders `[lg_member_nav]` inside `the_content()` because
every auto-seeded page has the shortcode baked into post_content. Three
choices:
- (a) Leave as-is — secondary nav strip under the shared header
- (b) Fold into the shared header itself — requires lg-shell changes,
  routes through coordinator (lg-shell owns site-header.php)
- (c) Remove from membership pages entirely — strip
  `[lg_member_nav]` from existing posts' content (one-shot SQL),
  drop the prepend in `Pages::createPage()`, leave the shortcode
  registered as a no-op for safety
- I have no strong preference; (a) is the lowest-risk for PoC.

**Q3 — Welcome-modal / `[lg_subscription_success]` chrome.** The
welcome modal hook (`showWelcome()`) reads `_lg_pending_welcome` user
meta and fires on `wp_footer()`. My template emits `wp_footer()`, so
the modal will still fire. But it was historically scoped to the
BB-themed `/welcome/` slug. When I add `welcome` to the slug list
post-PoC, the modal will render inside the new chrome too — likely
fine since it's a JS overlay, but flagging in case there's a known
issue.

### Not done (deferred)

- The other 10 membership slugs — pending Q1 + Q2 resolution.
- Dequeue passes on BB theme assets — pending visual audit.
- Cleanup of vestigial `template` field in `PAGES` registry — post BB-decommission.
- BB allowlist (`bp-enable-private-network-public-content`) coupling —
  irrelevant if BB plugin goes; live as-is until coordinator says strip.

---

## 2026-05-28 — round-trip verified ✓

Per `docs/reply-to-poller-purge-ready.md`. profile-app's exempt block
landed at `/etc/nginx/snippets/strangler-profile-app.conf` with
`allow 127.0.0.1; allow ::1; deny all`.

### Two adjustments needed on poller side to reach loopback correctly

**1. `LG_PROFILE_APP_URL` → `https://127.0.0.1`** (was `https://dev.loothgroup.com`).

Source IP needs to be 127.0.0.1 for nginx's allow rule. Going via
public hostname routes through the public IP (50.19.198.38), tripping
`deny all`.

**2. PurgeNotifier sets `sslverify=false` + explicit `Host` header.**

- `sslverify=false`: the site cert is for `dev.loothgroup.com`; cert
  validation against `127.0.0.1` would fail with no security benefit
  (the channel is shared-secret authed; TLS adds nothing the cert
  mismatch would protect on loopback).
- `Host: dev.loothgroup.com`: nginx selects server block via Host
  header / TLS SNI. Without the explicit Host, the request hits the
  default server block (cookie gate fires → 403). Added a
  `LG_PROFILE_APP_PUBLIC_HOST` constant (defaults to
  `dev.loothgroup.com`) so live can point at its own hostname.

Files: `wp-config.php` (one-line URL change), `src/PurgeNotifier.php`
(added sslverify + Host header + LG_PROFILE_APP_PUBLIC_HOST fallback).

### Smoke (2026-05-28)

End-to-end through Arbiter on qa_lite (uid=1906):
- Promote looth1→looth2 via manual_admin source → action fired →
  **POST to https://127.0.0.1/profile-api/v0/internal/purge-whoami → 204** ✓
- Revert looth2→looth1 (delete manual_admin row) → action fired →
  **204** ✓
- Both with empty response body, blocking=true forced just for the
  smoke; production path stays blocking=false / 1s timeout.

Captured-filter smoke retired — round-trip is now real.

### Cutover note for cross-cutting awareness

On live, `LG_PROFILE_APP_URL` + `LG_PROFILE_APP_PUBLIC_HOST` together
determine the routing:
- If profile-app is on the same box → keep loopback + public-host
  pattern; works as on dev.
- If profile-app is on a different host → set `LG_PROFILE_APP_URL`
  to its real URL and `LG_PROFILE_APP_PUBLIC_HOST` to its real
  hostname (cert validation can be re-enabled by removing
  `sslverify=false` from the wp_remote_post call).

This is a P4-adjacent config tweak; documenting here so it's not
forgotten at cutover.

---

## 2026-05-29 — membership-pages PoC (shared chrome) SHIPPED on dev

> **⚠️ COURSE-CORRECTED same day — see `docs/reply-to-membership-standalone.md`.**
> Launch invariant §0b (STRANGLER-COORDINATION.md) landed: launch pages are served
> **standalone** (nginx → standalone PHP → `require site-header.php`), NOT via
> `template_include` on a WP page — a WP-templated page boots WordPress every load
> (~2.6s floor) and the shim doesn't fix that. **So the `template_include`
> mu-plugin below is the RETIRED delivery mechanism — do NOT ship it to live.**
> What transfers to the standalone build (don't re-discover it): the viewer-ctx
> shape + `lg_viewer_tier()` reuse, the full shared-header consumer contract
> (`active_nav` + `logout_url`), and the confirmed BB-bypass / `body_class` /
> `wp_head`/`wp_footer` requirements — all proven here. The *render* is correct;
> only the *delivery* (WP template vs. standalone PHP reading `lg_membership`
> directly) changes. mu-plugin remains installed on dev as a render reference
> pending the standalone surface; pull it when that lands.

The membership-pages task's proof-of-concept is live on dev: `/membership-guide/`
renders on the unified `/srv/lg-shared/` header+footer, BuddyBoss theme chrome
bypassed. **Note on provenance:** the rotated poller sub-agent did the research +
design but its harness sandbox denied all writes/curl/wp-cli; per Ian's call this
PoC was built + verified from the coordinator session. Lane ownership of the code
stays poller — this section is the record.

### Shipped (the mechanism)

| File | What |
|---|---|
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome.php` | new — loader. `template_include` @ pri 99; matches `get_queried_object()->post_name` against the 11 registry slugs on `is_main_query() && is_singular('page')`; `is_readable()` guard on the shared partials → falls back to theme template (no fatal) if `/srv/lg-shared/` is absent. Also defines `lg_membership_chrome_viewer_ctx()`. Owner `www-data:www-data`, 0644. |
| `…/mu-plugins/lg-membership-chrome/template.php` | new — the template. doctype → `<head>` (`<link rel=stylesheet href="/lg-shared/site-header.css">` + `wp_head()`) → `<body class=body_class('lg-membership-page')>` → `lg_shared_render_site_header($ctx)` → `<main id="lg-main">` + the loop `the_content()` → `lg_shared_render_site_footer()` → `wp_footer()`. |

### Decisions made (and why)

- **Viewer state = in-process, NOT `/whoami`.** Reuses `lg_viewer_tier()` from the
  existing `lg-viewer-tier.php` mu-plugin (looth2→lite, looth3/4/admin→pro, else
  public) + `wp_get_current_user()` + `get_avatar_url()`. Keeps the pages rendering
  while profile-app/Stripe are dormant (B-now/A-later); no loopback HTTP hop. The
  `/whoami` shim is a passthrough to profile-app → wrong dependency for must-render
  pages.
- **`body_class()` is called** so the poller's `body_class` filters still fire
  (`Plugin::addCustomerBodyClass` → `lg-customer-only`). The membership-guide
  `is-member`/`is-anon` split is computed by the shortcode itself, so it does NOT
  depend on `body_class` — but the admin preview bar's hooks do.

### Verification (dev, 2026-05-29)

Both via curl with the cookie-gate cookie; member path with a minted front-end
`wordpress_logged_in_*` cookie for `claude_admin` (uid 1904).

| Check | Anon | Member (admin) |
|---|---|---|
| HTTP | 200 | 200 |
| Shared header (`<header class="lg-chrome">`) | 1 | 1 |
| `<main id="lg-main">` + shared footer, in order | ✓ | ✓ |
| BB masthead/nav (`#masthead`, `site-header`, `bb-menu-wrap`, `site-navigation`) | 0 | 0 |
| Header right-side | Sign in / Join | account "Claude Admin" + **Admin** tier pill + Edit |
| mg-elders (`.elders` ×1, `<a class="elder">` ×7 = `lgms_guide_elders`, `lgms-elder-{pic,name,cta}`) | ✓ | ✓ |
| `wp_head()` / `wp_footer()` fired (plugin assets present) | ✓ | ✓ |

### Caveat to resolve in the generalization pass (NOT a blocker)

`wp_head()` still enqueues the **BuddyBoss theme + Elementor CSS/JS** (the active
theme's filters fire even though its header/footer templates are bypassed). The
shared header renders correctly, but BB/Elementor stylesheets co-load (~150–250KB
page weight, possible visual conflicts). The clean fix when generalizing to all 11
slugs: dequeue BB-theme + Elementor assets on these page slugs (`wp_enqueue_scripts`
at high priority, or `wp_print_styles`/`dequeue` keyed on the membership slug set).
Left for the per-page pass since the briefing's PoC bar was "renders on the shared
header end-to-end," which is met.

### `lg_member_nav` cleanup — READY, not yet applied (per coord §3k)

§3k resolved Open Decision #1: `lg_member_nav` folds into the shared header's
account dropdown (lg-shell's lane), so it does NOT become a secondary strip. Two
edits staged for the generalization pass:
1. `Pages.php` `createPage()` (~:387-390) — drop the `[lg_member_nav]` prefix from
   the auto-seed body.
2. Keep `lg_member_nav` registered as a **no-op** (`Shortcodes::memberNav()` ~:5905)
   so the ~existing~ seeded pages don't render literal `[lg_member_nav]` text at
   cutover. (No-op is the safe route vs. SQL-stripping existing post_content.)

### Next moves in lane

1. Generalize the `template_include` mechanism across all 11 slugs (most are
   `page-fullwidth.php` today — the mu-plugin already matches them all; just needs
   per-page smoke, esp. the JS-heavy ones: `lg_join` loadProducts ×3, `lg_my_gifts`
   modals, `lg_manage_subscription` plan-switch, `lg_subscription_success` welcome
   modal).
2. Apply the BB/Elementor dequeue + the `lg_member_nav` cleanup above.
3. CDP pass on the JS-heavy pages (use `requestSubmit()`/click — never `form.submit()`).
4. P8 (poller dormant-mode dev smoke) — still not started.

## OPEN BUG — header nav horizontal shift (~83px), all surfaces (2026-06-11)
Flaky CLS ~0.116 on page load (events repro, likely site-wide): the header nav
LIs slide LEFT ~83px shortly after first paint (LayoutShift sources captured:
LI rects y=23.5 move x-83). No DOM mutation within ±120ms (MutationObserver
proven) -> resource/font-metrics race in the logo/wordmark cluster left of the
nav. Wordmark settles at 92px Georgia (Lora declared but no face ever loads).
Scrollbar component already fixed (scrollbar-gutter: stable in site-header.css).
Repro harness: CDP PerformanceObserver layout-shift script in session log /
tools/cdp-drive.py; flaky ~50% of cold loads at 1440px.
Next: bisect what the logo cluster renders as on a SHIFTING frame (screenshot
at ~150ms), or pin the cluster width. Small, well-specified; lane-sized.
