# Email Audit — every send path, both incidents, safe re-enable plan
**Lane:** email-audit · branch `email-audit` off main · dev2 only · FluentSMTP simulation = **ON** (verified `misc.simulate_emails = yes`)
**Date:** 2026-06-20 · read-only audit + controlled inspection (no sends, no main writes)

---

## TL;DR
- **Incident (a) — erroneous live poller email:** an *existing* member got a "Welcome / Set Your Password" email as if newly created. **Two independent triggers cause this**, only one is being fixed:
  1. **Onboard re-welcome** (`lg-patreon-onboard.php`): a repeat Patreon connect re-ran the new-user path → re-sent the welcome+set-password mail. **Already fixed in the in-flight `lane/login-poller` branch** (adopt-existing + "you're logged in now"); **NOT yet in main** → live still has the bug. Fold in, don't duplicate.
  2. **Arbiter re-welcome** (`Arbiter::sync` → `WelcomeMailer::sendIfNeeded`): trigger is **state-derived** (current WP role vs computed winner), not event-derived. Any role-state perturbation (DB reload, admin role edit via `AdminRoleCapture`) makes an established member look "newly upgraded" → welcome fires. Only guard is `_lg_welcome_email_sent_at`, which is **absent for legacy members and wiped by a full DB reload**. **Not addressed by login-poller — still open.**
- **Incident (b) — double event reminder:** the **Showrunner Apps Script** (`loothdev-sheets-bridge.apps-script.gs.txt`) sends 21/14/7-day pre-air reminders **via GmailApp from Google — it does NOT go through wp_mail/FluentSMTP**, so the simulation toggle never gated it. Dedup is a soft 6-day cooldown + non-atomic read-then-stamp, with **no per-(row,tier) sent flag and no cross-account trigger guard**. Double-fire happens when (i) more than one daily trigger exists (each authorizing Google account installs its own — `installDailyTrigger` only sees the current user's triggers), and/or (ii) a manual "Send Reminders Now (live)" collides with the trigger, and/or (iii) Apps Script at-least-once trigger semantics. The materializer (the prime suspect in the brief) is **NOT** involved — see below.
- **Two paths bypass FluentSMTP entirely** (so the simulation toggle and any wp_mail kill-switch do nothing to them): the Showrunner Apps Script (GmailApp) and the archive-poc feedback modal (raw PHP `@mail()`). These must be handled separately in any re-enable.
- **Materializer is exonerated:** `lg-article-materializer` re-materializes events on save but the `_materialize` endpoint never touches `_lg_push_reminded` or any reminder/CRM state. It cannot re-arm or re-send reminders.

---

## 1. Path-by-path map (trigger · recipient · transport · dedup/idempotency · gotchas)

### A. Patreon / poller (`lg-patreon-stripe-poller`)
| Path | Trigger | To | Transport | Dedup | Gotcha |
|---|---|---|---|---|---|
| **WelcomeMailer::sendIfNeeded** (`src/Wp/WelcomeMailer.php`) | `Arbiter::sync` when `isUpgradeToPaid(old,new)` (old=current WP role, new=computed winner). Callers: `UserLifecycle:282` (sweep/reconcile) + `AdminRoleCapture:74` (admin edits a user's roles) | the user's own email | wp_mail → FluentSMTP | `_lg_welcome_email_sent_at` user-meta sentinel | **INCIDENT (a) #2.** State-derived trigger + sentinel absent for legacy members / wiped on DB reload → re-welcomes established members. Admin role edit fires it instantly. |
| **Onboard welcome + set-password** (`lg-patreon-onboard.php:1187`) | New user minted at end of Patreon OAuth connect | patreon_email | wp_mail | none on main (only "is this a brand-new user" by falling through adopt branches) | **INCIDENT (a) #1.** On main, repeat connect re-runs new-user path → re-welcome. **Fixed in `lane/login-poller`** (adopt-existing returns before the mail). |
| **WelcomeMailer::sendTest** | Admin "preview" bar (Membership Guide) | arbitrary admin-typed address | wp_mail, `[TEST]` subject | n/a (does not set sentinel) | A typo / paste of a real member address sends a real-looking welcome. Admin-driven. |
| **lgpo_alert_failure** (`onboard.php:797`) | poll/onboard failures (token expiry, API 401, drift) | `lgpo_contact_email` → admin_email | wp_mail | none (best-effort) | Noisy on outages; fine. |
| **lgpo_notify_admin** (`onboard.php:1255`) | onboard email/admin collision | admin_email | wp_mail | none | ok |
| **GiftMailer** (`src/Wp/GiftMailer.php`: buyer summary, recipient mail, dashboard summary) | gift purchase / dashboard send (`RestController:266`) | buyer + each recipient | wp_mail | none explicit — relies on caller firing once | Re-running a gift send re-emails codes. Verify caller is one-shot. |
| **RestController self-action / gift-auth** (`:459`, `:1141`, `:1793`) | member self-serve actions / admin | member or admin | wp_mail | per-action | ok |
| **Stripe EventHandler** (`src/Stripe/EventHandler.php:258` payment_failed, `:294` trial_will_end) | Stripe webhooks `invoice.payment_failed`, `customer.subscription.trial_will_end` | customer email | wp_mail | **none — keyed only on the webhook firing once** | Stripe re-delivers webhooks on 2xx timeout/retry. No idempotency on event id → a retried webhook re-emails. Add an event-id guard. |
| **sync-engine admin summary** (`includes/class-lgpo-sync-engine.php:784`) | sweep summary | admin_email | wp_mail | none | ok |

### B. Stripe billing (`/srv/lg-stripe-billing`, separate Slim app)
| Path | Trigger | To | Transport |
|---|---|---|---|
| `WpGiftMailer::sendGiftCodes / sendOneRecipient` | gift checkout success (`GiftActionController::send`) | buyer + recipients | WP loopback → wp_mail |
| `ReturnHandler` / `WpSync` mail | post-checkout sync | customer/admin | wp_mail |
> Gift sends here have **no code-level dedup** — idempotency depends on the checkout/return handler running once per session. A double return-URL hit or webhook+return both completing could double-send. Worth a sent-flag keyed on the Stripe session id.

### C. Events / reminders
| Path | Trigger | To | Transport | Dedup | Gotcha |
|---|---|---|---|---|---|
| **Showrunner reminders** (`loothdev-sheets-bridge.apps-script.gs.txt` → `sendReminders_`) | daily 8am Apps Script trigger (`installDailyTrigger`) + manual menu "Send Reminders Now (live)" | showrunner (+ Max @14/7d, +Ian @7d/overdue) | **GmailApp (Google) — BYPASSES FluentSMTP** | soft 6-day cooldown on `LAST_REMINDER` col, non-atomic read→stamp | **INCIDENT (b).** Multiple triggers across authoring accounts, manual+auto collision, at-least-once semantics → duplicate. No per-(row,tier) sent flag. Not gated by sim toggle. |
| **Push event reminder** (`lg-push/run-event-reminders.php`) | `sudo php … run-event-reminders.php` cron (web push, not email; **not cron-scheduled on dev2**) | push subscribers | WebPush (VAPID) | `_lg_push_reminded` postmeta via LEFT JOIN | Plain `INSERT` (not idempotent) + no lock → two overlapping cron ticks both SELECT before either writes → double push. On live verify it isn't double-cron'd. |
| **FluentCRM "Event Reminder Email List" (id 4)** | `lg-event-reminders.php` only manages list membership (one-click signup). Actual sends = **manual FluentCRM campaigns** | list 4 members | FluentCRM → FluentSMTP | none (human-driven) | A human sending the same campaign twice = double. No code guard possible; relies on operator. |
| **Weekly digest** (`plugins/lg-weekly-digest`, `LG_WD_Cron`) | `wp_schedule_single_event` weekly; `auto_send` or `draft_and_notify` | CRM audience / admin | wp_mail / FluentCRM | `schedule()` clears before re-adding (idempotent-ish) | `fire()` reschedules at end AND `lg_wd_settings_saved` reschedules — saving settings mid-cycle can shift the next fire. Low risk. |
| **Weekly non-member double opt-in** (`lg-event-reminders.php` `lg_weekly_signup`) | anon /weekly/ signup | the signer | FluentCRM double-optin | already-subscribed short-circuits re-confirm; per-IP 5/hr | ok |

### D. WP / misc
| Path | Trigger | To | Transport | Gotcha |
|---|---|---|---|---|
| **snippet #39** (`lg-snippets/snippets/39.php`) | `transition_post_status` → `pending` | admin_email, `gerry@hazeguitars.com`, **`manager@yoursite.com`** | wp_mail | **Hardcoded placeholder recipient `manager@yoursite.com`** fires on every pending post. Bogus address — fix/remove. |
| **bug-report** (`bug-report/lg-bug-report.php:156`) | bug modal POST | `ian.davlin@gmail.com` | wp_mail | ok |
| **archive-poc feedback modal** (`archive-poc/web/index.php:208`) | feedback POST | `ian.davlin@gmail.com` | **raw PHP `@mail()` — BYPASSES FluentSMTP** | On dev → mailpit; on live → sendmail/SES directly, ungated. (NB: front-page feedback was separately moved to a wp_mail loopback per memory — this archive-poc index.php path still uses raw mail().) |
| FluentForms / FluentCRM campaigns | form submit / manual campaign | varies | FluentSMTP | standard plugin behavior; campaigns are operator-driven (no code dedup). |

---

## 2. Incident root-causes (detail)

### (a) Erroneous live poller email — established member got "Welcome / set your password"
Recipient was structurally **correct** (the user's own email); the **send was wrong** — they didn't just join/upgrade. Two triggers:
1. **Onboard path (main):** repeat Patreon connect fell through to the new-user branch and re-sent welcome+set-password. The `lane/login-poller` branch fixes this: `existing_by_patreon` / `existing_by_email` now `lgpo_adopt_existing_user()` and return *before* the mail, with a "You're logged in now" terminal (commits `62203b7`, `5115418`). These are **ahead of main, not merged** → live still vulnerable until merged.
2. **Arbiter path (open):** `Arbiter::sync` computes `$oldTier` from current WP roles and fires the welcome when `isUpgradeToPaid(old,new)` (`old===null → true`, or `new>old`). After a DB reload / role wipe / manual admin role edit, an existing paid member momentarily reads as null-tier → "fresh upgrade" → welcome. The `_lg_welcome_email_sent_at` sentinel is the only backstop and is missing for pre-WelcomeMailer members and wiped by a full reload. Squares with the known "DB reload breaks tier 4 ways" casualty pattern.
**Reproduce on dev2 (sim ON):** (i) take a member with a paid role + a payment source row, `delete _lg_welcome_email_sent_at` and remove their looth2 role, run `Arbiter::sync($id)` → observe `sendIfNeeded` would fire (FluentSMTP simulate logs it, nothing leaves). (ii) For the onboard path, run a second Patreon connect for an already-linked test user on main vs login-poller and compare.

### (b) Double event reminder — Showrunner Apps Script
`sendReminders_()` dedup is a 6-day cooldown read from `LAST_REMINDER`, with the stamp written **after** the GmailApp send (non-atomic). Failure modes, any of which double-sends:
- **Multiple daily triggers**: each Google account that opens the sheet and runs "Install Daily Reminder Trigger" creates its own `sendRemindersLive` time trigger; `installDailyTrigger` deletes only *the current user's* matching triggers, so it cannot dedupe across owners → N owners = N daily fires.
- **Manual + auto collision** on the same morning before the cooldown stamp commits.
- **Apps Script at-least-once** trigger execution.
The materializer hypothesis is **disproven**: `archive-poc/api/v0/_materialize.php` rebuilds the render blob only; it never writes `_lg_push_reminded`, FluentCRM lists, or any reminder state, and the dispatcher already de-dupes per (post,action)/request.

---

## 3. Prioritized fix list

**P0 — before re-enabling real mail**
1. **Showrunner reminders (b):** replace the cooldown with a **per-(row, tier) sent ledger** — write a unique key like `sent:{rowId}:{tierLabel}` and check it before sending; make the check+stamp the first thing inside the per-row loop. Guard `installDailyTrigger` against duplicates (document "only ONE Google account owns the trigger"; optionally store an owner email in Script Properties and refuse to install from another). Because this bypasses FluentSMTP, it must be fixed in the script itself — the sim toggle won't protect it.
2. **Merge `lane/login-poller`** to land the onboard adopt-existing dedupe (incident a #1) — coordinate with that lane; do not re-implement.
3. **Arbiter welcome guard (a #2):** make the trigger event-derived, not state-derived. Minimum: (i) **backfill `_lg_welcome_email_sent_at`** for all current paid members so legacy members can never be re-welcomed; (ii) gate `sendIfNeeded` so it only fires when the upgrade is backed by a *fresh* source transition (not a role recompute after a wipe), e.g. require account age / source-added timestamp within N hours, or skip when `AdminRoleCapture` is the caller.

**P1**
4. **Stripe webhook idempotency** (`EventHandler` payment_failed / trial_will_end): store processed Stripe event ids and no-op on replay.
5. **archive-poc feedback** (`index.php:208`): route through wp_mail (so it honors FluentSMTP + the kill-switch) instead of raw `@mail()`.
6. **snippet #39:** remove `manager@yoursite.com` (and confirm gerry@ is wanted).

**P2**
7. Gift sends (poller + lg-stripe-billing): add a sent-flag keyed on Stripe session / order id.
8. Push reminder: `INSERT … ON CONFLICT DO NOTHING` + a row lock or `SELECT … FOR UPDATE` to close the overlapping-cron race; confirm single cron on live.

---

## 4. Safe re-enable plan (turn real email back on without re-triggering either incident)
1. **Keep FluentSMTP simulation ON** until P0 (1–3) are merged and verified on dev2.
2. **Land P0 fixes**; on dev2 with sim ON, exercise: a repeat Patreon connect (expect NO welcome mail in the FluentSMTP simulate log), an Arbiter role-wipe+resync on a legacy member (expect NO welcome — sentinel backfilled), and a Showrunner dry-run twice in a row (expect the 2nd to skip via the sent ledger).
3. **Backfill `_lg_welcome_email_sent_at`** on the LIVE DB for all existing paid members *before* flipping sending on (one-shot SQL) — this is the single most important guard against a mass re-welcome.
4. **Neutralize the two bypass paths first:** confirm the Showrunner sheet has exactly ONE daily trigger (list triggers, delete extras) and that the fixed script is deployed; switch archive-poc feedback to wp_mail (or accept it's a separate channel).
5. **Staged flip on live:** set FluentSMTP `simulate_emails = no` but first **scope the audience** — temporarily point all transactional From/routing at a single internal address (or enable FluentSMTP's logging) and watch one full poll sweep + one Stripe webhook + one daily Showrunner run with no duplicates and no re-welcomes. Then open to real recipients.
6. **Keep a kill-switch:** the FluentSMTP `simulate_emails` toggle is the wp_mail-wide kill-switch — but document clearly that it does **not** cover the Apps Script (Google) or any raw `mail()` path. A true global kill needs either both covered or those paths moved onto wp_mail.

## 5. Coordination
- Incident (a) onboard fix is owned by **`lane/login-poller`** — folded in here, not duplicated. Recommend keeper sequences: login-poller merge → Arbiter guard + sentinel backfill → Showrunner script fix → staged re-enable.
