# AUDIT — Stripe membership: current state, risks, and a phased build plan

**Written 2026-07-30 by the `stripe-audit` lane.** Branch `stripe-audit`, cut from
`6dc65a1`. Read-only: every claim below came from **reading the code** and from
**read-only queries against live** (`ssh live-ro`; `looth_import`, `lg_membership`).
**Nothing was built. Nothing was changed. Nothing was touched on live.**

Ian's brief: *"time to start building [Stripe membership] out. We probably need to
audit the whole thing."* This is the audit half. §9 is the build plan to rule on.

---

## 0. Headline

**Stripe is frozen at the front door and wide open at the back.**

The Stripe *ingest* (polling Stripe's API) is genuinely off, behind two independent
locks. But the Stripe *egress* — the half that writes WP users and roles — is **not
behind either lock, and it runs every 5 minutes on live today.** I proved the tick
is live by watching it reschedule (§4.1).

Five findings that change what "build it out" means:

1. **The email-keyed minter is hot** — §3 of the poller audit called this. Confirmed,
   and now proven running. It is the single thing that must not survive to launch.
2. **41 of 44 `source=stripe` role rows on live are orphaned** — no customer, no
   bridge. **Three named live members are holding `looth3` on the strength of dead
   alpha data** while their Patreon source says `looth2`. This is new; §3 did not
   find it. It is *current, not latent* (§5.2).
3. **The tick runs blind.** Its entire log — every failure path — writes to a
   directory the PHP user cannot write, through `@`-silenced calls. There is no
   operational record of the Stripe pipeline on live at all (§5.4).
4. **The welcome email cannot send on live.** Fail-closed by design and correctly
   documented — but it is a launch-day switch that is currently OFF, and its failure
   mode is silent (§5.5).
5. **A real `sk_live_` Stripe secret key sits in live `wp_options`**, orphaned from a
   deleted plugin, on the *same Stripe account* the new system will use (§5.6).

Nothing here is on fire. But items 2 and 3 are live-data problems that exist right
now, independent of whether Stripe ever ships.

---

## 1. Method, and what I did *not* prove

Proven by direct observation:
- Every row count, schema, and index in §4 — live queries, exact `COUNT(*)`.
- The 5-minute tick is executing (§4.1) — watched the schedule advance.
- The three over-tiered members (§5.2) — named, with raw `wp_capabilities`.

**Not proven — stated as such where it matters:**
- **I did not talk to Stripe.** No API call, no dashboard. So I cannot say whether
  the one `active` subscription in our DB is still active *at Stripe* (§4.3), nor
  what the live webhook endpoints currently point at. Both need Ian or a key.
- **I could not read `/srv/lg-stripe-billing/.env` on either box** (mode `640
  www-data`; `live-ro` is not that user). Key *values* on live are unverified. The
  6/20 audit read dev2's and found test keys; I did not re-confirm.
- **I did not read live's `wp-config.php`** (permission denied), so `DISABLE_WP_CRON`
  on live is unconfirmed — though the tick demonstrably runs, which settles the
  question behaviourally.
- I did not exercise a checkout anywhere.

Where the 6/20 `stripe-poller-audit` doc (`origin/stripe-poller-audit`,
`docs/STRIPE-POLLER-AUDIT.md`) already established something for dev2, I read it and
did not redo it. Its §1 findings still hold except where noted in §7.

---

## 2. There are four Stripe surfaces, not one

Conflating these is the main way this system gets mis-reasoned about.

| # | Surface | Where | State |
|---|---|---|---|
| 1 | **Poller's Stripe ingest** — `lg-patreon-stripe-poller/src/Stripe/{Client,Poller,EventHandler}.php` | WP mu-plugin | **Frozen** (two locks, §3) |
| 2 | **Poller's Stripe egress** — `Tick` → `Sync` → `UserProvisioner` → `RoleSourceWriter` → `Arbiter` | WP mu-plugin | **RUNNING, every 5 min on live** |
| 3 | **`lg-stripe-billing`** — Slim app (checkout, webhook, gifts, affiliates) | `/srv/lg-stripe-billing` | **dev2 only. No route on live** (§6) |
| 4 | **PMPro + WooCommerce Stripe** — unrelated legacy plugins | live `wp_options` | **Dead plugins, live key left behind** (§5.6) |

The critical structural point: **surface 2 is downstream of surface 1's freeze
switch, not behind it.** Freezing Stripe ingest does not stop Stripe egress. That is
the shape of risk #1.

---

## 3. What the freeze actually covers

`Tick::run` (`lg-patreon-stripe-poller/src/Tick.php:56`):

```php
if ( (bool) get_option( 'lgms_stripe_frozen', true ) ) {   // default TRUE
    // skip Pass 1 entirely
}
```

**Lock A — `lgms_stripe_frozen`.** Verified **absent** from live `wp_options`, so
`get_option` returns the default `true`. Fail-closed by *absence*, which is the
safe direction. While frozen, nothing in `src/Stripe/` runs — no API call, no
`EventHandler` mail.

**Lock B — no key.** `lgms_stripe_secret_key` is also **absent** on live.
`Stripe\Client::__construct` (`src/Stripe/Client.php:41-44`) throws without it. So
even if Lock A were flipped, ingest would fail loudly rather than act.

Both locks are real and independent. **Neither covers Pass 2.** `Tick.php:177` calls
`Sync::all()` unconditionally, outside the freeze block.

---

## 4. Live state (all exact counts, 2026-07-30)

### 4.1 The tick is running — proof

`lgms_poll_tick` is scheduled at **300s (5 min)**, driven by WP-Cron. Two reads of
the `cron` option, 33 seconds apart:

```
read 1:  lgms_poll_tick next=2026-07-30T19:25:22Z   (NOW=19:25:05)
read 2:  lgms_poll_tick next=2026-07-30T19:30:22Z   (NOW=19:25:55)
```

WP only advances a recurring event's timestamp when it fires. **It fired at 19:25:22
and rescheduled.** The Stripe egress path executes for all 3 active customers,
roughly every 5 minutes, today. (`lgpo_patreon_auto_sync` is separately scheduled
`hourly`.)

### 4.2 `lg_membership` — exact counts

```
customers                4   (3 with deleted_at IS NULL)
wp_user_bridge           3
entitlements             7   (1 currently live: not revoked, not expired)
subscriptions            3   (1 status='active')
pending_sessions         6   (2 abandoned, 4 returned)
lg_processed_events    883
lg_role_sources        446 total — patreon 390, stripe 44, manual_admin 12
```

> **Do not read `information_schema.table_rows` here** — it is an InnoDB estimate and
> disagreed with every one of these (`customers` 5 vs 4, `entitlements` 68 vs 7,
> `subscriptions` 28 vs 3). All figures above are `COUNT(*)`.

### 4.3 The three bridged customers

| customer | wp_user | email | entitlements | subscription |
|---|---|---|---|---|
| 7 | 1 (Ian, admin) | ian.davlin@… | `looth2` **live** | `active`, period ended **2026-06-29** |
| 25 | 596 | Jamesroadman@… | none | none |
| 135 | 1003 (Buck) | vanlaarhovenguitars@… | all 6 revoked | 2, both `canceled` |

Customer 7's subscription row says `active` but its `current_period_end` passed a
month ago. **While ingest is frozen, our mirror of Stripe can never learn otherwise.**
Whether that subscription is still active at Stripe is unknown to this audit and
unknowable to the platform in its current state.

### 4.4 Schema notes that matter for the build

`wp_user_bridge` is **unique on both columns** — `PRIMARY KEY (customer_id)` and
`UNIQUE KEY uk_wp_user (wp_user_id)`. It is a strict 1:1. This is correct design and
it is also the cause of risk #3 (§5.3).

`customers.email` is `UNIQUE` — so two customers can never share an email. That
constrains, but does not eliminate, the collision in §5.3.

`lg_role_sources.updated_at` is `ON UPDATE current_timestamp()`. MySQL fires that
**only when a value actually changes.** So `updated_at` is *last-changed, not
last-checked* — the same trap as `lg_patreon_members.synced_at`. The stripe rows'
max `updated_at` of 2026-06-09 does **not** mean the sweep stopped; it means the
tiers have not changed since. The sweep is provably still running (§4.1).

---

## 5. Risks, ranked

### 5.1 R1 — the email-keyed minter (§3 of the poller audit, confirmed and now hot)

`UserProvisioner::findOrProvision` (`src/Wp/UserProvisioner.php:18`) keys on **email
and nothing else**:

1. `:21-28` bridged already? return.
2. `:31-35` `get_user_by('email', …)` → bridge and return.
3. `:39` **`wp_insert_user`** — mint.

It has never reached `:39` on live: all 3 bridge rows point at accounts that pre-date
their bridge by 1–3 years, so all took branch 2. That remains true.

**Why it is dangerous the moment onboarding opens.** The platform continuously
overwrites a member's WP email to their *current Patreon* email. A Stripe customer
whose Stripe email differs from their Patreon email — a second address, or an Apple
Private Relay alias, **both of which occur on live** — misses branch 2 and mints a
**second account**. No `patreon_id` check. No admin gate. No `looth_uid` check. The
duplicate-account problem the dupe-merge lane just spent a week cleaning up, with a
fresh source.

**This is the one non-negotiable item.** Ian's "build it out" resolves §3's fork: not
the fail-loud stub, but **route the lookup through shared Patreon-id-aware identity
matching** before any Stripe onboarding is reachable by a member.

### 5.2 R2 — 41 orphaned `source=stripe` rows; 3 live members over-tiered **now**

**New finding.** Of 44 `source=stripe` rows, only 3 correspond to a bridged customer.
**41 are orphans** from the decommissioned April–June alpha — the customers were
deleted, the role opinions were not.

`Arbiter::computeWinningTier` (`src/Arbiter.php:176-193`) takes the **highest tier
across all sources**. A stale stripe row therefore outranks a lower, *correct*
Patreon tier. Three real members, verified against raw `wp_capabilities`:

| wp_user | email | stripe row | patreon row | **actual role** |
|---|---|---|---|---|
| 1862 | swisherguitars@gmail.com | `looth3` | `looth2` | **looth3** |
| 1884 | jscatches@icloud.com | `looth3` | `looth2` | **looth3** |
| 1894 | dlaup2@gmail.com | `looth3` | `looth2` | **looth3** |

All three have `payment_source=patreon`, so the Arbiter's stripe-skip guard
(`Arbiter.php:51`) does **not** apply — the Arbiter runs, reads both sources, and
picks `looth3`. The trigger is live: the Patreon sweep calls
`\LGMS\Arbiter::sync()` at `includes/class-lgpo-sync-engine.php:914`.

These members are receiving a paid tier they do not pay for, indefinitely, because
of dead data. **This is current, not latent.**

A further 8 users carry a stripe tier with no persisted patreon row; whether the
stripe row is load-bearing for them depends on what `PatreonSourceReader` returns
live, which I did not evaluate per-user — **not proven, flagged**. The mirror risk
also exists: user 1825 has stripe `looth2` but actual `looth3`, so a stale row could
equally *downgrade* someone on the next Arbiter run.

> Cleaning these 41 rows is a **data fix, independent of the Stripe build**, and in my
> view should not wait for it. It is also exactly the kind of change that needs a
> journal — see §9 Phase 0.

### 5.3 R3 — a bridge collision fails silently and mis-assigns a tier

`writeBridge` (`UserProvisioner.php:83-87`):

```sql
INSERT INTO wp_user_bridge (customer_id, wp_user_id, synced_at) VALUES (?, ?, NOW())
ON DUPLICATE KEY UPDATE wp_user_id = VALUES(wp_user_id), synced_at = NOW()
```

The clause was written for the `customer_id` primary key. But `uk_wp_user` is *also*
unique. If customer B's email resolves to a WP user already bridged to customer A,
the conflict fires on `uk_wp_user`, and MySQL updates **A's row** (a no-op) — **B's
row is never inserted**, and no error is raised.

Result: B has no bridge, so every subsequent tick re-runs the email lookup, re-hits
the same WP user, and re-no-ops. Two customers now resolve to one WP user, and
`RoleSourceWriter::report` is called twice per tick for that user with two different
tiers — **last write wins, non-deterministically**.

Reachable because WP emails are rewritten to the current Patreon email: A bridges
while the user's email is A's, the mirror later rewrites it to B's, and B's next sync
collides. Unreached today (only 3 customers), but this is a correctness bug that
scales directly with customer count.

### 5.4 R4 — the tick has no log on live; failures are invisible

`Tick::run` writes every line — including `stripe poll FAILED`, `provision failed`,
`sync sweep FAILED` — to `LGMS_PLUGIN_DIR . 'tick.log'`, via `@file_put_contents`.

- `LGMS_PLUGIN_DIR` = `LGPO_PLUGIN_DIR` (`lg-patreon-onboard.php:26`) = the plugin
  directory, which on live resolves through the mu-plugin symlink to
  `/home/ubuntu/loothplatformv2-clean/lg-patreon-stripe-poller/`, owned
  **`ubuntu:ubuntu`, mode `drwxrwxr-x`**.
- WP runs as **`looth-dev`** (FPM pool `looth-dev.conf`); its groups are
  `looth-dev, www-data, loothdevs` — **not `ubuntu`**. Others have `r-x`. No write.
- **`tick.log` does not exist anywhere on live.** The only one on the box is
  `plugins/lg-member-sync.deprecated-2026-04-25/tick.log`, last written **Apr 25** —
  stale residue of the predecessor plugin.
- The `@` silences the failure. Nothing is logged about the fact that nothing can be
  logged.

So a pipeline that touches member roles every 5 minutes on production has **zero
operational record**. Any Stripe build multiplies what this blindness costs. (Good
news: `*.log` is gitignored, so this never threatened the serving checkout.)

### 5.5 R5 — member mail is suppressed on live; the welcome email is a launch switch

Two independent gates suppress any `wp_mail` whose call stack runs through
`/lg-patreon-stripe-poller/`:

- `Plugin::gateOutboundMail` (`src/Plugin.php:273-293`) — the poller's own gate.
- `platform/mu-plugins/lg-poller-mail-killswitch.php` — belt-and-braces.

Both bypass on the header `X-LG-Poller-Intent`, and both key on
`lgms_poller_mail_enabled`, which is **absent on live** → both default to suppress.

`WelcomeMailer::headers()` (`src/Wp/WelcomeMailer.php:161-167`) returns only
`Content-Type` and `From` — **no `X-LG-Poller-Intent`**. It is called from
`Arbiter::sync` (`Arbiter.php:120`), inside the plugin. **Therefore the membership
welcome email cannot be delivered on live today.**

This is *by design* and honestly documented ("Ian flips it ON at launch with NO
redeploy", `Plugin.php:257`). I flag it because the failure mode is silent — a new
member is minted, provisioned, granted a role, and told nothing, with only an
`error_log` line that nobody reads (see R4). **It belongs on a launch checklist, not
in a code comment.**

Worth noting the killswitch mu-plugin is stamped `@lg-dev-only DO NOT DEPLOY TO
PROD`, yet is symlinked into live's mu-plugins. Its self-disable is keyed on the same
absent option, so on live it does **not** self-disable. Harmless while the poller's
own gate says the same thing — but the file is doing the opposite of what its header
claims, which will mislead the next reader.

### 5.6 R6 — a real `sk_live_` key is sitting in live `wp_options`

```
pmpro_live_stripe_connect_secretkey      sk_live_51LJOi5Hg6gcIV22b…   (107 chars, autoload=off)
pmpro_live_stripe_connect_publishablekey pk_live_…
pmpro_sandbox_stripe_connect_secretkey   sk_test_…
woocommerce_stripe_api_settings          (autoload=ON)
```

Neither plugin is active: PMPro is **absent from `active_plugins` and its directory is
gone**; WooCommerce is present on disk but deactivated. These are orphaned rows.

The account id `acct_1LJOi5Hg6gcIV22b` is **the same account** `lg-stripe-billing`
targets. So this is a live secret key, for the account the new system will use,
persisting in a database that gets dumped and copied between boxes, belonging to
software that no longer exists.

**Recommend: Ian rotates/revokes it in the Stripe dashboard, then the rows are
deleted.** Rotate first — deleting the row does not invalidate the key. This is
Ian's action (live write + Stripe login).

Separately, `lgms_db_pass` (the `lg_membership` password) is stored with
`autoload=auto`, so it loads into memory on every request. Lower severity; worth
moving to `wp-config.php` when the app is next touched.

### 5.7 R7 — `lg-stripe-billing` is outside the deploy model, and dead on live

On **both** boxes `/srv/lg-stripe-billing` is a **real directory with its own `.git`**,
not a symlink into `~/loothplatformv2-clean` like every other app in `/srv`. It is
therefore **not covered by "deploy is one pull."** (I could not read its git state on
live — `dubious ownership` under `live-ro`.)

On dev2 the deployed copy matches the monorepo copy in `src/`, `bin/`, `config/`,
`db/`, `public/`, `deploy/`, `docs/`; only `.gitignore` and `PICKUP.md` differ, plus
untracked `.env`, `logs/`, `vendor/`, `.git`. So **no source drift on dev2 today** —
but nothing structurally prevents it.

**On live the app is unreachable.** There is no `/billing` location anywhere in
live's `/etc/nginx`. Its FPM pool (`lg-billing-dev.conf`, `user = www-data`) **is
configured on live**, so there is a live pool serving a route that does not exist.
On dev2 the route is present and current (`location ^~ /billing/`, fixed 2026-07-26
under HK-002/#40 — the old `^~ /billing` swallowed WP slugs like `/billing-faq/`).

Consequence for the build: **shipping the Slim app to live is an unsolved deploy
step**, not a pull. That belongs in the plan explicitly.

### 5.8 R8 — the poller's mu-plugin loader is a copy, not a symlink

`/var/www/dev/wp-content/mu-plugins/lg-patreon-stripe-poller.php` on live is a
**regular file** (`-rw-r--r-- looth-dev`, Jun 27), while every sibling mu-plugin is a
symlink into the serving checkout. I diffed it: it is **byte-identical** to both
repo copies today, so there is no drift right now.

But it is structurally decoupled — **a future edit to the repo copy will not reach
live via `git pull`**. Given this file is the loader for the entire poller, that is a
trap worth closing before the build adds churn to it.

### 5.9 R9 — stale docs that will mislead the build

- `lg-patreon-stripe-poller/CLAUDE.md` still points at the retired dev box
  (`ssh ccdev@54.157.13.77`, `/home/ccdev/lg-stripe-billing/`) and says the tick is
  **hourly** — it is 5-minutely on live. It also tells the next session to read
  `PICKUP.md` first, which inherits the same staleness.
- `Tick.php:14` docblock likewise says "Runs hourly."
- The 6/20 audit's dev2 `.env` gap (all 7 URLs pointing at the retired
  `dev.loothgroup.com` box) is **unverified by me** — I could not read `.env`. If it
  has not been fixed since, it still blocks any dev2 end-to-end test.

---

## 6. What is live, what is dead, what is frozen

| Component | Verdict |
|---|---|
| `Tick` Pass 2 → `Sync::all` → `UserProvisioner` → `Arbiter` | **LIVE** — every 5 min, 3 customers |
| `Tick` Pass 1.5 expiry sweep | **LIVE** (no gift entitlements to sweep) |
| `Tick` Pass 1.7 reconcile-pending | **INERT on live** — `lgms_shared_secret` absent → skipped before any HTTP |
| `Tick` Pass 1 Stripe poll | **FROZEN** — two locks (§3) |
| `src/Stripe/{Client,Poller,EventHandler}` | **FROZEN** — unreachable while Pass 1 is skipped |
| `lg-stripe-billing` on **dev2** | **LIVE** — routed, FPM pool active, test-mode |
| `lg-stripe-billing` on **live** | **DEAD** — deployed to `/srv`, FPM pool exists, **no nginx route** |
| PMPro / WooCommerce Stripe | **DEAD** — plugins inactive; **live key still in the DB** (§5.6) |
| 41 orphaned `source=stripe` rows | **DEAD DATA, LIVE EFFECT** (§5.2) |
| Stripe webhook signature verification | **CORRECT** — verified, 400 on bad sig |

On the last row: `WebhookController.php:41-43` calls `constructWebhookEvent($payload,
$sig, $secret)` and returns **400 `Invalid signature.`** on
`SignatureVerificationException`. Secret comes from `STRIPE_WEBHOOK_SECRET` via
`EnvSettingsStore`. This is the one part of the Stripe surface that is unambiguously
built right, and the build plan should not disturb it.

---

## 7. Patreon coexistence — how the two systems are meant to not fight

Three separate guards, all keyed on the `payment_source` user meta:

1. **Sweep skip** — `class-lgpo-sync-engine.php:581-584`: the Patreon sweep skips a
   user with `payment_source=stripe` **and** an active paid role.
2. **Reader skip** — `PatreonSourceReader` returns `null` for a non-`patreon`
   `payment_source`, so a Stripe member contributes no Patreon opinion.
3. **Arbiter skip** — `Arbiter.php:51-54`: a `payment_source=stripe` user with no
   `looth1` role and no persisted stripe row is **skipped** rather than downgraded.

The design is coherent: `lg_role_sources` is a per-source opinion table and the
Arbiter takes the max. **The flaw is not in the guards — it is that nothing ever
retracts an opinion when its source dies.** That is precisely §5.2. Any Stripe build
must add a retraction path (cancel/refund/delete → revoke the row), or it will
manufacture more of the same orphans at production volume.

Note guard 3 is keyed on `payment_source`, which the *Stripe* pipeline never sets —
`UserProvisioner` does not write it (only the Patreon paths at
`lg-patreon-onboard.php:1571,1634` do). So a Stripe-minted member would today have
**no** `payment_source`, and none of the three guards would recognise them. That gap
must close in the same change as R1.

---

## 8. The build plan — phased, for Ian to rule on

Ordered so that **every phase is independently shippable and leaves the system safer
than it found it.** Phases 0–1 are worth doing even if Stripe membership is later
shelved.

### Phase 0 — data hygiene, no code (independent of Stripe)
1. **Rotate the `sk_live_` key** in the Stripe dashboard (Ian), then delete the four
   orphaned `pmpro_*` / stale `wc_*` option rows. *Rotate before delete.*
2. **Revoke the 41 orphaned `source=stripe` rows**, with a journal per row so it is
   reversible — same pattern the dupe-merge lane used. Expect 3 members to drop
   `looth3 → looth2`; **that is the correction, but it is member-visible, so it is
   Ian's call whether to notify them.** I would grandfather the 3 rather than claw
   back, and delete the rows either way so the state stops being accidental.
3. Decide the fate of the 8 ambiguous users in §5.2 (needs a per-user
   `PatreonSourceReader` read first — small, scriptable, read-only).

### Phase 1 — make the running system observable and safe (no Stripe behaviour)
4. **Fix the tick log.** Move it out of the repo-owned plugin directory to a path
   `looth-dev` can write (e.g. `wp-content/uploads/lg-logs/` or syslog), and **drop
   the `@`** so a write failure surfaces. Without this, everything after is unverifiable.
5. **Fix `writeBridge`** (R3) — make the `uk_wp_user` collision an explicit, loud
   failure instead of a silent no-op.
6. **Symlink the mu-plugin loader** (R8) so the poller is covered by one pull.
7. Correct the stale docs (R9): `CLAUDE.md`, `PICKUP.md`, `Tick.php:14`.

### Phase 2 — the identity gate (the actual prerequisite for Stripe)
8. **Build the shared `match()` front door** that §6 of the poller audit specified,
   and **route `UserProvisioner` through it.** Patreon-id-aware, `looth_uid`-aware,
   email as one signal among several — never the only one.
9. **Set `payment_source=stripe`** on any Stripe-provisioned user, so the three
   coexistence guards actually recognise them (§7).
10. **Add source retraction** — a cancelled/refunded/deleted Stripe customer must
    revoke its `lg_role_sources` row, not merely stop refreshing it.
11. Until 8–10 land, keep the mint branch **fail-loud**: `lgpo_notify_failure` +
    `RuntimeException` rather than `wp_insert_user`. This is §3's recommended interim
    and it costs ~5 lines; it should go in *first*, not last, so the window between
    "we started building" and "identity is safe" is never open.

### Phase 3 — the Slim app's road to live
12. Decide the deploy model for `lg-stripe-billing` (R7): fold it into the monorepo
    and symlink it like every other `/srv` app, or keep it standalone with an
    explicit, documented deploy. **Recommend folding in** — "if it is not in the
    monorepo and traceable to a commit, it does not exist."
13. Add the `/billing/` nginx location to live's vhost, mirroring dev2's
    trailing-slash form (the HK-002 fix), and behaviourally test that WP slugs like
    `/billing-faq/` still reach WordPress.
14. Fix and verify the `.env` host URLs on dev2 (6/20 §1.3 item 1 — **unverified by
    me**), then run a full `4242` test checkout end-to-end on dev2 before any live key
    exists anywhere.
15. Confirm Stripe's webhook POSTs are not Cloudflare-challenged (6/20 §1.3 item 3,
    still open). A WAF skip rule for `/billing/v1/webhook` may be needed.

### Phase 4 — launch switches (a checklist, not code)
16. `lgms_poller_mail_enabled` → ON (R5), **or** tag `WelcomeMailer` with
    `X-LG-Poller-Intent`. Prefer the flag; it is the designed control.
17. `lgms_stripe_frozen` → falsey, and `lgms_stripe_secret_key` set — **in that order,
    with the identity gate already live.**
18. `lgms_shared_secret` + `lgms_billing_base_url` set, so reconcile-pending works.
19. Reconcile the stale subscription mirror (§4.3) against Stripe on first unfreeze —
    expect corrections, and make sure they are logged (which requires item 4).

### My recommendation on sequencing

**Phases 0 and 1 now, regardless of the Stripe decision.** They fix live-data and
observability problems that exist today and are cheap. **Phase 2 before any Stripe
onboarding is reachable by a member** — that is the hard gate. Phases 3–4 follow the
product decision.

---

## 9. Open questions for Ian

1. **The 3 over-tiered members** (§5.2) — grandfather them at `looth3`, or correct to
   `looth2`? Notify, or silent? *This is the only member-visible item in the plan.*
2. **Is Stripe membership replacing Patreon, or running alongside it indefinitely?**
   The answer changes item 10 substantially — a permanent dual-source world needs a
   real retraction protocol; a migration needs a cutover instead.
3. **Fold `lg-stripe-billing` into the monorepo?** (item 12) — my recommendation is
   yes, but it is a real chunk of work and it is your call.
4. **Is that `sk_live_` key still needed by anything?** I found no active consumer,
   but rotating it is your action and I did not want to assume.

---

## 10. Provenance

- Code read at branch `stripe-audit` (from `6dc65a1`). Line references are against
  that tree.
- Live reads: `ssh live-ro`, 2026-07-30 ~19:00–19:30 UTC. `looth_import` confirmed as
  the real WP DB (`siteurl=https://loothgroup.com`); `looth_dev` is the decoy
  (`dev.loothgroup.com`). Host `ip-172-31-67-175`.
- Prior work read and not redone: `docs/atlas/POLLER-ONBOARDING-AUDIT.md` §3,
  `origin/stripe-poller-audit:docs/STRIPE-POLLER-AUDIT.md` (2026-06-20).
- **Nothing was written to live. Nothing was built. The serving checkout was not
  touched.**
