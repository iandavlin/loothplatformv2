# Lane 203-route-exemptions — the dead server-to-server routes

Branch `203-route-exemptions`, 4 commits, 11 files. Issue #203, label `membership`.
**No flag** — the routes are unconditional, so unlike #193's rider there is no
second place to read one wrong.

## What it does, in one line

Three shared-secret routes in `lg-member-sync/v1` answered **401
`bb_rest_authorization_required`** to the billing app holding the **correct**
secret. Each now gets past BuddyBoss's blanket REST restriction through
BuddyBoss's own documented hook, one filter per route, so each route's own
`auth()` is what refuses. `auth()` is untouched.

## The measurement — before and after, on the running box

`tools/verify/203-route-exemptions-verify.php`. It loads the branch's filter
code, **asks it** for the route strings (nothing transcribed), and drives them
through BuddyBoss's real `bb_restricate_rest_api` via `rest_do_request()` —
filters off, then on, in one process, so "it answers now" cannot be a fact about
the box rather than about the change.

```
cp tools/verify/203-route-exemptions-verify.php /tmp/v.php && chmod 644 /tmp/v.php
sudo -u looth-dev env LG203_ROOT=$PWD wp eval-file /tmp/v.php --path=/var/www/dev
```

| route | before | after |
|---|---|---|
| `/sync-customer` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"customer_id required"}` |
| `/send-gift-codes` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"to_email and codes required"}` |
| `/send-gift-recipient` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"recipient_email missing or invalid"}` |
| all three, **wrong OR absent** secret | 401 `bb_rest_authorization_required` | **401 `rest_forbidden`** — our own check |
| `/run-now` | 401 `bb_rest_authorization_required` | **unchanged, on purpose** |

Side-effect free **by construction**: every request carries no body, so each
route refuses at its own required-parameter guard. **No customer is synced and no
gift mail is sent.** That refusal *is* the "own JSON" — it is this plugin
answering, which is the whole thing that was impossible before.

The same three were re-measured over real HTTPS with `--resolve` before any code
changed, and the two already-exempted routes were measured beside them as the
control: `/patreon-standing` → 400 own JSON, `/checkout-audience` → 200 own JSON.

## ⚠️ The issue said two, and the file said three

#203 named `/sync-customer` and `/send-gift-codes`. Enumerating `RestController`
instead of trusting the count found **`/send-gift-recipient`** behind the same
wall, behind the same `auth()`, **in the same flow as the second**: Slim's
`WpGiftMailer::sendOneRecipient()` calls it for the **Send / Resend / Reassign**
buttons on the buyer's My Gifts dashboard, and `WpGiftMailer::post()` is
best-effort by design — errors logged, never raised. So opening its twin alone
would have made a gift **arrive when bought and vanish when resent**, with the
button reporting success both times.

Boarded to keeper as a ruling request rather than decided in passing; **keeper
ruled it in and widened the issue (3 → 6 filters, not the 3 → 5 the issue asked
for)**. A count in an issue is a starting point, not a measurement.

## ⚠️ `/run-now` stays shut, and that is a decision

Same 401, same correct secret. Ops-only, nothing but a person calls it, the
five-minute cron does its job, and what it exposes is a **whole Tick** — the one
route where #181's *"the sweep covers it"* is still the true sentence. Keeper
agreed. It is now also **M33's wrong-decision mutation**: appending it to a
neighbour's filter is exactly the widening keeper's condition 3 forbids.

## ⚠️ Read the code, not the number — both refusals are 401

`rest_forbidden` is the route's OWN secret check saying no; healthy.
`bb_rest_authorization_required` is BuddyBoss pre-empting the REST stack at
`rest_request_before_callbacks` (priority 100) **before any
`permission_callback` runs**, which cannot tell the billing app apart from an
anonymous stranger. An assertion phrased *"it refuses"* passes on both and
measures nothing.

The mechanism is an **exact** `in_array()` against `$request->get_route()`
(`bb_restricate_rest_api`, `bp-core/bp-core-functions.php:6106`), so the route
constants must be the pattern to the character.

## It is a repair, not a bypass

All three carry `permission_callback => auth()`, which requires a **configured**
secret and compares with `hash_equals`. Untouched — only *which* check refuses
changes. Gate 86 §K10n/§K10o pin that none of the three quietly became
`__return_true` in the same edit (the single change that would turn this into the
thing it is not), and §K10k/§K10l/§K10m **execute** `auth()` rather than reading
it: unconfigured refuses an empty token, the correct secret is accepted, a
near-miss is refused.

## Three filters, one per route, one shared appender

A single combined filter would be smaller and would make the next widening
invisible. `appendExemption()` is what stops the three drifting on the things
that matter: a non-array is handed back untouched, entries are appended not
replaced, idempotent. **It takes one route and cannot take a list.**

## The health panel was lying, and it is why `SECRET_ROUTES` exists

`Health::checkChannel()` said *"Our exemption filter registered — yes,
checkout-audience is exempted"* from #181 until now. By this morning #193 and its
rider had opened three more, so an operator would have read the rest as shut
while two were open — **the health panel failing its one job, quietly, with every
assertion above it green.**

It now **runs the hook** for the roll-call and subtracts it from the new
`RestController::SECRET_ROUTES` for the still-shut line, so neither can be kept
up to date by hand. Gate 86 §K11 asserts that constant against `register()` in
both directions, so a shared-secret route added and never named is a RED.

## Gates

Run individually per the charter and #175. **`run-all.sh` was deliberately not
run**: this is a server-to-server repair plus one admin-only panel line, not a
member-facing surface.

| gate | result |
|---|---|
| **86** checkout-audience (§K10 + §K11 new, §K3 re-aimed, §K3j/§K9b 3 → 6) | GREEN 261 |
| **91** membership-health (§F10, the roll-call) | GREEN 121 |
| red-first 86 | **58/58** + 5 no-op controls |
| red-first 91 (unchanged, re-run) | 65/65 |
| 93 products-tab · 76 multi-tier · testgroup-pages · double-pay-block | GREEN |
| testgroup-sweep · test-identity-gate · test-checkout-session-metadata · test-soft-launch-allowlist (34) | GREEN |
| red-first double-pay 17/17 · red-first products-tab 41/41 | clean |

## ⚠️ Five gate/harness defects found — all by red-first, none by review

1. **My own §F10e measured nothing.** It read *"the still-shut LINE does not name
   it"* and asked `real_exemptions()` — a fact about the **filters**. The panel
   could report all four routes as shut and it stayed green; M58 proves it. Gate
   91 gained `line_value()`, because `words()` concatenates every line and the
   roll-call names them all a few characters earlier. **Ask a line, not a blob.**
2. **`fn_body('auth')` PREFIX-MATCHES.** It returned `authLoggedInUser()`'s body,
   so §K10i asserted `hash_equals` about the wrong function. It **FAILED** rather
   than passing, which was luck — that function has a nonce check and no
   `hash_equals` at all. Pin the open paren. Other gates use `fn_body`; the same
   trap is live for any name that is a prefix of a sibling.
3. **Gate 91's `apply_filters` stub returned `$value` unchanged**, which made the
   hook unmeasurable — a stub that always answers "nothing" cannot be told apart
   from a panel that finds nothing. It answers per scenario now, from the real
   filters via `real_exemptions()`, never a transcription.
4. **`sub()`'s docstring claimed it raises on a non-unique anchor and never
   checked.** `str.replace(count=1)` takes the FIRST match, so M47
   (*"appendExemption turns a non-array into an array"*) was mutating
   `exemptAuthFromBuddyBossRestriction` — the two share a guard clause character
   for character. It went RED, so nothing complained: **a false RED attributed to
   the wrong assertion**, the same family as this repo's false GREENs and just as
   expensive. `sub()` refuses ambiguity now; M35, M47, N4 widened until unique.
5. **M18 and M33 both used `/sync-customer` as their widening mutation.** After
   this lane that is a *legitimate* exemption spelled differently, not a wrong
   decision — both re-aimed at `/run-now`, which keeper named.

## Stale claims corrected in the same commit

Four places asserted these routes stay shut and would have been confidently
wrong: both precedent controllers' docblocks
(`CheckoutAudienceRestController`, `PatreonStandingRestController`),
MEMBERSHIP.md's #193 and #197 sections, and #192's *"BuddyBoss is still eating
the route the billing app calls after every checkout"* — that one closed with its
own re-measurement.

## Deploy

**One pull, no config coupling, no symlink change** — the poller mu-plugin is
already symlinked into the serving checkout. Nothing for keeper to place and
nothing for Ian to click.

⚠️ **On LIVE it changes nothing yet.** Live's `lgms_shared_secret` is still
**ABSENT** (#192's measurement), so post-merge these routes answer
`rest_forbidden` to everyone until Ian sets it — fail-closed, correct, and the
same go-live blocker #192 already recorded rather than a new one.

## Noticed, not fixed

- **`/refund-request` is behind the same wall** (`permission_callback =>
  '__return_true'`, so it is a *public* route, not a shared-secret one). It 401s
  an anonymous caller. It is reached from `membership-pages/web/request-refund.php`
  by a **signed-in** member, and BuddyBoss's wall admits authenticated users, so
  it works today for the people who use it. Out of scope here; worth a look if
  anyone ever wants that form to work logged out.
- **`InternalRestController`'s route is in the `looth-internal/v1` namespace**,
  which is on live's BuddyBoss public-content allow-list, so it is past the wall
  already (measured: `rest_forbidden`, its own check). Nothing to do.
- **Gate numbering:** `products-tab-gate.php` prints "GATE 93" and
  `switch-menu-gate.py` (#196) is also numbered 93 in places. Pre-existing
  collision already on the board; not touched.

## Files outside the plan (flagged per LANE-RULES)

Four, all in-scope consequences rather than scope creep:

- `tools/gates/membership-health-gate.php` — **required**, not optional: Health
  now asks `RestController::SECRET_ROUTES`, and without the added require that
  call is a **fatal at exit 255 with no FAIL line**, which this plugin's test
  files have died from three times.
- `lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php` and
  `.../PatreonStandingRestController.php` — **docblock only**, the stale
  "stay shut" claims this change makes wrong.
- `tools/verify/203-route-exemptions-verify.php` — new, the re-runnable proof.

## State

1 commit behind `origin/main`. **Not rebased**, per LANE-RULES. Worktree left in
place, branch pushed.
