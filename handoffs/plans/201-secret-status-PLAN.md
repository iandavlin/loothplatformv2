# Lane 201 — shared-secret STATUS + REFRESH in the membership dash

Issue #201, `approved` + `membership`. Ian reshaped it himself, verbatim:
*"Should just be a refresh button or something with a status check."* — that
supersedes the paste-in field of the first draft.

**No code written yet.** Everything below is measured, not assumed; the
measurements are named inline so the next reader can tell a fact from a guess.

---

## 1. What the dash says about the shared secret TODAY — measured

`Membership\Health::checkSecrets()` (`Health.php:362`) already compares the two
halves, for **two** pairs at once — the shared secret and the Stripe webhook
secret — inside one Health-tab card titled *"Do the two halves agree?"*. It is
correct and it is #192's work. Three things about it are why #201 exists:

1. **It only runs on a page load.** There is no way to re-ask after fixing
   something; you reload the whole dash.
2. **The shared secret is one row inside a card among six cards**, and in the
   healthy branch it collapses to `AGREE — both set, 64 characters`: the
   per-half present/absent is only spelled out when something is already broken.
3. **Its own note is wrong about where to set it** — it says *"Set the
   WordPress side on the Settings tab"*, and `lgms_stripe_webhook_secret` is
   **not registered and has no field there** (checked `registerSettings()`,
   `Admin.php:193-212`, and grepped the whole plugin: the only non-test
   occurrences are `StripeLifecycle::SECRET_OPT` and Health). Half that
   sentence points at a control that does not exist.

## 2. ⚠️ THE ISSUE'S PREMISE IS HALF WRONG, AND IT IS THE ONE THING I CANNOT DECIDE

#201 says, and asks the screen to say: *"SETTING the secret stays a
command-line act, deliberately."*

**It is not, today.** `Admin.php:1613`, Settings tab:

    <input type="password" name="lgms_shared_secret"
           value="<?php echo esc_attr( get_option( 'lgms_shared_secret', '' ) ); ?>" ...>

That field **sets** the secret, and its `value=` attribute **prints the live
secret into the HTML source** of the Settings tab. `type="password"` hides it
from the eye and not from View Source. The same shape carries `lgms_db_pass`
(1594) and `lgms_stripe_secret_key` (1600).

It is admin-only (`manage_options`), so this is not a public leak — but it
means a screen that says *"setting is a command-line act"* would be lying about
its own dash, three tabs away. **Ruling needed before I write that sentence.**
The issue's own reasoning is worth quoting because it argues against the
existing field rather than around it: *"on live the app's half lives in a
root-posture file the web user cannot read (by design), so any dash setter is
either half-a-pair or a privilege machine nobody asked for."* A setter that can
only move one half is exactly the trap that produced the halves-disagree
failure this lane is about.

**Options, with my recommendation last:**

- **(a) Leave it alone**, and have the new section say the accurate thing
  instead ("there is a field on the Settings tab; the runbook way is the
  command line"). Smallest diff. The dash keeps a half-a-pair setter and keeps
  printing the value into markup.
- **(b) Stop echoing the value** — render the field with a placeholder
  (`•••• set (64 characters)`, blank = leave unchanged) so the setter still
  works and the value leaves the markup. Standard WP pattern. Does not make the
  issue's sentence true.
- **(c) RECOMMENDED — retire the shared-secret field from the Settings tab**
  and print the two runbook lines in its place, so the dash and the new section
  agree and the half-a-pair trap is gone. `lgms_db_pass` and
  `lgms_stripe_secret_key` are **reported, not touched** — different secrets,
  different issue.

(c) is a widening past "add a status and a refresh", so it is not mine to take.
I will build to (a) unless keeper rules otherwise; switching to (b) or (c)
afterwards is a small edit and a gate section, not a rebuild.

## 3. Shape of the build

### 3.1 A dedicated **Shared secret** section, first on the Health tab

Not an eleventh tab: the dash already has ten, and the 8/22 standing rule is
never a new menu. First on that tab because when this channel is down every
other answer on the screen is meaningless — server-to-server auth is what it
gates.

It always shows, in every branch, healthy or not:

| | |
|---|---|
| WordPress | set (64 characters) / **NOT SET** |
| Billing app | set (64 characters) / **NOT SET** / cannot be read — *which of the four states* |
| Verdict | **MATCH** / **DIFFER** / cannot compare |
| Checked at | `19:42:11 UTC` |

### 3.2 ⚠️ It is reported ONCE, so the shared secret comes OUT of `checkSecrets()`

Leaving it in both places puts the same fact on one screen twice in two
presentations — the #199 two-stacked-panels shape, which this repo has already
paid for once. So:

- extract the per-pair comparison to `Health::secretPair()` — **one
  definition**, unchanged logic;
- `Health::sharedSecret()` (new, public, renders nothing) uses it for the new
  section;
- `checkSecrets()` uses it for the webhook secret alone, and the card is
  retitled to say so;
- **gate 91 §B is re-pointed, not deleted** (the §I9 discipline): it keeps
  asserting the pair-comparison behaviour against the pair that card still
  holds — the same code path through `secretPair()` — and **gains** an
  assertion that the shared secret is *not* in that card, so a future merge
  that re-adds it is a RED rather than a silent duplicate. The
  shared-secret-specific assertions move to gate 98. Net coverage is equal or
  better; nothing is dropped.
- that card's wrong note (§1.3 above) is corrected in the same commit.

### 3.3 The Refresh button

`wp_ajax_lgms_shared_secret_status`, in its own class with its own boot — the
`HealthPanel` / `TesterUnlockPanel` / `ProductsPanel` pattern, so gate 98 can
drive it without loading `Admin.php` (whose neighbouring test files have died
at exit 255 with no FAIL line three times over a missing require).

Four things about it are load-bearing rather than detail:

- **ONE RENDERER FOR BOTH PATHS.** The AJAX handler returns the *same*
  `renderBody()` the page load calls, not a second client-side render. Two
  renderers is two chances to leak a secret and two things to gate; one is
  asserted once and covers both. Gate 98 §E asserts it by tokenizer.
- **A REFRESH THAT CAN RETURN A CACHED ANSWER IS A LIE.** Measured: dev2 runs
  a persistent object cache (`/var/www/dev/wp-content/object-cache.php`,
  105,926 bytes) and `lgms_shared_secret` is autoloaded (`wp_options.autoload =
  auto`, length 64). `update_option` does invalidate, so a plain round-trip is
  *probably* fresh — "probably" is the word this panel exists to remove. The
  handler calls `Health::reset()` and drops that option's cache entry
  (and `alloptions`) before reading. Gated with a stubbed cache that would
  otherwise answer stale.
- **BOTH LOCKS, NEVER ONE**: `check_ajax_referer` AND
  `current_user_can('manage_options')`; no `nopriv` registration exists.
- **THE ERROR PATH IS WHERE A SECRET ESCAPES.** Every `Throwable` is replaced
  with a fixed sentence — **never `$e->getMessage()`**, because a PDO or file
  exception message can carry a value. Gate 98 throws an exception whose
  message *contains* the secret and requires that it does not reach the
  response.

### 3.4 No input field, and it says why

No `<input>` bearing the option name, no `type="password"`, no form that could
post a value. In its place, the two runbook lines with a Copy button (the
existing "Copy path" pattern on that tab) — the WordPress half
(`wp option update lgms_shared_secret …`) and the app half (the
`LGMS_SHARED_SECRET=` line in the env file, whose path the panel already
knows). **A real value is never printed, generated or offered.**

### 3.5 No flag

Dash-only, matching #190, #192, #194, #183 and MEMBERSHIP.md's standing rule.
The one shared-code change (`secretPair()` extraction) is a pure refactor of a
read-only reporter; nothing member-facing is reachable from this diff.

## 4. Gate 98 — `tools/gates/shared-secret-status-gate.php`

Number taken from the CRAFT-STANDARD next-free counter line (**98**), bumped to
99 in the same commit. Run individually (#175). Red-first harness
`tools/gates/shared-secret-redfirst.py`, target ≥25 mutations each reddening
its own named assertion, plus no-op controls proven inert.

- **§A** the four env-file states are never conflated — `missing`,
  `unreadable`, `empty`, `ok`. "Cannot read it" must never render as "not set":
  they need opposite fixes.
- **§B** the verdicts, each against a deliberately broken fixture: equal ⇒
  MATCH; different ⇒ DIFFER; **WP absent + app present ⇒ names WORDPRESS as
  the missing half — live's shape today**; both absent ⇒ fail; env unreadable ⇒
  cannot compare, and **not `ok`**.
- **§C** no value, no fragment, no prefix, no sha256 in the rendered section —
  on the **page load**, on the **refresh response**, and on the **error path**,
  with realistic-looking fixtures (gate 91 §A6's mechanics).
- **§D** no input field, no password field, no form posting the option.
- **§E** the refresh path and the page load call the SAME renderer (tokenizer,
  not a regex — gate 90's equivalents matched their own prose twice).
- **§F** both locks on the handler, asserted as the COMPARISONS and not as the
  presence of a string (#193 found three gate defects of exactly that shape).
- **§G** the read is uncached: proven with a cache stub that would answer stale.
- **§H** the command-line sentence and both runbook lines are on screen.
- **§I** wiring, unconditional: `Admin::boot()` registers it, `HealthPanel`
  renders it. Asserted by tokenizer, and asserted **even when it is broken** —
  a gate that stops watching the moment the thing breaks is not a gate (#190).
- **§J** two refreshes produce two different stamps: the refresh is real, not
  decoration.
- A fatal is reported **as a finding**, not as exit 255 with no FAIL line
  (#194's lesson); exit 2 for cannot-run, never 3 (run-all reads 3 as red).

## 5. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES.

**New**
- `lg-patreon-stripe-poller/src/SharedSecretPanel.php`
- `tools/gates/shared-secret-status-gate.php`
- `tools/gates/shared-secret-redfirst.py`
- `handoffs/2026-08-22-201-secret-status.md`

**Edited**
- `lg-patreon-stripe-poller/src/Membership/Health.php` — `secretPair()`
  extraction, `sharedSecret()`, `checkSecrets()` down to one pair, note fixed
- `lg-patreon-stripe-poller/src/HealthPanel.php` — one line, renders the section
- `lg-patreon-stripe-poller/src/Admin.php` — one line, `SharedSecretPanel::boot()`
  (**plus the Settings-tab field, only if keeper rules (b) or (c)**)
- `tools/gates/membership-health-gate.php` — §B re-pointed and widened
- `tools/gates/run-all.sh` — register gate 98
- `docs/CRAFT-STANDARD.md` — next-free 98 → 99, and the ledger row
- `docs/domains/MEMBERSHIP.md` — the domain rule (this issue wears `membership`)

**⚠️ Declared overlap:** lane **203** is in `docs/domains/MEMBERSHIP.md` and in
this same plugin's REST controllers, and lanes 197/200 are also in
MEMBERSHIP.md. My MEMBERSHIP.md edit is a new `State (8/22, #201 …)` section at
the end — additive, no existing line rewritten — so a conflict should be
keep-both. My `Admin.php` footprint is one line in `boot()`; lane 193's region
is the Testers tab and 194's is the Products registration, both elsewhere in
the file.

## 6. Deploy couplings — declared, and one stale doc found

- **A pull delivers all of it.** Measured on both boxes rather than trusted:
  `wp-content/mu-plugins/lg-patreon-stripe-poller` is a symlink into
  `~/loothplatformv2-clean` on **dev2 AND live**, and the plugin autoloads
  `LGMS\` PSR-4 from `src/` (`composer.json`, `autoload_psr4.php`, empty
  classmap) — so a new class file needs **no require-list edit and no symlink**.
  No nginx, no pool, no config file, no `.local.php`.
- ⚠️ **`lg-patreon-stripe-poller/PICKUP.md:140` is STALE and confidently
  wrong** — it says the poller is *"a wp-content plugin, not a /srv git-served
  app … deployed via the self-verifying patchers in deploy/patch-*.py — NOT git
  pull."* Both boxes contradict it: it is an mu-plugin symlinked to the serving
  checkout. A lane that believes that line will hand-deploy over a symlink. One
  line to correct; **I have not touched it** — say the word and it rides along,
  otherwise I only report it.
- ⚠️ **Live's `mu-plugins/lg-patreon-stripe-poller.php` loader is a REAL FILE
  (2,193 bytes), not a symlink** as it is on dev2. Its content matches the
  repo's and it only `require_once`s the folder's main file, so nothing in this
  lane depends on it — recorded because a change to that loader would not reach
  live by pull.
- **The dev2 serve runs `main`**, so nothing here is verifiable over HTTP until
  it merges. I will verify by driving the real classes against real fixtures
  and by rendering the real panel, and publish pictures behind the dev gate.

## 7. State measured on dev2 while planning

- `lgms_shared_secret` **is set, 64 characters, autoloaded.** `.env`'s
  `LGMS_SHARED_SECRET` is present and readable by the `looth-dev` pool. So dev2
  will render the healthy MATCH state; **every broken state is exercised
  against fixtures**, because a health panel is worthless on a healthy box.
- Live still has **no** `lgms_shared_secret` (MEMBERSHIP.md, #197's
  enumeration) — the exact state this section is being built to make legible.

## 8. Blocked on

1. Ian's approval of this plan (LANE-RULES: no code first).
2. Keeper's ruling on §2 — the Settings-tab field. **Nothing else in the build
   depends on it**; it changes one sentence and adds one gate section.
