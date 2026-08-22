# Lane 201 — shared-secret STATUS + REFRESH in the membership dash

Issue **#201**, `approved` + `membership`. Branch `201-secret-status`.
Plan `handoffs/plans/201-secret-status-PLAN.md`, approved by Ian; the one open
ruling (the Settings-tab field) was decided by keeper as **(c) retire it**.

**PICTURE:** https://dev2.loothgroup.com/mockups/lanes/201-secret-status.html

---

## What shipped

A **Shared secret** section, first on the Health tab of LG Member Sync. In every
state, healthy included, it spells out:

| | |
|---|---|
| WordPress | `set — 64 characters` / `NOT SET` |
| Billing app | `set — 64 characters` / `NOT SET` / cannot see it — **which** of four reasons |
| Do they match? | `MATCH` / `DIFFER` / `NOT SET in WordPress` / `NOT SET in the billing app` / `NOT SET anywhere` / `CANNOT COMPARE` |
| | `Checked at 19:46:49 UTC` |

…plus a **Refresh** button that re-reads both halves without a page load, and
the two command-line lines with Copy buttons. **No input field anywhere.**

`Health::secretPair()` is the one comparison. `Health::sharedSecret()` reads this
pair alone. `SharedSecretPanel` renders it and owns the AJAX door.
`checkSecrets()` keeps the webhook secret through the same helper.

## Files touched

    lg-patreon-stripe-poller/src/SharedSecretPanel.php      NEW
    lg-patreon-stripe-poller/src/Membership/Health.php      secretPair(), sharedSecret(), checkSecrets() narrowed
    lg-patreon-stripe-poller/src/HealthPanel.php            renders it; folds it into the headline
    lg-patreon-stripe-poller/src/Admin.php                  boot(); field + registration retired; pointer added
    tools/gates/shared-secret-status-gate.php               NEW  gate 98
    tools/gates/shared-secret-redfirst.py                   NEW
    tools/gates/membership-health-gate.php                  §B re-pointed, require list, I3 fixture
    tools/gates/membership-health-redfirst.py               anchors follow the refactor; 2 new mutations
    tools/gates/run-all.sh                                  gate 98 registered
    docs/CRAFT-STANDARD.md                                  next-free 98 → 99, row 98
    docs/domains/MEMBERSHIP.md                              domain rule
    handoffs/plans/201-secret-status-PLAN.md, handoffs/2026-08-22-201-secret-status.md

Nothing outside the plan. **No flag** — dash-only, matching #190/#192/#194/#183.

## Gates

| | assertions | red-first |
|---|---|---|
| **98** shared-secret status | 78 | **51/51** (48 mutations + 3 no-op controls) |
| **91** health panel (neighbour) | 104 | **67/67** |

Run individually (#175), all green and re-run after the last commit: 91, 98,
comp-expiry (96), checkout-audience (224), double-pay-block (131), tester-dash,
products-tab, `test-identity-gate`, `test-checkout-session-metadata`.

## The four things worth carrying forward

1. ⚠️ **Retiring a settings FIELD without retiring its REGISTRATION blanks the
   option on every Save.** `wp-admin/options.php:336-345` walks the registered
   options of the submitted group and calls `update_option( $option, null )` for
   every one **absent from POST** — verified in the running WordPress, not
   recalled. Removing the input alone would have emptied `lgms_shared_secret`
   the first time anyone pressed Save on that tab, silently. Gate 98 §I2b.
2. ⚠️ **The issue's own premise was half wrong, and measuring it is what caught
   the leak.** #201 asked the screen to say setting is a command-line act; it was
   not, and `Admin.php:1613` printed the live secret into the Settings tab's HTML
   source through a `value=` attribute. Gate 91's leak sweep covers `HealthPanel`
   and deliberately never loads `Admin.php`, which is how it stood.
   `lgms_db_pass` and `lgms_stripe_secret_key` carry the same shape — **reported,
   not touched**; keeper is filing them with #197's plaintext-`db_pass` finding.
3. ⚠️ **A refresh needs BOTH cache layers dropped.** dev2 runs a persistent
   object cache and this option is **autoloaded**, so it is served from the
   `alloptions` blob and not from its own key. Dropping only the key would have
   answered stale on the very box the button was written for.
4. ⚠️ **Nine gate defects, none found by review.** Six by red-first: §C4 wrote
   ONE settings file six times and rendered one state under six names; §B6 asked
   "both halves" of the whole render and passed off one half; §H1 matched a
   different sentence in the same page; §G1 was vacuous because the cache stub
   let an `alloptions` delete wipe everything; §C3e could not fail because
   nothing threw mid-render; §E3 counted a CSS rule as a renderer. Three by
   **building the real screen and looking at it** — see below. And in the
   harness: one mutation was **BROKEN rather than WRONG** (a dangling brace) and
   killed the run at exit 255, in the very file whose docblock warns about it.

## Verified on dev2, against both real halves

Real code, real stores, driven as the site's own user — the option out of dev2's
database and `LGMS_SHARED_SECRET` out of the real `/srv/lg-stripe-billing/.env`:

    env state  : ok            verdict : match      both halves 64 characters
    refresh    : HTTP 200, 3,711 bytes of markup
    leak check : CLEAN  (value, fragment, prefix and sha256 of BOTH real halves)
    inputs     : 0            on the page AND in the refresh response

⚠️ **Three defects the picture found that 78 green assertions had not:** the
chips rendered as identical plain grey text (the palette lives in `HealthPanel`'s
style block and this section only borrowed the class names); **PHP swallows one
newline after `?>`** so the billing-app command rejoined into one line running
off the edge; and the copy told the operator to reload PHP-FPM, which — checked,
not assumed — `LGSB\App::create()`'s per-request `Dotenv::…->load()` makes
unnecessary. All three are gate legs now (§H8, §H4b, §H4c).

## Noticed, deliberately not fixed

- ⚠️ **`lg-patreon-stripe-poller/PICKUP.md:140` is confidently wrong about
  deploy** — it says the poller is a wp-content plugin deployed by
  `deploy/patch-*.py` and *not* git pull. Both boxes contradict it: the
  mu-plugin is a symlink into the serving checkout on **dev2 AND live**, and the
  plugin is PSR-4, so a new class needs no require-list edit and no symlink. **A
  pull delivers this whole lane.** One line; keeper's call.
- **Live's `mu-plugins/lg-patreon-stripe-poller.php` loader is a real file**, not
  a symlink as on dev2. Content matches the repo and it only requires the folder's
  main file, so nothing here depends on it — recorded because a change to *that
  loader* would not reach live by pull.
- The neighbouring invite panel still puts a raw token in a redirect query arg
  (#190 observed the same); different token, not this issue.

## Deploy

**One pull.** No nginx, no pool, no `.local.php`, no symlink, no migration.

## Owed

- **Ian** looks at the tab on dev2 after the merge.
- **The live gap is `lgms_shared_secret` itself** — still absent on live, still a
  live write and therefore his. This section is what will say so out loud, and
  what will confirm the fix landed on **both** halves the moment he presses
  Refresh.
- ⚠️ The dev2 serve runs `main`, so nothing here is reachable over HTTP until it
  merges; everything above was verified by driving the real classes against the
  real stores on the real box.
