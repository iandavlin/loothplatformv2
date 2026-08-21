# PLAN — #179 · the Loothprint bundle: one Edit door, one pill family, a paywall toggle

Lane `179-loothprint-bundle`. Branch at parity with origin/main (0 / 0). Nothing
written yet outside this plan file.

Ian's rescope of 8/21 10:56 is the scope — three ruled deliverables plus two
carried riders. Everything below was **measured on this box today**, not
remembered; where a measurement contradicts the issue text I say so rather than
quietly working around it.

---

## What I measured first (three of the issue's stated facts have moved)

**1. The "no standalone page since July 22" finding is STALE — and that is good
news for deliverable 1.**

    published loothprints ............ 170
    with a standalone blob ........... 169
    without ..........................   1   ← 73544, Ian's 8/16 "Test"

`lg-article-materializer.php` **is** installed in the docroot (symlink present),
`loothprint` **is** in `Plugin::MANAGED_CPTS`, and it re-bakes on
`wp_after_insert_post`, on a `_lg_layout_v2` / `_thumbnail_id` meta write, and on
a `tier` term change. Ian's other test post 73550 was published 8/19 and has a
blob; it was re-baked 8/21 06:42 when it was next edited.

So the Edit control I am asked to build **does** reach members' posts. The one
exception is real and worth one cheap check during the build: 73544 has no blob
and no `_lg_layout_v2`, and has never been re-saved since creation, so a
first-publish through the compose form may not be baking. One loopback POST to
`/archive-api/v0/_materialize` answers it. **If it turns out compose-created
posts do not bake on first publish, I will report it, not silently widen scope
into fixing it** — that is a separate issue and it is Ian's call.

**2. The compose form writes NO tier at all today.** There is no tier field in
its list and no tier write in its save path, so every loothprint composed
front-end lands with **no `tier` term**, i.e. **public / ungated**. Both of Ian's
test posts (73544, 73550) carry no tier term. The site norm is the opposite:

    tier term on loothprints:  looth-lite 161 · public 9 · (none) 4

So deliverable 3 is not a refinement of an existing behaviour, it is the first
time this form expresses a paywall intent at all — and today's silent default is
the *wrong* one by Ian's ruling.

**3. There is a second, dead "paywall" mechanism, and I am deliberately not
using it.** The ACF group **"Paywall - Loothprint"** (`group_68498d60b2b0d`)
holds one field that swaps the post between `loothprint` and
`freebie-loothprint`. `freebie-loothprint` **is not a registered post type** on
this box (2 orphan rows in `wp_posts`, nothing renders them, not in
`MANAGED_CPTS`, no nginx route). Ian's words name the other mechanism —
*"toggle the tier selector looth-lite or public"* — and that one is alive:
`Plugin::synth_gate_tier()` reads the `tier` taxonomy and is what puts
`gated_tier` on a synthesized loothprint's download block. **The tier taxonomy
is the write path.** Recorded here so the next reader does not have to
re-disprove the dead one.

**4. The middle-width dock, measured rather than described** (authored
loothprint, signed in, `.lg-standalone-dock` children):

    width   dock box        Hub    react   Save   Comments    Edit
    390     343 × 44 row    45×35  64×44   84×35  126×34      (bottom-right)
    900      64 × 166 col   45×35  64×44   45×35   47×34      bottom-right, 80×34
    1280     64 × 166 col   45×35  64×44   45×35   47×34      bottom-right, 80×34
    1600    421 × 44 row    83×35  105×44  84×35  126×34      bottom-right, 80×34

That is the defect in numbers: at 900–1280 the stack is **four ragged circles of
four different widths** (45 / 64 / 45 / 47), and the react wrapper is a
square-cornered `div` 20px wider than its neighbours — while at 1600 they are one
family of labelled pills. And the Edit control is not in the stack at all; it is
a dark pill in the **opposite corner**.

---

## Deliverable 1 — ONE Edit button, in the floating stack, straight to the form

`archive-poc/standalone/render.php`.

- Delete the `.lg-standalone-editwrap` two-line menu (markup, CSS and its ~30
  lines of open/close JS) and the bottom-right fixed positioning with it.
- Add **one `.lg-dock__btn lg-dock__edit` pill inside `.lg-standalone-dock`**,
  last in the row, wearing the same pill as Hub / Save / Comments so the dock
  stays one shape.
- Its href is `$composeUrl` when there is one (`loothprint` + compose flag ON),
  otherwise `$editUrl` (`?lg_edit=1`). That fallback is not a hedge: it is what
  keeps the control honest for the other managed types (video, imgcap…), which
  have no compose form, and it preserves the flag-agreement property gate 69
  already asserts.
- Entitlement is unchanged — `edit_archive_poc` **or** the post's author, hidden
  in preview mode. Not one line of the permission check moves.
- **Return-after-save already works** and I am not touching it:
  `lg_fc_route()`'s `'return'` for an edit is already
  `get_permalink($edit) . '?lg_fc=saved'`. Ian's *"when the post is edited and
  resubmitted it should kick back to the post"* is satisfied today; I will
  verify it end-to-end rather than rebuild it.

### ✅ RULED BY IAN, 8/21 — the admin keeps a one-click page editor

Collapsing to one door would have removed the only click-path to the v2
page-text editor on a standalone page. The header's "Edit" is **not** it —
measured: that button goes to `/wp-admin/`, not to `?lg_edit=1`.

**Ian ruled the admin-only second pill.** So:

- **Every entitled viewer** (author or `edit_archive_poc`) gets **one Edit pill**
  → the prefilled form. That is the member's whole experience, exactly as ruled:
  one door.
- **`edit_archive_poc` holders only** — Ian and editors, never a plain member,
  never a member editing their own post — additionally get a small **"Page"**
  pill → `?lg_edit=1`.

The gate asserts both halves: a member-who-owns-the-post sees exactly ONE edit
control, and a cap-holder sees two. "Members get one door" becomes a *testable*
claim rather than a description.

## Deliverable 2 — the pill family at every width

Same file, the `@media (min-width: 641px) and (max-width: 1500px)` block.

The compact vertical stack **stays** — GH #53 / HK-027 is law and the dock must
not grow back over the article column. The constraint I will hold myself to is
the measured one: **the stack's box stays ≤ 64px wide at 641–1500px** (it is 64
today), so nothing that is currently clear of the text can become unclear.

Inside that budget, make the four (five) controls one family:

- a shared `--lg-dock-mini` sizing rule so every child is the **same** footprint
  (equal width, equal height, `border-radius: 999px`, same padding), instead of
  45 / 64 / 45 / 47;
- give `.lg-dock__react` the same footprint as its siblings — today it is the
  odd one out because the pill is the inner button and the wrapper is a bare
  square-cornered div;
- keep the react **count** visible (the existing rule hides `__lbl`, not the
  count) — a pill that drops the number loses information, which is a different
  defect from the one I am fixing.

Light **and** dark, at 641 / 900 / 1280 / 1500 / 1501, both sides of each
breakpoint (the "presence is not reachability" rule: bracket the boundary).

---

## Deliverable 3 — the paywall toggle on the Loothprint form, create AND edit

`platform/mu-plugins/lg-frontend-compose.php`, behind a new flag.

**The control.** Rendered by us, not by ACF, in `lg_fc_own_controls()` — the
exact shape the comments Yes/No control already uses (that is the "do not invent
a second write path" instruction taken literally: the form already has a pattern
for a non-ACF control that maps to an explicit server-side write, and this is
that pattern again). Two chips, plain words, and **"behind the paywall" is
checked by default**.

**The write.** A strict two-value read of `$_POST['lg_fc_paywall']` — same shape
as `lg_fc_comment_status()`, so there is no injection surface and it is only ever
consumed after ACF has verified the form nonce — applied on `acf/save_post` at
priority **26**, i.e. *after* `lg_fc_promote_draft` (25) so the post has its
final status. The write itself is `wp_set_object_terms($id, [<term_id>], 'tier',
false)`, which is what `loothdev-sheets-bridge.php` already does and what the
materializer already listens for.

**The four-case rule, and why it is four and not two.** A naive
`behind → looth-lite / not behind → public` **silently downgrades a `looth-pro`
post to `looth-lite`** the first time its author saves it, because the toggle has
no way to say "pro". So:

    behind   + already non-public (lite OR pro)  →  no write   (preserve)
    behind   + public or no term                 →  set looth-lite
    not behind + non-public                      →  set public
    not behind + already public                  →  no write

No loothprint on this box is `looth-pro` today (161 lite / 9 public / 4 none), so
this costs nothing now and cannot bite later.

**Prefill on edit** comes from the post's own `tier` terms — any non-public term
reads as "behind".

**The flag.** New tracked config `platform/config/loothprint-paywall.php`,
`'enabled' => false`, read by a `lg_fc_paywall_enabled()` copied line-for-line
from `lg_fc_enabled()` (tracked file → `.local.php` box override → `getenv()` +
`$_SERVER` preview pair; a `.local.php` and never an FPM `env[]`, because dev2's
pool files are symlinks into the serving checkout). **Flag OFF renders no
control and performs no write** — the form's markup and the post's terms are both
byte-identical to today. Row added to `docs/FLAGS.md` **in the merge commit**.

Not a separate key inside `frontend-compose.php`: compose is already ON on dev2
and OFF on live, and folding the toggle in would either launch it with compose on
the next live flip or force the two decisions to move together.

---

## Riders

- **Gate-47 dark ink — IN, it is three lines.** `lg-frontend-compose.php:1617-18`
  (`.lgfc li:has(input:checked)>label`) and `:1791-92` (`.lgfc__chip:has(input:checked)`)
  and `:1801` (`.lgfc__submit`) each pair `background: var(--lg-sage-d)` with a
  hardcoded `color:#fff`. `--lg-sage-d` flips **lighter** in dark, so white ink
  on it measures ~1.85:1. The fix is already written two rules away — lines
  1686-89 darken `--lg-sage-d` to `#3d5233` under `html[data-lguser-theme="dark"]`
  for the taxonomy chips. Same treatment, same value, for the three that were
  missed. Gate 47 is `tools/frontend-compose/dark-contrast-sweep.py`; I run it
  individually at 1280 and 390, before and after.
- **looth_id ticket heal — IN, and RULED to have no bounce and no flag.** See below.
- **Noticed, NOT fixed, flagged here:** `lg_fc_own_controls()` hardcodes
  `checked` on the comments "Yes" chip, so **editing** a post whose author closed
  comments silently re-opens them on save. It is adjacent (I am adding a second
  control to the same function and giving that function the edit id it needs to
  prefill), so it is nearly free to fix in passing — but it is not in Ian's
  scope, so it is his call, and I will not fix it unless told to.

### ✅ RULED BY IAN, 8/21 — heal the ticket WITHOUT a bounce, and without a flag

Ian's question when shown the bounce: *"Can we just simplify the tickets and
avoid the bounce?"* — and the answer is yes, because the mechanism is already in
the file.

`render.php` **already** makes one loopback call per render carrying the
visitor's own cookies — `lg_archive_poc_whoami()` (`archive-poc/config.php:136`)
curls `https://127.0.0.1/profile-api/v0/whoami` with
`Cookie: $_SERVER['HTTP_COOKIE']`, 5-second timeout, returning `null` on any
failure. So the heal is a second call of the same shape, not a new mechanism:

    whoami says NOT authenticated
      AND a wordpress_logged_in_* cookie is present   ← the exact broken state
        → loopback GET /looth-auth/issue?return=/ with the visitor's cookies,
          WITHOUT following the redirect
        → read `looth_id` off the response's Set-Cookie
        → re-emit it on OUR response (so the browser keeps it)
        → re-run whoami ONCE with the new token, and render as that member

**Why this is the safer shape, not merely the nicer one.** There is no redirect,
so **no loop is structurally possible** — and the loop was the entire reason a
flag was proposed. Every failure mode degrades to exactly today's behaviour:
mint fails, times out, returns no cookie, or the second whoami still says
anonymous ⇒ we render signed-out, which is what happens now.

It is also not slower overall. The WordPress boot inside `/looth-auth/issue` is
paid either way; the bounce simply made the *browser* wait for it and then
issue a second request. This pays it once, inside one render.

**No flag**, on Ian's ruling. The house rule wants a flag where a member-facing
change could do harm on arrival; this one cannot, because its whole failure
surface is "behaves as it does today". What it needs instead is a **hard
timeout** (short, so a stalled WP boot can never hang an article page) and a
**once-per-request static guard** so nothing can re-enter it. Both are asserted.

Anonymous viewers are untouched in every state: no `wordpress_logged_in_*`
cookie ⇒ the branch is never entered, and that is asserted too (it is the
majority of this page's traffic).

**FILED AS #182, NOT DONE HERE.** The real simplification Ian is pointing at is
**one ticket instead of two** — teaching the identity desk to accept a WordPress
session directly, which `archive-poc/config.php:126-134` already anticipates
("when profile-app ships the trusted-header bridge…"). Ian, 8/21: *"Yes but make
issue and save for later, unless mission critical for membership."*

**It is not mission critical, and the issue says why.** The membership-critical
version would be a paying member shown gated content as locked because the wrong
ticket expired — real, because `lg_archive_poc_viewer_tier()` fails closed to
`public` when whoami says anonymous. But this lane's heal closes it on the
surface where it bites, and profile-app already heals it its own way. After
#179 the split is a wart with two patches holding it shut, not an open hole
under a paying member. #182 carries the scope, the blast radius (profiles, hub,
messages, every `/archive-api/v0/*` surface) and the two recorded traps.

## Gates — extended, not minted

I am **not minting a gate number**. Two lanes have collided on a self-minted
number before, and #172's precedent is the right one: extend the gate that
already owns the surface.

- **Gate 69** (`loothprint-edit-door-gate.py`) is rewritten in place for the
  ruled shape: *one* Edit control, *in the dock*, carrying a real post id,
  agreeing with the compose flag, in both themes, and invisible to a stranger —
  plus the deliverable-2 assertions, which belong here because they are about the
  same control: **every dock child the same footprint at 641–1500, and the dock
  box no wider than it is today.** Its signed-in liveness leg and its
  CANNOT-RUN-is-2 discipline are kept exactly.
- **Gate 35** (`compose-gate.py`) gains a paywall section asserting **all three
  flag states** (file absent / OFF / ON), the default, the prefill, and the
  four-case write rule. The write is exercised against a **per-run, PID-keyed
  throwaway post** and read back from the term store, never from the HTML — a
  refused or no-op write must not be able to read as success.
- **Red-first, both of them**, against the current serve, before any code moves.
  A red-first that stays green is itself the finding.
- Run **individually**, never through `run-all.sh` — #175's first-red early exit
  strands 47 and 69 — and any red attributed against `~/loothplatformv2-clean`
  the way #162 did it.

## How this gets verified before merge (not after)

`render.php` is served from `/srv/archive-poc` → `~/loothplatformv2-clean`, so
**nothing I write in this worktree is visible on the dev2 serve until it is
merged** — the recorded trap where a lane "verifies on dev2" and is really
testing main. So this lane stands up a preview: one snippet
`platform/nginx/lane-preview-179-loothprint-bundle.conf` under
`/preview/179/…`, pointed at **this worktree's** `render.php` on the existing
`archive-poc` pool, via `tools/preview/lane-preview.sh up`. Already proved
feasible: the `archive-poc` pool user can read this worktree
(`sudo -u archive-poc test -r …` passes, the path is world-`x` the whole way
down). Ian gets a URL he can click on a branch, which is the only way the
"merges need Ian-verified" rule is satisfiable at all.

## Files I expect to touch

Guessing wide on purpose, so overlap with the two other live lanes
(`178-confirm-button`, `180-tester-token-url`) is caught now:

    archive-poc/standalone/render.php                    D1, D2  (+ D-rider auto-heal)
    archive-poc/config.php                               the no-bounce ticket heal (RULED)
    platform/mu-plugins/lg-frontend-compose.php          D3 + gate-47 dark ink
    platform/config/loothprint-paywall.php               NEW — the flag
    platform/nginx/lane-preview-179-loothprint-bundle.conf  NEW — the preview
    tools/gates/loothprint-edit-door-gate.py             gate 69, rewritten
    tools/gates/compose-gate.py                          gate 35, extended
    tools/gates/run-all.sh                               the two gates' comment blocks ⚠️ shared file
    docs/FLAGS.md                                        the flag row(s) ⚠️ shared file
    docs/domains/PAGE.md                                 see below
    handoffs/plans/179-loothprint-bundle-PLAN.md         this file

⚠️ `run-all.sh` and `docs/FLAGS.md` are the two everybody edits. I keep both
edits to appended/self-contained blocks, and I do not rebase.

⚠️ **#179 wears the `page` label but is not a lanes-page issue** — exactly the
shape of #171. The domain rule says a domain-labelled issue updates its domain
file in the same commit, so I will record that fact and a one-line pointer in
`PAGE.md` (as #171 was recorded) and flag the label to Ian for a ruling, rather
than silently relabelling it. `180-tester-token-url` is likely in that same file,
so my edit there is one appended paragraph.

## What I will NOT do without being told

- Fix the first-publish materialize gap (report only).
- Fix the comments-reopened-on-edit prefill bug (report only, unless told).
- Touch the dead `freebie-loothprint` ACF group or its 2 orphan rows.
- Touch `~/loothplatformv2-clean`, rebase, force-push, or merge my own lane.

---

# WHAT WAS ACTUALLY BUILT — closing handoff, 2026-08-21

All three ruled deliverables plus both riders. Branch `179-loothprint-bundle`,
10 commits, 13 files. Not merged; the worktree and the preview are left standing.

> ⚠️ **SUPERSEDED, 2026-08-21 evening — read this before the table below.** Ian
> reversed the "members one, admins two" answer after looking at the running
> page: *"The text is handled on the form. I don't know how we got to the modal
> thing."* There is now **ONE Edit control for everyone, cap-holders included**,
> and the page-text pill is deleted — markup, CSS, parameter and entitlement
> branch. He took that shape from a description and rejected it from the thing;
> the description was mine, so this is a plan error, not a change of mind.
>
> Gate 69 still runs three viewers on purpose. The cap-holder is the only viewer
> for whom a second door could have survived, so dropping that leg is exactly
> what would make *"not even for admins"* untestable. The assertion is inverted,
> not removed.
>
> Also landed with it: the rich-text editor no longer arrives un-initialised.
> Ian's screenshot showed *"Click to initialize TinyMCE"* above his write-up as
> literal `<p>test</p>` — ACF's `delay` placeholder. **That is the same element as
> gate 47's open red** (`div.acf-editor-toolbar`, a 712×40 `#f5f5f5` slab): one
> object, two symptoms. `delay = 0` removes it entirely.

## Deviations from the plan above, each traceable

| Plan said | What happened | Why |
|---|---|---|
| extend `tools/gates/compose-gate.py` | new sibling `tools/gates/loothprint-paywall-gate.py`, run under **35b** | surgery on a 489-line gate for a self-contained subject; no number minted, same as extending |
| — | **`platform/mu-plugins/lg-article-materializer.php`** | keeper ruled the re-bake blocker IN: without it the toggle writes the term and the page renders the old gating |
| — | `tools/gates/materializer-queue-harness.php` | the proof for that, stubbed because the plugin is symlinked out of the serving checkout |
| `platform/config/looth-id-autoheal.php` | not created | Ian ruled the heal has no bounce, therefore no loop, therefore no flag |

## Verified, and how

- **Gate 69** (rewritten): red-first **36 of 49 on the serve**, then **49/49 green
  against the branch preview**. Three viewers, because "members one, admins two"
  is unfalsifiable against one account.
- **Gate 35b** (new leg): green, 5 checks — the rule truth table, and a paywall
  choice reaching the **served bytes** (`chip--tier--public` → `chip--tier--looth-lite`)
  on a PID-keyed throwaway that was deleted with its blob, 0 rows left.
- **Re-bake harness**: 5/5 — one bake per save, first publish baked as
  *published*, delete survives its own row vanishing.
- **Flag-register gate**: green.
- **The ticket heal**, same request, WP session with no `looth_id`:
  main renders **3 sign-in links and no member name** (a stranger); the branch
  renders the member, with the Edit pill, in **one page load**, 0.525s. Anon is
  untouched — no cookies set, 0.090s.
- **Gate 47**: run individually. Main is RED for `div.acf-editor-toolbar`
  (712×40 of `#f5f5f5` in dark) — fixed here, **not verifiable from a branch**
  (mu-plugin symlinked out of the serving checkout); re-run after the pull.

## Noticed and NOT fixed

1. **641–700px: the dock already covers ~40px of body text on main.** GH #53 /
   HK-027 is not actually fixed at the bottom of its own media query. Full table
   in `docs/domains/PAGE.md`. Keeper's posture: report, don't fix.
2. **First-publish materialize gap / post 73544.** Flagged per keeper, not
   chased. The harness shows the re-bake fix plausibly closes it.
3. **The dark sweep is blind to `<input>` value text.** It measures text nodes,
   so the form's primary control ("Post it") was never covered. It happens to be
   fine — measured 12.23:1 — but the next input-valued control would not be
   checked either. Gate's shape, not this file's.
4. **The standalone article page's own body background stays light in dark
   mode** (`#f0eee8`, no dark override) while its chrome and the new dock pills
   go dark. Seen in the dark screenshot; not measured further, not in scope.
5. **`b23f436`'s commit body lost one word** to shell backtick substitution.
   Not amended — it is pushed, and force-pushing is forbidden.

## Left standing deliberately

- **The lane preview is UP.** `tools/preview/lane-preview.sh down 179-loothprint-bundle`
  removes it. Ian needs it to look at the branch.
- **`platform/config/frontend-compose.local.php` exists in this worktree**
  (gitignored). Without it the previewed code reads the tracked default and the
  compose flag disagrees with the box — which is what made the gate's first run
  red on healthy code.
- Screenshots: `/home/ubuntu/projects/footer-mockups/179-dock/` (before/after at
  1280 light, after dark, after 390, after admin).
