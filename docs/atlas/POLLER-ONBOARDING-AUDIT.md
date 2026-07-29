# AUDIT — Patreon poller / onboarding: is it broken, and can it be smaller?

**Written 2026-07-29 by the `poller-audit` lane.** Branch `poller-audit`, cut from
`0995f2b`. Every claim below was established by **reading the code** and by
**read-only queries against live** (`ssh live-ro`; WP DB `looth_import`,
membership DB `lg_membership`, profile-app Postgres `profile_app`). Nothing was
changed. Where the handoff's premises turned out to be wrong, that is stated
explicitly with the evidence.

**Headline: the duplicate-minting bug is fixed and provably not recurring. But
three things the handoff recorded as "dead" are not dead, and the email mirror
silently changes a member's login credential without telling them or Ian.**

---

## 0. Corrections to the handoff's premises

The handoff asked for its own lines to be verified rather than inherited. Four
did not survive.

| Handoff said | Actually | Proof |
|---|---|---|
| "four hits" for `wp_insert_user` | **Six** creation callsites; two whole paths unmapped (`Admin.php:218` affiliate mint, `RestController.php:1541` public gift-auth mint) | grep across repo, §1 |
| `UserLifecycle.php` — "0 callers — DEAD" | The **file is alive** — `teardown()` has 7+ callers. Only `provision()` + its 3 exclusive helpers (lines **206–409**) are dead | §2 |
| Stripe path "has never run — no bridge table exists on live" | Bridge table **exists in `lg_membership`** with **3 rows**; `findOrProvision` runs on **every 5-minute cron tick**. It has never reached its *mint branch* — a different, weaker claim | §3 |
| "fixed since ~2026-06-25" | The mint guard was already present at the repo seed (2026-06-18) and was hand-patched onto live well before that. The 6/25 commit fixed the **sweep**, not the onboard mint | §1.5 |

The handoff's central measured signal — **zero full-name duplicates minted since
2026-04-30** — is **confirmed**, and confirmed by a sharper test than name
matching (§1.6).

---

## 1. Is it actually broken?

### 1.1 Every path that can create a user

Six callsites. Verdict per path:

| # | Path | file:line | Fires when | Looks up by | Can mint a duplicate? |
|---|---|---|---|---|---|
| 1 | Patreon OAuth onboard | `lg-patreon-onboard.php:1369` | member returns from Patreon | patreon_id → email → skeleton login | **No** — three guards, all deterministic |
| 2 | Roster sweep | `class-lgpo-sync-engine.php` | hourly cron | patreon_id → email | **No — never mints at all** |
| 3 | Stripe provisioner | `Wp/UserProvisioner.php:39` | every 5-min tick, per customer | **email only** | **Yes, latently** — see §3 |
| 4 | `UserLifecycle::provision` | `UserLifecycle.php:231` | never (0 callers) | **email only** | **Yes, if ever called** — see §2 |
| 5 | Affiliate admin mint | `Admin.php:218` | admin clicks button | `email_exists()` refusal | Low — admin-gated, nonce, refuses existing email |
| 6 | Public gift-auth REST | `Wp/RestController.php:1541` | anyone POSTs `/gift-auth` | **email only** | **Yes, latently** — see §1.4 |

A seventh `wp_insert_user` (`UserLifecycle.php:812`) is **not** a member minter —
it creates the single `[deleted member]` tombstone sentinel, roleless and
emailless, idempotent via option + login lookup. Correct as written.

### 1.2 Path 1 — the live OAuth onboarding. NOT BROKEN.

Read in full, `lg-patreon-onboard.php:1221–1417`. The decision is three guards
then a mint, in this order:

1. **`lgpo_get_user_by_patreon_id()`** (`:1222`, helper at `:1425`) — matches on
   the stable `lgpo_patreon_user_id` meta. Hit ⇒ adopt + log in, never mint
   (`:1224`).
2. **`get_user_by('email')`** (`:1238`). Hit ⇒ three sub-cases:
   - existing account linked to a **different** Patreon id (`:1243`) ⇒ pending
     review + `lg_login_blocked` + terminal. **No mint, no auto-adopt.**
   - existing account is an **administrator** (`:1267`) ⇒ same review routing.
     Never hands an admin session out over OAuth.
   - otherwise (`:1290`) ⇒ **adopt** the existing account.
3. **Skeleton adopt** (`:1306`) — `get_user_by('login', 'patreon_<id>')`. Catches
   bulk-imported accounts invisible to both guards above (no meta, blank email).
   The Patreon id is literally in the username, so this is deterministic, not a
   fuzzy name guess. Mirrors the email only when WP's unique-email constraint
   allows (`:1343–1355`), else keeps the link and alerts.
4. Only with all three missed does it mint (`:1369`).

Given the rule "WP email must equal current Patreon email", a duplicate requires
a member to arrive with a Patreon id that matches no account, an email that
matches no account, and no `patreon_<id>` skeleton. That is a genuinely new
member. **The path is correct.**

### 1.3 Path 2 — the roster sweep. NOT BROKEN; it cannot mint.

`compare_member()` matches patreon_id first (`:499–504`), falls back to email
(`:505–511`), and when neither hits it **records `skipped_no_wp` and returns**
(`:513–520`). There is no `wp_insert_user` anywhere in the file. On an email
match it backfills the id meta (`:529–532`) so every later pass keys on the
stable id — this is the mechanism that progressively immunises the roster.

### 1.4 Path 6 — public gift-auth. Latent, never fired.

`/auth` and `/gift-auth` are registered with `permission_callback =>
'__return_true'` (`RestController.php:166,169`) — fully public. On an unknown
email plus `confirmed_consent`, it mints (`:1541`), keyed on email alone
(`:1472`), with no Patreon-id check. It is rate-limited (20/hr per IP, 5/15min
per email, `:1419–1463`).

**Live: it has never minted.** `SELECT COUNT(*) FROM wp_usermeta WHERE
meta_key='lg_auto_provisioned'` ⇒ **0**.

It is nevertheless the sharpest proof of the credential question, because
`:1499` authenticates with `wp_check_password` against an email lookup — see §4.

### 1.5 The "fixed since June" claim — refuted as stated, but the conclusion holds

Git cannot date this fix. The repo's first commit `e5d466d` (2026-06-18) is a
"fresh seed from dev2 reality", and `da44885` is literally *"capture live
hand-patches into git"* — live ran hand-edited code before the repo existed, so
commit dates are **capture** dates, not deploy dates.

Reading the seed settles it anyway: at `e5d466d`, `lg-patreon-onboard.php`
**already had** id-first matching, email adopt, the different-patreon-id
conflict route and the admin guard (seed lines 1084–1132). The only later
addition was skeleton adopt (`a06ee54`, 2026-06-29).

So `a6af334` (2026-06-25, "Patreon-ID bridge, email mirror") fixed the **sweep**,
not the onboard mint guard. The onboard guard predates the repo — consistent
with duplicates stopping 2026-04-30, **eight weeks before** the commit the
handoff credited. The causal story in the handoff is wrong; the outcome is right.

### 1.6 Independent proof that minting has stopped

Name matching is unreliable, so it was replaced with a test that cannot produce
false positives: **the same Patreon id appearing on more than one WP user.**

```sql
SELECT meta_value, COUNT(*) FROM wp_usermeta
 WHERE meta_key='lgpo_patreon_user_id' AND meta_value<>''
 GROUP BY meta_value HAVING COUNT(*)>1;
```
⇒ **zero rows.** No split identity by Patreon id anywhere on live.

The handoff's "no full-name duplicates since 2026-04-30" also holds. Five
display-name groups *look* newer (ben 2026-07-13, David 2026-07-08, Rob
2026-07-01, Michael 2026-06-21, Mike 2026-05-05) — all are **distinct people**:
every account in each group carries a different Patreon id and a different
email. The newest true multi-word duplicate is *Nick Gagliardo*, 2026-04-30.

**Verdict for question 1: the user-creating code that actually runs is not
broken. Two email-keyed minters (§2, §3) and one public one (§1.4) are latent
sources that have not fired.**

---

## 2. The dead file — it is not a dead file

**`UserLifecycle::teardown()` is heavily used.** Callers: `Plugin.php:301`
(the `deleted_user` safety net), `MemberTools.php:443`, `UserLifecycleAdmin.php:138,274`
(the admin UI), `TestChecklist.php:365`. `purgeOrphanCustomer()` is called from
`MemberTools.php:447` and `TestChecklist.php:371`. **Deleting this file would
break member teardown across the admin.**

What *is* dead is exactly **lines 206–409** — `provision()` and the three helpers
reachable only from it:

| Method | Lines | In-file callers |
|---|---|---|
| `provision()` | 206–307 | **0 repo-wide** |
| `uniqueUsername()` | 308–333 | only `:230` (inside provision) |
| `ensureProfileIdentity()` | 334–389 | only `:294` (inside provision) |
| `loginUser()` | 390–409 | only `:300` (inside provision) |

A contiguous **204-line** block. Line 410 (`purgeOrphanCustomer`) is live again.

**It is a loaded gun, not mere abandonment.** Its docblock (`:178–196`) instructs
future maintainers that *"Every creator (Patreon onboard, gift-auth, Stripe,
sweep-match, admin, affiliate, native) routes through here"* and claims
*"Idempotent on email: an existing account is found + reconciled, never
duplicated."* But its lookup is `get_user_by('email', $email)` at **`:227` and
nothing else** — no Patreon-id match, no skeleton adopt, no admin-collision
guard, no different-patreon-id conflict routing. Obeying that docblock would
route the Patreon onboard back into **precisely the email-keyed logic that minted
the 29 duplicates**, discarding all three guards proven in §1.2.

**Hardened against dynamic dispatch (2026-07-29).** "Zero callers" from a static
grep can miss `call_user_func`, `$class::$method`, or a callback stored as a
string, so each was searched: no dynamic dispatch, no bare `'provision'` string
in any PHP file, nothing outside the repo under dev2's `wp-content` reaching it,
and no runbook or script invoking it.

**It has, however, *run*.** dev2's `lg-user-audit.log` records **6** executions of
`LGMS\UserLifecycle::provision`, all on **2026-06-04**, and every one has
`… < eval < Eval_Command->__invoke < WP_CLI\Runner->run_command` in its trigger
chain — i.e. hand-run via `wp eval` on the day the lifecycle work was built. So
the honest description is **written, hand-tested, never wired** — abandonment
mid-implementation, not code whose callers were later removed.

**One doc dependency to clear in the same commit.** `docs/atlas/NAMING-UNIFICATION-SPEC.md:48`
still describes this mint as part of the live design ("§2 Patreon human-handle
minting" is flagged STILL LIVE in that spec's header). It is only *descriptive* —
§2 was actually built in `lgpo_generate_username()` (`lg-patreon-onboard.php:1470–1491`),
whose name → vanity → +tag-stripped email → `looth-member` chain matches the spec
exactly — so deleting `provision()` breaks nothing there. But that line should be
corrected alongside the deletion, for the same reason the docblock is the real
hazard: a stale pointer is what sends the next reader back to the wrong door.

### Recommendation: **delete lines 206–409.** Do not make it real.

The docblock is more dangerous than the code, because it is an instruction. The
canonical create path already exists and is correct — it is the onboard's
three-guard chain. If a shared front door is wanted, build it from §6's `match()`,
not from this. Deleting is safe: zero callers, and the block is contiguous.

---

## 3. The Stripe path — reachable, running, and never yet minting

The handoff's evidence was wrong: it tested `looth_import` for `wp_user_bridge`.
The table lives in **`lg_membership`**, and on live it exists with rows.

```
customers               4   (3 with deleted_at IS NULL)
wp_user_bridge          3
entitlements            7
subscriptions           3
lg_role_sources source=stripe   44 rows / 44 distinct wp_user_id
```

`Tick::run` → `Sync::all()` (`Tick.php:177`) → `Sync::customer()` →
`UserProvisioner::findOrProvision()` (`Sync.php:36`), and `lgms_poll_tick` is
scheduled on live cron at a 5-minute interval. **This code executes for all 3
customers, roughly every 5 minutes, today.** It is not inert.

**It has never reached its mint branch.** All 3 bridge rows point at accounts
that pre-date their bridge by 1–3 years:

| customer_id | wp_user_id | bridged | user_registered | patreon id |
|---|---|---|---|---|
| 7 | 1 | 2026-04-29 | 2023-06-10 | (Ian, admin) |
| 25 | 596 | 2026-05-04 | 2023-11-16 | 5604352 |
| 135 | 1003 | 2026-06-09 | 2024-09-08 | 41670846 |

All three took the **found-by-email** branch (`UserProvisioner.php:31–35`), which
bridges and returns. The mint at `:39` has never run.

**Why it is a real latent duplicate source.** `findOrProvision` keys on email and
nothing else. Given the platform rule that a member's WP email is continuously
overwritten to their *current Patreon* email (§4), a Stripe customer whose Stripe
email differs from their Patreon email — a different address, or an Apple Private
Relay alias, both of which occur on live — will miss the email lookup and **mint
a second account**, with no Patreon-id check and no admin guard.

### Recommendation: do not "leave it inert" — it is hot. Two acceptable moves:

- **If Stripe onboarding is not a product decision yet (Ian's stated position):**
  replace the mint branch (`:37–47`) with a fail-loud `lgpo_notify_failure` +
  `RuntimeException`. ~5 lines, removes the latent minter entirely, keeps the
  find-and-bridge behaviour the 3 live customers depend on. **Recommended.**
- **If Stripe onboarding is coming:** route the lookup through §6's shared
  `match()` so it inherits the Patreon-id check and the guards.

Do **not** simply delete the file — `Sync::customer` depends on it for the
bridging that runs now.

---

## 4. Credential changes on the Patreon side (Ian's question)

### 4.1 First, the premise the code disputes with itself

`class-lgpo-sync-engine.php` states **both** doctrines, ~150 lines apart:

- `:492–496` — *"The email is NOT optional and is NEVER non-load-bearing: it is
  the user's LOGIN CREDENTIAL and MUST be captured."*
- `:540–543` and `:670–675` — *"Email is communication-only — login is Patreon
  OAuth (wp_set_auth_cookie), so rewriting the WP email can never lock a member
  out."*

**The second is false, and it is the comment sitting directly above the code that
overwrites the email.** Proof that email is a login credential:

- `wp_authenticate_email_password` is registered on live
  (`/var/www/dev/wp-includes/default-filters.php:504`) — WP accepts email in the
  login field by default.
- The gift-auth endpoint authenticates by email lookup + `wp_check_password`
  (`RestController.php:1472, 1499`).
- The onboard offers password setup and explicitly lets members **skip** it
  (`lgpo-set-password.php:107,130` — *"Set a password so you can sign in directly
  next time — or skip it and just reconnect with Patreon"*).

So the population splits: members who set a password log in with **email +
password**; members who skipped re-authenticate through Patreon OAuth and are
matched by patreon_id, unaffected by email churn.

### 4.2 A member changes their PASSWORD on Patreon — nothing breaks

**The poller stores no per-member Patreon token.** Verified: there is no
`update_user_meta`/`add_user_meta` write of any `access_token` or `refresh_token`
anywhere in the plugin. The member's OAuth `access_token` in
`lgpo_handle_callback` (`:1072`) is used for the single identity fetch and
discarded when the request ends.

Consequently: their WP password is a separate secret and is untouched; their WP
session is untouched; their next Patreon reconnect simply runs the OAuth dance
again. **Member-side answer: a Patreon password change breaks nothing.**

**The real exposure is Ian's own Patreon password.** The creator credentials are
site-wide options — `lgpo_creator_access_token`, `lgpo_creator_refresh_token`,
`lgpo_creator_token_expires_at` (`lg-patreon-onboard.php:955–965`), all present
on live. They authorise the campaign-members sweep. If Patreon invalidates them,
the sweep 401s; `fetch_all_members` retries once through
`lgpo_refresh_creator_token()` (`:306–337`) and, on a second failure, emails an
alert that deliberately bypasses the mail gate (`:326–334`, `:342–353`). So this
degrades **loudly**, which is correct.

> **One claim I could not verify from this box:** whether Patreon actually
> revokes OAuth refresh tokens on a password change. That is Patreon-side
> behaviour and I did not browse (and should not guess — guessing is the failure
> this lane exists to correct). What *is* verified is our side: if the tokens are
> revoked by anything, polling stops and Ian is emailed. **Operational note:**
> `lgpo_creator_token_expires_at` on live = **2026-07-31 01:52 UTC** — about two
> days out. Refresh-on-401 should rotate it; if it does not, polling stops.

### 4.3 A member changes their EMAIL on Patreon — this is the real problem

The chain, each step read:

1. Hourly sweep matches them by patreon_id (`:499–504`) — correct, survives the
   change.
2. `sync_wp_email()` (`:544` → `:684`) mirrors the new Patreon email onto the WP
   account via `wp_update_user` (`:733`), unless a **different** WP user already
   holds it — in which case it correctly refuses and alerts (`:704–730`).
3. **Their login identifier has now silently changed.** Their WP password is
   unchanged and still works — with an email they were never told about.
4. `_looth_uuid` is **not** re-derived (`stamp_looth_uuid` returns early if set,
   `:784–787`). This is correct and important: the JWT `sub` stays stable, so the
   member is **not** logged out and their profile/identity survives.
   **Measured on dev2, 2026-07-29** — across a real email change the uuid held at
   `3dcadeb3-…` rather than becoming the new-email uuid `15b4e080-…`, and
   re-running `stamp_looth_uuid()` was a no-op. So the member keeps their session
   and profile; what they lose is only the ability to sign in with the address
   they know. This is the one part of the mirror that is behaving correctly.
5. profile-app is never told (§5) — it keeps the old address as `primary_email`.

**Does the member get told? No — and the mechanism is exact.** An email-change
notice *is* generated, to the **old** address only. WP core ships one
(`/var/www/dev/wp-includes/user.php:2818–2845`, `'headers' => ''`); on this
install BuddyBoss supersedes it with its own *"Notice of Email Change"*
(`buddyboss-platform/bp-core/bp-core-wp-emails.php`). Either way it carries **no**
`X-LG-Poller-Intent` header, and it is emitted from inside `wp_update_user()` —
i.e. from the poller's own call stack. The plugin installs `pre_wp_mail` →
`Plugin::gateOutboundMail` (`Plugin.php:135, 273`), which:

- passes anything carrying `X-LG-Poller-Intent` (`:278`) — core's notice has no
  headers;
- passes everything if `lgms_poller_mail_enabled` is on (`:282`) — **that option
  does not exist in live `wp_options`**, so `get_option(..., false)` ⇒ false;
- otherwise walks the backtrace and **suppresses any mail with a frame under
  `/lg-patreon-stripe-poller/`** (`:286–290`). The sweep's frame is
  `…/lg-patreon-stripe-poller/includes/class-lgpo-sync-engine.php` (live symlink
  resolves to `/home/ubuntu/loothplatformv2-clean/lg-patreon-stripe-poller`), so
  the test matches.

**⇒ The member's login email is changed and WP's own notification of that change
is swallowed by the poller's mail gate.**

**Measured on dev2, 2026-07-29** (probe asserts on the gate's `pre_wp_mail`
verdict, not on `wp_mail()` returning true — mailpit accepts everything, so a
`true` return proves nothing). Driving an email change from a required file under
`/lg-patreon-stripe-poller/` — the same frame shape `sync_wp_email()` has:

```
BEFORE  SUPPRESSED  tagged=no   "[The Looth Group] Notice of Email Change"  -> old address
        (that was the ONLY notification — the member is told nothing)
```
Fixed in §7 item 1; the after-state is recorded there.

**And Ian is not told either.** If the member later tries their old email,
`lg-login-monitor` early-returns because the address no longer resolves to a user
(`lg-login-monitor.php:161–167` — *"A miss = bot/typo spray → ignore"*). The
failed login is invisible on both ends.

**Member experience, stated plainly:** a member who set a password and changes
their email on Patreon will, within the hour, be unable to log in with the email
they think is theirs. They receive no notice. Their attempts raise no alert. If
they use "forgot password", the reset goes to the **new** address — which, when
Patreon supplies an Apple Private Relay alias, is one they may never read. Live
already contains such addresses: WP #1814 has Patreon email
`g6mjz8rrf5@privaterelay.appleid.com`, and profile-app holds relay addresses as
primary for WP #1034 and #1115.

**This is the single highest-value fix in this audit** and it is small: give the
core notice the bypass header, or send a purpose-written one. See §6.

---

## 5. The email-change gap on the WP side — confirmed, and worse than recorded

**The sender does not exist.** `platform/mu-plugins/profile-sync.php` registers
exactly one hook — `add_action('user_register', …)` at **`:67`**. There is no
`profile_update` hook anywhere in the repo (grep for `profile_update` returns
nothing outside vendor).

**The receiver exists and is correct.** `Provision::applyEmailChange()`
(`profile-app/src/Provision.php:339`) keeps `users.uuid` stable, records the new
address as an alias, moves `primary_email`, and handles the UNIQUE collision by
keeping the existing primary (`:372–384`). It is fronted by a real endpoint,
`profile-app/api/v0/internal-email-changed.php`, whose own docblock says it is
*"Called by the Profile-app Sync mu-plugin on the WP `profile_update` hook"* — a
documented contract with no caller.

**New finding — the gap is two layers, not one.** The route is wired on dev2
(`platform/nginx/strangler-profile-app*.conf`, 3 files, e.g.
`strangler-profile-app.conf:183`) but **absent from the live snippet**:
`profile-app/deploy/profile-app.nginx-snippet.live.conf` rewrites only
`hooks/user-created` (`:15`), and `grep -r email-changed /etc/nginx/` on live
returns nothing. **Adding the WP hook alone would 404 on live.**

**Measured blast radius.** Comparing all 1,836 WP users against profile-app's
bridged `primary_email`:

| | count |
|---|---|
| bridged pairs compared | 1,836 |
| divergent | 132 |
| — legacy `looth-<id>@invalid` placeholders | 114 (31 of which have a blank WP email too) |
| — **genuine drift, two real different addresses** | **18** |

Those 18 (WP #224, 369, 555, 560, 774, 792, 881, 937, 1025, 1034, 1115, 1333,
1427, 1431, 1500, 1675, 1690, 1768) are members whose WP email moved and whose
profile-app identity still carries the old one.

### What should fire on `profile_update`

In `profile-sync.php`, alongside the existing `user_register` hook:

```php
add_action('profile_update', function ($user_id, $old) {
    $u = get_userdata($user_id);
    if (!$u || strtolower(trim($u->user_email)) === strtolower(trim($old->user_email))) return;
    // POST {wp_user_id, email} to /profile-api/v0/hooks/email-changed, X-Hook-Secret
}, 99, 2);
```

Notes that matter:
- It must **not** re-stamp `_looth_uuid` — immutability is what keeps the member
  logged in (`class-lgpo-sync-engine.php:784–787`).
- It fires for the poller's mirror too, since that mirror is a `wp_update_user`.
- **Ship the live nginx route in the same window as the hook**, or it 404s.
- Backfill the 18 drifted rows once the channel is live.

---

## 6. Can it be smaller?

### Honest accounting first

The "~4,200 lines" figure is the sum of four whole files, and it overstates the
problem: most of `lg-patreon-onboard.php` is OAuth plumbing, settings UI and the
sync admin screen, and most of `UserLifecycle.php` is the live teardown. The
**identity-touching** surface is:

| Block | file:line | Lines |
|---|---|---|
| onboard identity decision + mint | `lg-patreon-onboard.php:1221–1417` | 197 |
| onboard identity helpers (`get_user_by_patreon_id`, `adopt`, `generate_username`) | `:1425–1491` | 65 |
| sweep match + id backfill | `class-lgpo-sync-engine.php:484–532` | 49 |
| sweep email mirror | `:670–754` | 85 |
| uuid freeze (+ v5 fallback) | `:756–824` | 69 |
| **dead** `UserLifecycle::provision` block | `UserLifecycle.php:206–409` | **204** |
| **latent** Stripe provisioner | `Wp/UserProvisioner.php` (whole) | **133** |
| | **Total** | **802** |

So: **802 identity lines inside 4,239 lines of file.** The problem was never bulk
— it is that a ~50-line decision is spread across four files with an 824-line
file shadowing it and two email-keyed minters standing next to it.

### Phase 1 — pure deletion, zero behaviour change: **−337 lines**

- Delete `UserLifecycle.php:206–409` (§2) — **−204**, zero callers.
- Neutralise `UserProvisioner`'s mint branch (§3) — **−133** of latent minter,
  replaced by ~5 fail-loud lines.

This alone removes **both** remaining email-keyed minters and the docblock that
instructs people to use one.

### Phase 2 — one match, one create, one mirror: **−140 further**

Target shape — a single small module (`LgIdentity`, ~210 lines) called by both
entry points:

| Function | Responsibility | ~Lines |
|---|---|---|
| `match($patreonId, $email)` | id → email → skeleton; returns `[user, matched_by, conflict]` | 60 |
| `adopt($user, …)` | stamp meta, arbiter role, snapshot, login | 35 |
| `create($email, $name, $vanity, $role)` | username chain + mint | 45 |
| `mirrorEmail($user, $newEmail)` | uniqueness guard + uuid freeze + **member notice** | 70 |

Call sites collapse: onboard's 197+65 → ~55 (call `match()`, switch on outcome,
render terminals); the sweep's 49+85 → ~20 (delegate).

| | Before | After |
|---|---|---|
| Identity surface | **802** | **~325** |
| Reduction | | **−477 (≈60%)** |

The duplication being removed is real and specific: the id→email lookup exists
twice (`onboard:1222,1238` vs `sync:499–511`), and the email mirror with
uniqueness guard exists twice (`onboard:1341–1355` vs `sync:684–754`).

`stamp_looth_uuid` is already shared correctly — onboard calls the sweep's copy
(`onboard:1351`). Keep that pattern; move it into the module.

### Do this at the same time (it is why the refactor is worth doing)

Fold the §4.3 fix into `mirrorEmail()`: before `wp_update_user`, send the member
a purpose-written "your sign-in email is changing" notice tagged
`X-LG-Poller-Intent: notify` so it clears the gate — to **both** old and new
addresses. One function, one place, instead of a behaviour that is currently an
accident of a backtrace check.

### Must NOT be lost

Each of these is load-bearing and was verified against live data:

1. **Patreon-ID-first matching** (`sync:499–504`, `onboard:1222`) — the thing that
   actually stopped the duplicates. Live: 0 duplicate patreon_ids across 1,770
   linked users.
2. **Admin-collision guard** (`onboard:1267–1287`, `:1311–1331`; `sync:696–698`) —
   never hand an admin session out over OAuth; never overwrite an admin's email.
3. **`different_patreon_id` → human review** (`onboard:1243–1265`) — two Patreon
   accounts sharing one email must never auto-adopt.
4. **Skeleton adoption** (`onboard:1306–1363`) — **30 skeleton accounts are live
   right now** (`user_login LIKE 'patreon_%'` with no id meta). Remove this and
   the next 19 of them to self-connect mint duplicates.
5. **Email-mirror uniqueness guard** (`sync:704–730`, `onboard:1344–1348`) —
   refuse the write, keep the link, alert a human.
6. **`_looth_uuid` immutability** (`sync:780–801`, `profile-sync.php:28–34`) —
   re-deriving on an email change breaks the JWT `sub` and logs members out as
   strangers.
7. **looth4 / administrator protection** (`sync:547–554`, `:845–847`).
8. **`payment_source=stripe` coexistence skip** (`sync:556–568`).
9. **Arbiter as sole role writer** — never a raw `set_role`.
10. **`record_patreon_member` snapshot at every terminal** (`onboard:1399`,
    `:1465`) — member provisioned immediately, not next sweep.
11. **`lgpo_onboarded_at` one-shot gate** (`onboard:1453–1462`) — keeps operator
    notification to exactly one mail per member.

---

## 7. Recommended order of work

| # | Change | Size | Risk | Why now |
|---|---|---|---|---|
| 1 | ~~Un-suppress the email-change notice to the member (§4.3)~~ **DONE** — `lgpo_notify_email_change()`, `lg-patreon-onboard.php` | +47 | low | Members were silently losing their login identifier |
| 2 | `profile_update` hook **+ live nginx route** (§5) | ~25 lines + conf | low | Receiver already built and correct; 18 members drifted |
| 3 | Delete `UserLifecycle.php:206–409` (§2) | −204 | none | Zero callers; its docblock is an active instruction to regress |
| 4 | Fail-loud the `UserProvisioner` mint branch (§3) | −133/+5 | low | Runs every 5 min; only remaining hot email-keyed minter |
| 5 | Backfill the 18 drifted profile-app emails | script | low | After #2 exists |
| 6 | Unify into `LgIdentity` (§6 phase 2) | −140 | medium | Only after 1–4, and only if Ian wants it |

Items 1–4 are independent and can ship separately. Item 6 is optional — the code
is correct as it stands; unification buys comprehensibility, not correctness.

### Item 1 — shipped on this branch (Ian approved 2026-07-29). NOT deployed.

`lgpo_notify_email_change()` in `lg-patreon-onboard.php`, hooked on
`profile_update` — the single point every `wp_update_user` email change passes
through (the sweep's mirror, the skeleton-adopt mirror, admin edits). Sends one
short purpose-written notice — *"Your sign-in email for … is now X"* — to **both**
the old and new address, tagged `X-LG-Poller-Intent: notify`. **The gate itself is
untouched.**

Proven on dev2 with the same frame shape `sync_wp_email()` has:

```
BEFORE  SUPPRESSED  tagged=no   "Notice of Email Change"                 -> old
AFTER   PASSED      tagged=yes  "Your sign-in email for … has changed"   -> new
        PASSED      tagged=yes  "Your sign-in email for … has changed"   -> old
        SUPPRESSED  tagged=no   "Notice of Email Change"                 -> new
```

Controls confirm the gate still works as before: `lgpo_notify_admin()`
(poller-framed, untagged) → SUPPRESSED; `lgpo_notify_failure()` (poller-framed,
tagged) → PASSED. Assertions are on the gate's `pre_wp_mail` verdict, never on
`wp_mail()`'s return value.

**Known trade-off, flagged for Ian:** on an *admin-initiated* edit (no poller
frame) BuddyBoss's notice is not suppressed, so the old address receives both it
and this one. An extra security notice is the benign direction; silencing
BuddyBoss/core is a separate decision and was not taken here.

---

## 8. Out of scope, handed over

The 11 skeleton accounts whose embedded Patreon id matches a **different**
account's `lgpo_patreon_user_id` meta (WP #84, 195, 399, 471, 505, 615, 676,
1154, 1407, 1516, 1520) are **data repair and belong to the `dupe-merge` lane** —
this audit did not touch them and takes no position on survivor selection. They
are cited here only as evidence that guard #4 above is load-bearing. Note for
that lane: three of the partner accounts (#1574, #1333, #1690) carry a
`lgpo_patreon_user_id` that disagrees with their own `patreon_<id>` username —
worth a look before merging.

**Nothing in this audit changed any code, and nothing touched live.**
