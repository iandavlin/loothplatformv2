# #170 — three states for the header Join (PLAN, awaiting Ian)

> Lane `170-live-header-allowlist`, branch level with `origin/main` (0/0) at
> `5833f38`. Nothing written yet — this is the plan-first wall.

## The ruling

Ian, 2026-08-20, verbatim on #170:

> "We need the join button in the header to still go to patreon unless a test
> user is there on live."

## What I measured before designing (§0)

Everything below was executed, not read.

### 0a. The allowlist mechanism already exists, and it is one list

There is no second list to invent. The soft-launch cohort is
`wp_option lgms_stripe_lifecycle_allowlist` (PHP-serialized array of WP user
ids), written by `LGMS\CohortAllowlist` in the poller's admin dash, and read
two ways that must never drift:

| reader | who | how |
|---|---|---|
| `LGMS\StripeLifecycle::inCohort($uid)` | webhook, sweep, **and the capability** | list only |
| `lg_membership_in_stripe_test_group($uid)` | membership-pages router | `lgms_stripe_testgroup_pages` **AND** list |

The **header already receives the answer as a capability**:
`InternalRestController.php:179` computes
`$caps['stripe_testgroup'] = manage_options || inCohort($uid)`, it rides whoami
to every consumer, `Whoami::capabilitiesFor()` passes it through, and
`site-header.php:166` already reads it as `$stripe_tester`.

**So this lane needs no user id, no DB call, and no new list — only a new use of
a capability the partial is already handed.** That is the reuse the charter asked
for.

### 0b. ⚠️ The header's Join pill renders for ANON ONLY — measured

`.lg-chrome__join` (site-header.php:711) sits inside the `else` of
`if ($authenticated)`. Rendering the partial four ways:

| viewer | `.lg-chrome__join` | `/lgjoin/` | bytes |
|---|---|---|---|
| anon | **1** | 0 (patreon) | 28,028 |
| member, not listed | **0** | 0 | 39,455 |
| member, **test group** | **0** | 1 (account menu) | 40,117 |
| admin | **0** | 1 (account menu) | 40,859 |

**A logged-in test user never sees the header Join button at all.** A tester's
`/lgjoin/` door today is the account-menu entry (site-header.php:661-664), which
is gated on the same `$stripe_tester` capability and carries no flag.

**This is the fork this plan turns on.** Implemented literally — "Patreon for
anon and non-listed members, `/lgjoin/` only for a listed member" — the
`allowlist` state renders **byte-identically to `off` for all four viewers**: the
one control it would change does not exist for the one viewer it would change it
for. That is a vacuously-green feature of exactly the class MEMBERSHIP.md already
records ("THE OBVIOUS GATE ASSERTION IS A VACUOUS GREEN").

### 0c. ⚠️ A phone-width tester has no path to /lgjoin/ at all

`bb-mirror/web/forums.css:4224` — `@media (max-width:640px){ #site-header
.lg-chrome__aside{display:none!important} }` on the hub — hides the whole aside,
**including the account menu**. `webroot/bottom-nav.js:845 buildSheet()` builds
the authed "You" sheet with notifications / messages / saved / settings and
**no destination links at all** ("the Nav tray is the sole mobile menu now",
Ian 6/24). Only `buildAnonSheet()` carries a Join row.

So on the hub at ≤640, a signed-in tester has **no reachable `/lgjoin/`** today.
Any design that hands a tester a header pill must answer this width too, or it
repeats the presence-is-not-reachability trap for the third time.

### 0d. dev2's `.local.php` must keep working through the merge

`/home/ubuntu/loothplatformv2-clean/platform/config/header-join-stripe.local.php`
is `return array('enabled' => true);` — a gitignored file in the **serving
checkout**, which this lane must not touch. If the new reader stopped
understanding `enabled => true`, dev2's header would silently revert to Patreon
the moment the serving checkout pulls, with nobody having flipped anything.
**Backward compatibility of the reader is therefore a hard requirement, not a
nicety** — `enabled => true` must keep meaning `on`.

## The design

### 1. The flag becomes three-state, and keeps reading the old shape

`platform/config/header-join-stripe.php` → `return array('state' => 'off');`

New `lg_shared_header_join_stripe_state(): string` returning
`'off' | 'allowlist' | 'on'`, resolution order unchanged from #165 (tracked →
`.local.php` → `LG_HEADER_JOIN_STRIPE` from **both** `getenv()` and `$_SERVER`):

- `state` key present and one of the three → that.
- else legacy `enabled` key: `true` → `'on'`, `false` → `'off'` (0d).
- unknown string / non-array / missing / unreadable / parse-free garbage →
  **`'off'`**. Fails closed to today's behaviour, same as #165.
- env accepts `off|allowlist|on` plus legacy `1|true|0|false`.

`lg_shared_header_join_stripe_enabled(): bool` stays, as
`state() === 'on'`, so nothing that calls it breaks.

### 2. The render rule

```php
$state       = lg_shared_header_join_stripe_state();
$join_stripe = ($state === 'on') || ($state === 'allowlist' && $stripe_tester);
$join_href   = $join_stripe ? '/lgjoin/' : 'https://www.patreon.com/...';
$join_external = (bool) preg_match('#^https?://#i', $join_href);   // unchanged
```

**The caching law is satisfied structurally, not by care.** The `allowlist` swap
keys on `$caps['stripe_testgroup']`, which an anonymous ctx never carries (it
fails closed to `false`), so an anon render in `allowlist` cannot differ from an
anon render in `off` — and the gate proves it with `cmp`, the way #165 proved OFF.

### 3. DECIDED (Ian, 2026-08-20) — the tester gets a header pill (Option 1)

**Option 1 — CHOSEN. The tester gets the pill.** In `allowlist` only, the
aside renders a `.lg-chrome__join` → `/lgjoin/` for a signed-in tester. Ian's
sentence becomes literally true, and he gets a header button to click on live
while signed in as a test user. `off` and `on` keep authed renders **byte-for-byte
what #165 proved**, so every existing proof stays valid and the new markup exists
only in the new state, only for the hand-picked cohort. Requires the ≤640 answer
in 0c (a Join row in the authed You sheet in that state).

**Option 2 — NOT chosen, recorded for the next reader. Anon-only swap.** `allowlist` renders byte-identically to `off`
everywhere; the tester's door stays the account-menu Join that already ships.
Honest, minimal, and **decorative**: live would sit at `allowlist` with no
observable difference from `off`, and any gate on it is vacuously green.

### 4. Gate 79 grows the third state, red-first

Number stays 79 (extension, per charter). New assertions:

- **§B render matrix** — `allowlist` × {anon, authed-not-listed, authed-tester,
  authed-admin}, each asserted for href, `target`, and pill presence.
- **caching law** — anon bytes in `allowlist` `cmp`-identical to anon bytes in
  `off` and to `origin/main`'s anon render. Plus the ABSENT-config case.
- **config shapes** — `enabled=>true` ⇒ `on` (the 0d migration, asserted so the
  dev2 file cannot quietly stop working), `enabled=>false` ⇒ `off`,
  `state=>'allowlist'`, unknown state ⇒ `off`, non-array ⇒ `off`, unreadable ⇒ `off`.
- **the vacuity guard** — an explicit assertion that `allowlist` differs from
  `off` for the tester. Under Option 2 this assertion cannot exist, which is the
  clearest statement of what Option 2 costs.
- **§E coupling, one column over** — while `allowlist` is served, a tester's
  `/lgjoin/` must admit a tester: that needs `lgms_stripe_testgroup_pages` ON
  **and** the member on the list (`lg_membership_in_stripe_test_group`, two
  locks). Same trap #165 found for anon, so it gets the same treatment: assert
  while `allowlist`/`on` is served, report while `off`.
- **red-first** — every new assertion reddened first by a snapshot mutation
  (never `git checkout --`), each reddening its own named assertion, plus a
  proven-inert no-op.

### 5. Docs, in the closing commit

`docs/domains/MEMBERSHIP.md` (charter), `docs/FLAGS.md` row rewritten three-state,
the tracked config docblock, `docs/CRAFT-STANDARD.md` row 79 amended.

## Files I expect to touch

Guessed wide, per LANE-RULES.

| file | why |
|---|---|
| `platform/config/header-join-stripe.php` | three-state tracked default |
| `lg-shared/site-header.php` | state reader + render rule (+ tester pill, Option 1) |
| `webroot/bottom-nav.js` | Option 1 only: Join row in the authed You sheet (0c) |
| `tools/gates/header-join-gate.py` | gate 79 grows the third state |
| `tools/gates/header-join-redfirst.sh` | red-first mutations for the new assertions |
| `tools/gates/run-all.sh` | gate 79 header comment only, if the description moves |
| `docs/FLAGS.md` | the flag row becomes three-state |
| `docs/CRAFT-STANDARD.md` | row 79 amended |
| `docs/domains/MEMBERSHIP.md` | closing commit |
| `handoffs/plans/170-live-header-allowlist-PLAN.md` | this file |
| `handoffs/2026-08-20-170-live-header-allowlist.md` | closing handoff |

**Not touched, deliberately:** `lg-shell/lg-shared/site-header.php` — a 6/18 seed
snapshot, untouched since, carrying no flag and a different Join (`/join/`).
#165 left it alone; noted, not fixed.

## Noticed, not in scope

- The **821–904px dead band** (#165's `KNOWN_MAIN_GAPS`) is still open and still
  Ian's call. Option 1 adds a control to the authed aside, which is a different
  cluster from the anon one that overflows — but it is the same neighbourhood and
  will be measured at those widths rather than assumed.
- The three anon Patreon join CTAs #165 left alone (`directory-members.php:154`,
  `archive-poc/web/defaults.php:88`, `_chrome-footer.php:40`) are unchanged.
- `gh` is not authenticated in this worktree, so #170's issue body could not be
  read directly; the charter's verbatim quote is the ground truth used here.
