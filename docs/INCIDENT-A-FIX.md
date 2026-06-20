# Incident (a) — erroneous member welcome emails: investigation + fix
**Lane:** email-audit · branch `email-audit` off main · committed-not-pushed · for keeper review
**Date:** 2026-06-20

## What happened
A reconcile sweep re-fired the "Welcome — your membership is active" email to **established** members,
treating them as fresh upgrades.

### Evidence (live, confirmed by Ian; dev2 mirror identical off the recent AMI)
`_lg_welcome_email_sent_at` user-meta = the WelcomeMailer delivery sentinel. Timeline:
- **2026-06-17 23:25 UTC — 12 stamps in ~9 seconds** = one reconcile sweep. The IDs are founding
  members (registered 2023): Gary Brawer (61), Jiri Markalous (174), Larry Fitzgerald (244), Cotrus
  Vlad (245), Casey Murray (337), Jason Portier (388) + ERO (1289), Moses McKinley (1388), Evan (1403),
  John Catches (1884), David Laupmanis (1894), and 1 QA test acct (1911, @example.com — harmless).
- **2026-06-20 04:15 UTC — 2 stamps**: Marko (1096, joined 2024-10), Jayr Mendez (1654, joined 2025-11).
- **Total 14 stamps; ~13 real people**, all pre-existing members — zero genuine new joins.

### Delivered or caught?
`fluentmail-settings.misc.simulate_emails = yes` on live **now** — wp_mail is being simulated, nothing
leaves. So current sends are caught. Whether the 6/17 batch actually went out depends on whether
simulate was already `yes` at 6/17 23:25 (the flag has no history). **Ground truth = AWS SES sending
stats for 2026-06-17 ~23:25 UTC**: a spike of ~12 = delivered; flat = caught. (Still open — needs Ian/SES.)

## Root cause
`Arbiter::sync()` computes the welcome trigger from **current state**, not from a real upgrade event:
`$oldTier` = the user's *current* WP roles; `$winning` = tier recomputed from payment sources;
`isUpgradeToPaid($old,$new)` returns true when `$old === null` ("first-ever paid assignment") or `$new > $old`.
Any process that perturbs role state makes an established member read as `null → looth2+`:
- a **DB reload** that wipes roles (then re-adds on next sweep) — matches the 6/17 batch and the known
  "DB reload breaks tier 4 ways" casualty pattern, and squares with the box being fresh off an AMI;
- an **admin role edit** (`AdminRoleCapture::Arbiter::sync`) — fires a welcome instantly;
- a **reconcile sweep** (`UserLifecycle:282`).
The only backstop was `_lg_welcome_email_sent_at`, which is **absent for members onboarded before
WelcomeMailer existed and is wiped by a full DB reload** → no protection exactly when it's needed.

**Exposure measured:** 1,172 paid members (looth2/3/4); **1,158 had no sentinel** → that many would be
re-welcomed the instant simulate is turned off and a sweep runs.

## Fix (two parts)

### 1. Data backfill — DONE on dev2, ready for live
Stamp the sentinel for every current paid member that lacks it, with a reversible marker. Verified on
dev2: **1,158 rows inserted, re-welcome risk → 0**. WelcomeMailer short-circuits on any non-empty
sentinel, so these members can never be re-welcomed.
- Live apply / verify / rollback commands handed to Ian (marker `backfill-20260620`; rollback =
  `DELETE … WHERE meta_value='backfill-20260620'`). **Pending Ian running on live.**

### 2. Code guard — DONE in `email-audit` worktree (commit below), needs review + merge
`WelcomeMailer::sendIfNeeded()` now refuses to first-time-welcome an account older than
`lgms_welcome_max_account_age_days` (default 14, filterable) and self-stamps the sentinel on skip.
Rationale: real new members are welcomed within days of registering; an "upgrade" on a months/years-old
account is a state glitch, not a join. This makes the system **self-healing** — even on a fresh box with
no backfill, the first sweep stamps established members instead of emailing them. Placed in WelcomeMailer
(not Arbiter) deliberately: it defends regardless of caller, and avoids colliding with `lane/login-poller`,
which also edits Arbiter.php.
- Trade-off (documented): a long-time *free* member who upgrades to paid after >14 days won't get the
  HTML welcome. Acceptable vs. mass re-welcome, and the threshold is filterable.
- `php -l` clean.

## Coordination / sequencing for keeper
- Incident (a) has a **second trigger** at the onboard layer (repeat Patreon connect re-welcomes) that is
  **already fixed in `lane/login-poller`** (adopt-existing + "you're logged in now") but **not yet merged
  to main**. Don't duplicate — merge that lane.
- login-poller edits `Arbiter.php`; this fix edits only `WelcomeMailer.php` → no conflict.
- Recommended order: (1) run the live backfill now; (2) merge login-poller; (3) merge this WelcomeMailer
  guard; (4) keep `simulate_emails = yes` until 1–3 are live; (5) confirm SES for the 6/17 batch; (6) then
  follow the staged re-enable plan in docs/EMAIL-AUDIT.md.
- Bigger picture lives in **docs/EMAIL-AUDIT.md** (all send paths, incident (b) the Showrunner double
  reminder, the two FluentSMTP-bypass paths, and the full safe re-enable plan).

## Still open (not in this fix)
- SES confirmation of whether the 6/17 batch was delivered (Ian/SES).
- Incident (b) Showrunner double-reminder (separate, GmailApp — bypasses FluentSMTP); see EMAIL-AUDIT.md.
- Optional: turn ON FluentSMTP email logging on live so future sends are auditable (currently `log_emails=no`).
