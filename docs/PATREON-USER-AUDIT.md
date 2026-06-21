# Patreon Sync Report — bug fix + Patreon↔WP member audit
**Lane:** `poller-report-audit` off `main` · dev2 only · own worktree `~/worktrees/poller-report-audit`
**Date:** 2026-06-20 · sweep RUN on dev2 (sim ON, no MTA — nothing left the box) · code fix committed (not pushed)
**Cross-ref:** `docs/STRIPE-POLLER-AUDIT.md` (239c761, poller path map) · fix commit `efa7970`

---

## TL;DR
- **The hourly "Patreon Sync Report" email has TWO defects, not one.** (1) `send_summary()` emailed admin
  **unconditionally** after every sweep — even a no-op. (2) Worse, the sweep is **never a true no-op**: it
  reports **7 phantom "applied" changes every single run** that never actually change anyone's role, so even
  a "send only when applied>0" guard would still fire hourly. Both are fixed.
- **Root cause of the phantom changes:** `apply_change()` reports Patreon's opinion to the Arbiter and then
  builds its result message from the **proposed** transition — it never checks what the Arbiter actually did.
  When another source (Stripe) outranks Patreon, the role never converges, so the same proposal is re-made and
  re-reported as "Applied" forever. **Verified on dev2: `applied=7` with `0` real role changes, stable across
  back-to-back runs.**
- **The report also omitted** fetched/matched/unchanged/skipped, so a real sweep looked empty/broken.
- **Fix (commit `efa7970`)**: `apply_change` re-reads the effective role post-Arbiter and returns
  `changed=bool`; results split into **applied / reconciled / errors**; `send_summary` prints the full stats
  and **sends only when `applied>0` OR `errors>0`**. **Tested: steady-state run → `applied=0, reconciled=7,
  emails_sent=0`.**
- **Audit:** Patreon campaign returns **3,335 members**; **1,501** match a WP user by email; **1,834 are on
  Patreon with no WP account** (mostly free followers). Tier-map coverage is **complete** (0 unmapped tiers).
  Real reconciliation items: ~7 members where Patreon disagrees with the effective WP role (held by another
  source), ~6 WP "patreon-paid" members **absent from the current campaign** (stale source), and the looth4
  cohort correctly protected.

---

## 1. The bug (JOB 1 + 2)

### 1.1 Captured BEFORE the fix (the email Ian gets — rendered by me, never sent)
Running `LGPO_Sync_Engine::run()` on dev2 produced this exact `wp_mail` body (captured via a `wp_mail`
filter; FluentSMTP simulate discarded it):
```
SUBJECT: [The Looth Group] Patreon Sync Report (Auto)
Patreon Member Sync Report (Auto)
======================================

Applied:  7
Errors:   0

Applied:
  - swisherguitars@gmail.com — looth3 → looth2.
  - andy.gleeman89@gmail.com — downgraded to looth1.
  - dave@red58.com — looth2 → looth3.
  - danielffr90@gmail.com — looth3 → looth2.
  - dlaup2@gmail.com — looth3 → looth2.
  - mike.larkin212@gmail.com — downgraded to looth1.
  - jscatches@icloud.com — looth3 → looth2.
```
**But a before/after role snapshot showed `0` actual role changes** — and a second back-to-back `run()` again
reported the *same 7*. So the report is **lying**: it lists 7 transitions that never take effect. That is the
"buggy" report — same names, every hour, forever.

### 1.2 Root cause (file:line, `lg-patreon-stripe-poller/includes/class-lgpo-sync-engine.php`)
- **Unconditional send:** `send_summary()` (`~750`) is called at the end of `run()` (`~240`) and
  `execute_approved()` (`~134`) with no guard — every sweep emails, even a no-op.
- **Report omits stats:** `send_summary` only received `$results` (`applied`/`errors`); the rich stats
  (`total_fetched`, `matched`, `unchanged`, `skipped_*`) computed in `run()` (`$changes['stats']`, `~210`,
  logged at `~232`) were **never passed in** → the email shows only Applied/Errors.
- **Phantom "applied" (the real defect):** `apply_change()` (`~609`) does
  `RoleSourceWriter::report(uid,'patreon',$tier)` → `Arbiter::sync(uid)` (`~646`), then returns
  `"{$email} — {$current_role} → {$new_role}."` (`~672`) built from the **proposed** values. The Arbiter —
  not this proposal — writes the final `wp_capabilities` by merging all sources; when Stripe (or a protected
  looth4) outranks Patreon, the effective role **does not move**, but `apply_change` still returns success with
  a "changed" message. `compare_member` (`~440`) then re-derives the same diff next sweep → infinite re-report.
  Proof: `swisherguitars@gmail.com` (uid 1862) stays **looth3** while Patreon says looth2, every run.

### 1.3 The fix (commit `efa7970`) + AFTER capture
- `apply_change` now re-reads the **effective** role after `Arbiter::sync` and returns `changed=bool` with an
  accurate message; a proposal the Arbiter overrides becomes a **reconciled (no net change)** line, not
  "applied".
- `run()` / `execute_approved` split results into `applied` / `reconciled` / `errors`.
- `send_summary($results,$is_auto,$stats)` prints the full pipeline stats + a reconciled count, sets an
  explicit `Content-Type: text/plain; charset=UTF-8` (so `—`/`→` never mojibake), and **returns early unless
  `applied>0` OR `errors>0`**.

Tested on dev2 (patched build installed on the served path momentarily, then restored to the original SHA):
```
run #1: applied=0 reconciled=7 errors=0  emails_sent=0
run #2: applied=0 reconciled=7 errors=0  emails_sent=0
```
Reconciled lines now tell the truth, e.g.:
`swisherguitars@gmail.com — Patreon says looth2; effective role stays looth3 (held by another source).`
And a forced real change renders the complete report:
```
SUBJECT: [The Looth Group] Patreon Sync Report (Auto) — 1 changed, 0 errors
Fetched from Patreon:  3335
Matched to WP users:   1501
Unchanged:             1482
Skipped (no WP acct):  1834
Skipped (looth4):      12
Skipped (stripe):      0

Role changes applied:  1
Errors:                0
Reconciled (no net change): 1

Applied:
  - alice@example.com — looth1 → looth2.
```

> NOTE: the brief's suggested guard ("send only when applied>0 OR errors>0") is necessary but **not
> sufficient** on its own — because `applied` was perpetually 7. The effective-role check is what actually
> drives it to 0 in steady state. Both changes ship together.

---

## 2. Patreon ↔ WP member audit (JOB 3)

Source: live creator-token campaign fetch on dev2 (`fetch_all_members`, read-only), reconciled against WP users.

### 2.1 Campaign fetch
- **3,335** campaign members returned. **36** have no tier (free/declined). **11** distinct tier IDs appear.
- Tier distribution (tier_id → looth role → count):
  `10455112→looth1: 1884`, `22207438→looth3: 569`, `22199086→looth2: 461`, `6401900→looth3: 119`,
  `7757819→looth2: 120`, `7757908→looth3: 116`, `5735635→looth2: 33`, `8603192→looth3: 17`,
  `5735762→looth3: 12`, `9517681→looth3: 1`, `24295274→looth4: 1`.

### 2.2 Tier-map coverage — COMPLETE
`lgpo_tier_map` has **15** entries; the fetch uses **11**. **0 unmapped tiers** appeared, so there is **no
coverage gap**. The 4 mapped-but-unused IDs (`9220984`, `22226742`, `25496422`, `25496403`) are retired tiers
— harmless; can be pruned for tidiness but not required.

### 2.3 Patreon vs WP
- **Matched to a WP user (by email): 1,501.**
- **On Patreon, NOT in WP: 1,834** — overwhelmingly the 1,884 free (`looth1`) followers who never created a
  site account. **Expected, not a defect** (free followers aren't site members). Worth confirming none of these
  are *paid* tiers that failed to onboard: of the 1,834, the paid-tier share is the gap to watch at cut — none
  surfaced as paid-without-WP in spot checks, but a full paid-only slice is a good cut-day check.
- **Tier mismatches (Patreon target ≠ effective WP role): 17**, which split into:
  - **looth4 protected (intentional, never changed):** `chenyuexin2014`, `giuliano.nicoletti`,
    `michael@bashkinguitars`, `ted.a.bergstrand`, `ianhatesguitars`, `scorpio.guitars.509` (Patreon=looth1),
    `rick@gluboost`, `jamesroadman` (Patreon=looth3). `compare_member` skips looth4 by design — correct.
  - **Genuine reconciliation flappers (~7):** `swisherguitars` (WP looth3 / Patreon looth2),
    `danielffr90`, `dlaup2`, `jscatches` (looth3 / looth2), `dave@red58` (looth2 / looth3),
    `andy.gleeman89`, `mike.larkin212` (paid / Patreon ended), `maxmonte` (looth1 / Patreon looth2). These are
    members whose **effective role is held by another source (Stripe)** that disagrees with Patreon. Pre-fix
    these drove the hourly spam; post-fix they show as **reconciled**. **Real signal for Ian:** Patreon and
    Stripe disagree for these accounts — decide which source should win per user (the Arbiter currently keeps
    the higher tier).
- **WP `looth2`/`looth3` with NO Patreon row in the fetch AND not `payment_source=stripe`: 9** — includes test
  accounts (`anonymous@f7xn47ba.com`, `qa-gift-rcpt@example.com`, `ian.davlin@gmail.com`) and **~6 real
  members carrying `payment_source=patreon` but absent from the current campaign**
  (`the.liam.browne`, `lindsay.bme`, `mpaula2701`, `michael.webking`, `bradyshreeve`, `cmara`). These are
  **stale Patreon sources** — paid on the site, but no longer in the Patreon campaign (lapsed/removed/email
  changed). At cut these should be re-checked: either they moved to Stripe, changed their Patreon email, or
  should be downgraded.

### 2.4 Duplicates / orphans
No duplicate WP-user collisions surfaced in this pass (match is email-keyed, 1:1). Orphan risk is the inverse of
2.3: the ~6 stale-`payment_source=patreon` members are the orphan-leaning set (WP source row with no live
Patreon backing). The 1,834 Patreon-not-in-WP rows are not orphans (they have no WP side to orphan).

---

## 3. Stop-it-now + proper fix (JOB 4 — LIVE steps are Ian/keeper-held, scoped only)

**Cron identity:** hook `lgpo_patreon_auto_sync` (`includes/class-lgpo-sync-cron.php:17`), gated by
`lgpo_auto_sync_enabled` + `lgpo_sync_frequency` (currently `hourly`).

**To stop the hourly email on LIVE immediately (pick one):**
1. **Admin UI (fastest, no SQL):** WP Admin → the Patreon poller settings page → **uncheck "Auto Sync"**
   (or set **Frequency = Daily**). Saving runs `maybe_manage_schedule()` which `wp_unschedule_event()`s the
   next `lgpo_patreon_auto_sync`. Daily instead of off keeps polling but cuts 24 emails/day → 1.
2. **SQL + clear the queued event (if UI is unavailable):**
   ```sql
   UPDATE wp_options SET option_value = '0' WHERE option_name = 'lgpo_auto_sync_enabled';
   ```
   then clear the already-scheduled tick so the queued one can't fire:
   `wp_clear_scheduled_hook('lgpo_patreon_auto_sync')` (one-off PHP, or `wp cron event delete
   lgpo_patreon_auto_sync` **on live**, where wp-cli works — it FATALS on dev2).
3. **Proper fix = deploy commit `efa7970`** — with it, a steady-state sweep emails **nothing** (applied=0),
   so auto-sync can stay hourly without spamming, and the report is accurate + complete when something real
   happens. This is the durable solution; steps 1–2 are the stopgap.

---

## 4. For the keeper (cross-lane / deploy notes)
- **Fix commit `efa7970`** on branch `poller-report-audit` (off `main`) — `php -l` clean, tested on dev2.
  Touches only `includes/class-lgpo-sync-engine.php`.
- **SERVED ↔ main drift (same pattern as onboard.php):** the served
  `/var/www/dev/.../includes/class-lgpo-sync-engine.php` is **ahead of `main`** by an 18-line partial-snapshot
  tolerance block in the member upsert (defaults all snapshot keys so a self-connect onboard's thin
  `/identity` payload doesn't raise "Undefined array key"). My fix is on `main`; deploying `main` as-is would
  **drop that served block**. **Reconcile served→main first**, then layer this fix (the fix re-applies cleanly
  on top of the served base — verified: all 6 hunks matched the served copy too).
- **Arbiter source-precedence is a product decision, not a bug:** the ~7 reconciled flappers are Patreon-vs-
  Stripe disagreements the Arbiter resolves by keeping the higher tier. If Ian wants Patreon to be able to
  downgrade a Stripe-held member (or vice-versa), that's an Arbiter change in a different lane — out of scope
  here.
- **dev2 role data was mutated** by the sweep runs (Arbiter writes) as expected per the brief — this is the
  dev2 sandbox; no live data touched. Served code was restored to its exact original SHA after testing.

---

## 6. ADDENDUM (2026-06-21) — decision reversal, LIVE deploy, dev2 reconcile test

### 6.1 Decision: KEEP the suppression (efa7970 as-built)
Ian's final call: keep `efa7970` exactly — `send_summary` emails **only when `applied>0` OR
`errors>0`** (no-op hourly sweeps stay silent), plus the accuracy fix. (An intermediate "keep the
hourly heartbeat" variant was prototyped and discarded per the later override.) Branch state:
`efa7970` (fix) → `53ddf24` (this doc) → `54f21d7` (tolerance block, keeper cleanup).

### 6.2 LIVE deploy — packaged, NOT run on live (Ian/keeper-held)
- The poller is in `wp-content/plugins` (**not git-served**) and live's file is **ahead of main** by
  the 18-line tolerance block — so a git pull / shipping main's file is **wrong** (would drop the
  block). See `deploy/DEPLOY.md`.
- Deliverable: `deploy/patch-sync-report.py` — a **self-verifying** in-place patcher (8 hunks, each
  must match exactly once or it aborts) built from the `efa7970` diff. **Proven byte-exact**:
  applying it to `git show main:…` reproduces `git show efa7970:…` identically; and all 8 hunks match
  the live-equivalent served file (so the tolerance block is preserved). A prebuilt drop-in file
  (`live-base + fix`) is also available on dev2 at `/tmp/deploy_engine.php`.
- Ian's steps: backup → `python3 patch-sync-report.py <file>` → `php -l` → `sudo systemctl restart
  php8.3-fpm`. Keep `simulate_emails` as-is; don't touch the hourly schedule.

### 6.3 dev2 reconcile test — fix DEPLOYED on dev2, test RUN (sandbox; no email left the box)
Safety gate first (asserted in-harness before any action): `simulate_emails=yes`, **no MTA**. The fix
was deployed onto dev2's running poller (served SHA `9fbee2d…` → `f3460d3…`, `php8.3-fpm` restarted)
and **left deployed** so Ian can keep testing (restore: `cp /tmp/served_orig_engine.bak <served>` —
or just re-run the keeper reconcile).

Method: picked 4 non-flapper, in-sync, Patreon-owned users (uids 699/1143/1149/167), forced their WP
role to a wrong value (`looth1`), ran the sweep, captured the report via a `wp_mail` filter, then
restored their roles.

**Result — two things, both important:**
1. **The report was ACCURATE** (the point of the fix): the 4 diverted users were **NOT** falsely
   listed as "Applied" — they were correctly counted under **Reconciled (no net change)**. The report
   listed only a genuine change under "Applied" and showed full counts
   (`Fetched 3335 / Matched 1501 / Unchanged … / Errors 0`). **Pre-fix, the same sweep would have
   printed "Applied: looth1 → looth3" for all four — a lie.** `emails_captured` were 100% simulated;
   MTA absent. ✅
2. **The roles did NOT reconcile back up (0/4)** — and this surfaced a **separate upstream bug** (NOT
   in the report code, NOT my fix):
   - `Arbiter::sync()` computes the winning tier from `RoleSourceWriter::readAllForUser()`, which
     **overwrites the persisted `patreon` source with a live read from
     `PatreonSourceReader::readForUser()`** (`src/Patreon/PatreonSourceReader.php`).
   - That "live" reader derives the Patreon tier from the user's **current WP role**
     (`$tier = highest of $user->roles`, lines ~17-21) — **not** from the Patreon API.
   - Net: `apply_change()`'s `RoleSourceWriter::report('patreon', $target)` is **ignored** by the very
     next `readAllForUser()`, and the Arbiter recomputes "patreon = the role the user already has".
     **The sweep can DOWNGRADE (clear payment_source → tier drops) but cannot UPGRADE** a role above
     its current value. Confirmed by direct probe: report `patreon=looth3` on a `looth1` user, then
     `readAllForUser` still returns `looth1`, Arbiter winning `looth1`.
   - **This is the real mechanism behind the original "Applied: 7, 0 actual changes" symptom** — the
     7 were proposals the Arbiter could never enact. The report fix makes that visible/honest;
     **fixing the reconcile itself is an Arbiter/PatreonSourceReader change in another lane.**

**Keeper action items (cross-lane):**
- Owner of `Arbiter` / `PatreonSourceReader`: decide whether `PatreonSourceReader` should read the
  **Patreon API/`lgpo_patreon_tier_id`** truth instead of mirroring the current WP role, so the sweep
  can actually apply upgrades. Until then, the poller only enforces downgrades.
- Note: forcing roles via `set_role` also trips `AdminRoleCapture` (writes a `manual_admin` source),
  which compounds the above — relevant for anyone hand-testing reconciles.
