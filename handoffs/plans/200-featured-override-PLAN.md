# #200 — Ian's picks stay on the front page — PLAN

Lane `200-featured-override`. Plan-first per LANE-RULES. No code touched yet.

---

## 0. WHAT I MEASURED FIRST — the issue's stated cause is only half the story

The issue says the band is empty because *"no member passes the eligibility
criteria"*, and that a live `.local.php` turning the flag off *"restores the old
hand-placed band"*. **Both need correcting, and the second one is urgent** —
it is a stopgap that cannot work, so nobody should be waiting on it.

### A. The live band is empty for TWO independent reasons, either one sufficient

**Cause A — a database grant that was never applied to live.** Measured on live:

| check | live |
|---|---|
| `has_column_privilege('archive-poc','users','uuid','SELECT')` | **t** |
| `has_column_privilege('archive-poc','users','featured_opt_in','SELECT')` | **t** |
| `has_column_privilege('archive-poc','users','featured_opt_in_at','SELECT')` | **f** |
| `has_table_privilege('archive-poc','profile_sections','SELECT')` | **f** |

`lg_resolve_featured_member()` selects `featured_opt_in_at`, so Postgres raises
`permission denied for table users`, the call site's `try/catch` swallows it, and
the band is absent **for every visitor and for every pick, however perfect**.
This is precisely the failure `tools/cut/featured-member-grants.sql` and
`docs/FLAGS.md` both warn about in capitals; it just never got applied.

**Ian's command (a live write, so it is his):**

    sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql

**Cause B — the grant alone does NOT bring the band back.** Live's current pick
is *Jonathan Scott Chisels & Picks*. His header block is members-only, his
`at_a_glance` is empty, and his `business_name` ("Chisels & Picks") is a tail of
his display name so the fallback is deliberately skipped → `role` resolves to
`''` → the resolver's card-ready guard returns null → no band.

Measured across live's whole opted-in pool — **6 opted-in and public, only 2 of
them render**:

| member | header | glance | business_name | renders? |
|---|---|---|---|---|
| John Greenwald Epiphany Guitars, LLC | members | yes | tail of name | **no** |
| Jonathan Scott Chisels & Picks ← on now | members | no | tail of name | **no** |
| Bryan Parris | members | yes | Parris Guitars | yes |
| BodyShopGuitar | members | yes | (empty) | **no** |
| ERIC OTTAVIANO MINOR FRET SJ | public | no | tail of name | **no** |
| Ian Davlin | public | yes | The Looth Group | yes |

**That is the regression Ian is describing, and it is what this lane fixes.**

### B. The stopgap cannot work — two reasons, both structural

1. **Nothing reads `featured-members.local.php`.** All three readers of this flag
   — `archive-poc/web/index.php:993`, `profile-app/web/u.php:175`,
   `profile-app/api/v0/me-featured.php:35` — include **only** the tracked file
   plus `getenv`/`$_SERVER`. There is no `.local.php` merge for this flag
   anywhere. Placing one on live is inert.
   ⚠️ The same is already true on dev2 for **`featured-consent.local.php`**,
   placed 2026-08-20 and read by nothing: **dev2 has been believed to be running
   consent-ON since then and is actually OFF.**
2. **Even if it were read, flag OFF gives NO band.** Live's `config.json` carries
   a `member_uuid`, and `index.php:1027` nulls the whole band when the flag is
   off and a uuid is present. Measured by CLI render on dev2 against the same
   config shape: **flag ON → 1 band, flag OFF → 0 bands.** "OFF is byte-identical"
   was only ever proven against a config *without* a uuid; gate 39 §C greps
   source and never rendered that pairing.

**This is why deliverable 2 is bigger than a one-line default flip:** flipping the
tracked default to `false` with no `.local.php` reader would take the band off
**dev2** as well. The reader has to be built in the same change.

### C. The fix's premise, measured before building it

The pinned resolver's query is the shipped one minus `featured_opt_in = true`.
Run read-only against dev2 for Ian's own pick:

    id 1390 · jonathan-scott-chisels-picks · has_avatar t · glance (none)
    header members · business_name "Chisels & Picks" · featured_opt_in **f**

So **the pinned card draws** — photo, name, profile link, no role line — which
is the picture in the mock. Two things fall out of that row:

- **dev2 already holds the perfect fixture and I do not need to invent one.**
  On dev2 this member has `featured_opt_in = false`; on **live** the same
  member is `true`. The boxes disagree, so gate 94 must **read** the opt-in
  state rather than assume it — asserting "not opted in" as a constant would
  pass on dev2 and be wrong about live.
- **1,921 members on dev2 are pinnable and have a photo.** The picker is a
  search box over a real population, which is why the plan does not offer a
  dropdown.

### D. Also measured, for scale

- dev2: 1,934 public members, **8 opted in**. Live: 1,888 + **6 opted in**.
  A pin picker over ~1,900 members is a **search box**, not a dropdown.
- The active `config.json` is **`/home/ubuntu/projects/archive-poc/config.json`**
  on *both* boxes (`$ap_def_app_root`, the non-`live` branch, because live's
  `LG_ENV` says `dev2`). `/srv/archive-poc/config.json` exists on both boxes,
  is a symlink into the serving checkout, and is a **decoy** — it still holds a
  stale hand-typed Chip Tait card that nothing serves.

---

## 1. Deliverable 1 — admin-pinned picks that bypass the criteria

### The shape
A pick carries a new boolean `pinned` in `config.json` beside `member_uuid`.
Pinned means *Ian placed this member by hand*; unpinned means *they ticked the
box and were chosen from the self-serve pool*. The two live side by side; the
pool is untouched.

### What `pinned` bypasses, and what it deliberately does not

| criterion | consented pick | **pinned pick** |
|---|---|---|
| `featured_opt_in = true` (the tick) | required | **bypassed** |
| `profile_visibility = 'public'` | required | **still required** — see below |
| avatar present | required (else no band) | **bypassed** — line omitted |
| resolved `role` non-empty | required (else no band) | **bypassed** — line omitted |
| members-only `at_a_glance` republished | only under consent-A rules | **never** |
| location / About visibility rules | member's own dials | **unchanged** |

**Reconciling standing ruling 1 (consent-A).** A pinned member has given no tick,
so there is no consent to lean on. The card therefore prints **only what their
profile already publishes**: a members-only one-liner is *not* republished for a
pinned pick, under any flag. What Ian gets is the member's photo, name, public
role if they have one, and a link to their profile — the pick appears, and the
consent framework is not quietly widened. The dash says this in words at the
point of the click: *"Pinned by you — they have not ticked the box. Ask them
first."*

**Reconciling standing ruling 2 (real members only).** Every pinnable row comes
from `users` in `profile_app`. There is no hand-typed string path; the card is
still resolved live on every request.

**The one criterion kept — RULED by keeper 2026-08-22, my recommendation
upheld:** *"a pinned pick does NOT bypass `profile_visibility` private. That is
the member's own switch and it outranks admin pinning under the consent-A
principle; the dash listing them as cannot-be-pinned is the legible refusal."*
Ian can overrule with a word and has been told so. The reasoning, kept because
it is the reasoning that will be re-litigated: `profile_visibility = 'private'` is not a completeness bar, it is the
member's own switch saying "I am not public". Pinning such a member would put
their name and photo on the open web and link to a profile the public cannot
open. On both boxes this is **1 member**, so keeping it costs nothing today.
The dash will list Private members in the search marked *"cannot be pinned —
their profile is Private"* rather than hiding them, so the refusal is legible.
Interpreting "regardless of the criteria" as "including the one criterion the
member set themselves" would have been the silent reading; this is the loud one.

### The pieces
1. **`internal-featured-pool.php`** grows `GET ?q=<text>` returning a second list,
   `candidates`: real members matched on display name / slug / business name,
   capped at 25, each with `uuid, slug, display_name, avatar_url, tagline,
   location, eligible, opted_in` and the same `card_*` prediction fields.
   The existing `pool` payload is unchanged, so an older dash keeps working.
2. **`FeaturedMemberDash`** gains a **"Pin a member"** search box above the pool
   table, its results table, and a `PIN_ACTION` handler. The current-pick banner
   and the history table both name which kind a pick is
   (*"Pinned by you"* vs *"Opted in"*).
3. **`_config.php`** accepts `pinned` (it already merges arbitrary scalars into
   `featured_member`; this is a whitelist + doc change, not new machinery), and
   the history row records it so a past stint can be read honestly.
   ⚠️ **`pinned` must be written on EVERY save, `false` included.** The webhook
   merges with `$clean + $existing['featured_member']`, and PHP's `+` keeps the
   left operand's keys and fills the rest from the right — so a key omitted
   from a POST **persists**. Omitting `pinned` on an ordinary Feature click
   would let a stale `true` from a previous pin carry over and silently
   reclassify a consented pick as one of Ian's. This is the same discipline
   `consent_ack` already documents for exactly the same reason, and it is why
   that comment says "explicitly false included".
4. **The pinned rule goes INSIDE the existing pure function, not beside it.**
   Gate 39 §G3 lifts `lg_fm_card_role()` out of `index.php` **by name** with
   `_extract_php_fn()` and executes it over a truth table — "a rule read out of
   a file is not a rule that ran". So `$pinned` becomes a parameter of *that*
   function (defaulting `false`, which keeps §G3's existing table valid
   unchanged) rather than a second function that would have to be kept in step
   with it by hand. Gate 94 then adds the pinned rows to the same lifted
   function. **One rule, one place, two gates executing it.** The function stays
   free of every DB call, config read and global, because that is what makes it
   liftable at all.
5. **`lg_resolve_featured_member()`** takes a `$pinned` argument: drops
   `featured_opt_in = true` from the WHERE, keeps `profile_visibility='public'`,
   forces the consent republication rule to *off* for the glance, and skips the
   avatar/role card-ready guard.
6. **The card template** guards `.lg-fm__avi` and `.lg-fm__role` with `!empty()`,
   the way `where`/`bio` already are. Byte-identical for every card that has
   both — which is every card rendering today.

---

## 2. Deliverable 2 — tracked default FALSE, and a `.local.php` reader that exists

**Keeper, 2026-08-22, ack on the finding above:** *"whatever flag mechanism you
land, give it the standard `.local.php` merge like every other flag in the
register, so the next keeper stopgap is not a lie."* That is now an explicit
charter item, and item 1 below is it. The stopgap has been withdrawn with Ian.

1. **New shared reader.** One tiny helper, `lg_featured_flag()`, resolving in the
   documented order: tracked `featured-members.php` → gitignored
   `featured-members.local.php` (per key) → `getenv` → `$_SERVER`. Because the
   three call sites live in **three different apps** with no shared autoload, the
   honest answer to the duplication is the #191 one: **copy it and gate the
   agreement** (gate 94 §D asserts all three resolve identically for the same
   inputs) rather than couple three apps together.
   The same reader is added for **`featured-consent.local.php`**, which is the
   already-placed-and-inert file above.
2. **`platform/config/featured-members.php`: `enabled => false`**, with the
   reason and the date in the docblock.
3. **`docs/FLAGS.md`**: the featured-members row updated — repo default **false**,
   dev2 **ON via `.local.php`**, live **OFF**, plus the two-cause live story and
   the "nothing read the .local.php" trap. Same commit, per the maintenance rule.
4. **dev2 keeps ON.** `platform/config/featured-members.local.php` must be placed
   in the **serving checkout** in the same window keeper pulls, or dev2 goes dark.
   ⚠️ It also **dirties the serving checkout** unless it is gitignored — I will
   confirm `*.local.php` is in `.gitignore` before handing keeper the file, and
   `php -l` it before it is placed (the `front-signup-banner-retire.php` warning).
   Coordinated over the board; the merge does not fight Ian's live posture,
   because after this merge live's tracked default is OFF, which is where live
   wants to be until he says otherwise.

---

## 3. Deliverable 3 — the empty-pool law: the band never renders as absent

**Today there are three ways to get no band**, and only one of them is anybody's
intent:

| state | today | after |
|---|---|---|
| flag ON, pick resolves to null | **absent** | **fallback band** |
| flag OFF with a uuid in config | **absent** | **fallback band** |
| `enabled: false` (dash "Remove") | absent | absent — but see below |

- **`featured_member_fallback`** becomes a new top-level key in `defaults.php`.
  Top-level on purpose: `config.json`'s overlay replaces `featured_member`
  *wholesale*, and the webhook's `$allowed_keys` will not carry the fallback, so
  no dash write and no stale config can ever clobber it. This is what stops the
  live failure mode where the leftover hand-typed fields had drifted into a
  Frankenstein card (name *Jonathan Scott*, bio about *Beau Hannam*).
- ⚠️ **The law also closes the failure mode that caused this issue.** A pinned
  pick still reads `featured_opt_in_at`, so on a box whose grant is missing the
  resolver still throws — but the `catch` now lands on the fallback instead of
  on nothing. **After this lane, a missing grant degrades to a visible fallback
  band rather than a hole in the page.** Still wrong, but no longer invisible;
  gate 39 §G2 is what says *why*. I am deliberately **not** narrowing the
  pinned SELECT to dodge the ungranted column — that would hide a real
  misconfiguration and would not help the consented path anyway.
- **"Remove from front page" stops meaning "hide the band".** It clears the pick
  and leaves `enabled: true`, so the band reverts to the fallback. A separate,
  explicitly-labelled **"Hide the band entirely"** control keeps the real
  off-switch available and honest.
- **Two fallback designs, drawn for Ian, not chosen by me.** He decides from
  pictures, so after plan approval I will publish both behind the dev gate,
  both themes, both widths:
  - **A — the hand-placed card** that shipped from June (Dan Erlewine).
  - **B — a designed empty state** *(my recommendation)*: "Nobody is featured
    this week — want to be? Tick the box on your profile", which is honest about
    the state and recruits into the self-serve pool instead of showing a stale
    card dated by nothing.

---

## 4. Gates

**Gate number minted from MAIN: 94.** `run-all.sh` on main topped out at 93 and
carried **two** blocks numbered 93 — reported to keeper, who has since
renumbered `switch-menu` to 95 on main and given the ledger header an explicit
next-free counter (96) bumped at assignment, so numbers no longer race merges.
**94 stands.** Every live worktree was also diffed against main for
`run-all.sh`: only `emoji-picker-build` touches it, and it is an old branch
still on the "/19" numbering.

`tools/gates/featured-override-gate.py`, **red-first**, run individually (#175):

- **§A empty pool ⇒ present band.** With zero picks, in each of the three flag
  states (absent / OFF / ON), the served front page contains a featured band.
  Paired with a liveness assertion so a 403 or a dead route cannot pass it
  vacuously.
- **§B a pinned pick renders** when `featured_opt_in` is false *and* the role
  resolves empty — the exact shape of live's Jonathan Scott.
- **§C a pinned pick does NOT republish a members-only one-liner**, under either
  state of the consent flag. This is the consent-A fence.
- **§D the three flag readers agree** — same tracked file, same `.local.php`,
  same env, same answer, executed rather than grepped.
- **§E the tracked default is false**, and the OFF state is a real no-op *for
  everything except the band*, which now shows the fallback. Asserted **per
  state**, reading the flag rather than hardcoding it, so a later flip needs no
  gate edit.
- **§F the dash names pinned vs consented** and refuses a Private pin.

### Gate 39 impact — read before building, so no RED is a surprise at merge

I read every section of `featured-member-gate.py` against the planned change.
**Exactly two sections move, both for a real reason, and both get restated
rather than suppressed:**

- **§C1 does not move.** It is already per-state and reads the flag: setting
  `enabled => false` lands on its `"[C1] the tracked flag defaults to false"`
  branch. The default flip needs **no** gate edit — which is exactly what
  `feedback-gate-reads-the-flag-not-a-hardcoded-state` asks of a gate.
- **§C3 moves.** It matches the literal structure
  `if ($lg_fm_on) { … lg_resolve_featured_member(…) … } else { $lg_fm = null;`.
  The empty-pool law replaces that `null` with the fallback, so the regex
  fails. Its *property* — no real member resolves while the flag is off — is
  untouched, so §C3 gets restated to assert the property (the resolve call is
  unreachable when off, and the off branch reads no member field from the
  config) instead of the one spelling of it.
- **§F3 moves.** It pins the resolver's card guard to the literal
  `if (trim((string) $u['avatar_url']) === '' …) return null;` and requires
  `$role === ''` inside it, because the pool endpoint reproduces that rule and
  must not drift. Adding the pinned bypass changes the guard's shape, so §F3
  is restated to assert **both** arms: the consented path still refuses on
  avatar-or-role, and the predictor still mirrors *that* arm.
- **§C4 and §G4 constrain HOW I write the reader.** They extract the literal
  `@include __DIR__ . '…featured-members.php'` expression from each caller and
  ask PHP to resolve it for real. The new `.local.php` merge therefore keeps
  that exact literal in place and adds the local read beside it — a refactor
  that hid the include behind a variable would red both, correctly.
- **§D is a rule the pin must not break, and does not.** An admin may never
  tick a member's consent, not even `?as=` them. Pinning writes only to
  `config.json`; it never touches `users.featured_opt_in`. **Gate 94 asserts
  that directly**, so the two halves of "admin can place, admin cannot consent"
  are each fenced.

**The restatement, stated plainly.** "OFF is byte-identical" is no longer the whole truth
once OFF renders a fallback band. It becomes the property that actually
matters — **OFF publishes no real member** — said so in the gate's own comment,
rather than an assertion quietly deleted because it went red.

`docs/CRAFT-STANDARD.md` gets gate 94's row (its table is already missing rows
19 and 22 — noted, not fixed here).

---

## 5. Files I expect to touch — guessed wide

    platform/config/featured-members.php          default -> false, .local.php doc
    platform/config/featured-consent.php          .local.php doc
    archive-poc/web/index.php                     flag reader, pinned resolve, fallback, template guards
    archive-poc/web/defaults.php                  featured_member_fallback
    archive-poc/web/archive.css                   fallback card styles
    archive-poc/api/v0/_config.php                accept `pinned`
    archive-poc/api/v0/_featured-history.php      record `pinned`
    archive-poc/api/v0/fp-save.php                doc only — it forwards a raw featured_member
                                                  object and, because omitted keys persist,
                                                  carries `pinned` through unchanged. Safe
                                                  direction, but it must be SAID.
    profile-app/api/v0/internal-featured-pool.php ?q= candidates
    profile-app/api/v0/me-featured.php            flag reader
    profile-app/web/u.php                         flag reader
    lg-layout-v2/src/FeaturedMemberDash.php       pin UI, honest naming, Remove semantics
    tools/gates/featured-override-gate.py         NEW, gate 94
    tools/gates/featured-member-gate.py           gate 39 §C restated
    tools/gates/run-all.sh                        register 94
    docs/FLAGS.md                                 featured-members row
    docs/CRAFT-STANDARD.md                        gate 94 row
    docs/domains/PROFILE.md                       the real knowledge
    docs/domains/PAGE.md                          the `page`-label footnote (8th in 8 days)
    handoffs/plans/200-featured-override-PLAN.md  this file
    footer-mockups/200-featured-override/         the pictures (added after the list was
                                                  first written -- flagged rather than
                                                  slipped in, per LANE-RULES)
    tools/preview/200-featured-shots.py           the shot run for those pictures (same)

**No overlap with any live lane**: no other worktree touches any of these files
(checked by diffing all nine worktrees against main).

**Not touched, on purpose:** live, and `tools/cut/featured-member-grants.sql`
(the file is correct; it was never *applied*, which is Ian's command above).

---

## 6. What I will report but not fix

- ~~The two `GATE 93` blocks already on main~~ — reported, and keeper fixed it
  on main the same hour (`switch-menu` → 95).
- `featured-consent.local.php` on dev2 has been inert since 8/20 — the reader
  this lane adds is what makes it real, which means **placing it becomes a
  behaviour change on dev2** and needs Ian's eyes before it counts as "on".
- `/srv/archive-poc/config.json` is a live decoy on both boxes.


---

## 7. The pictures — published for Ian, 2026-08-22

    https://dev2.loothgroup.com/footer-mockups/200-featured-override/

Behind the dev gate. Carries the measured rejection table, what his own pinned
pick looks like, and the two empty-state options drawn side by side in **both
themes**, shot at **1440 and 390** (`tools/preview/200-featured-shots.py`).

The mock pins the complete `--lg-*` / `--fp-*` token set on its own panes and
copies the shipped `.lg-fm` rules verbatim, so it cannot invert under the
injected `app-settings.js` and cannot poison the shared chrome profile. The
shot run **asserts the delta** — the light pane's card must compute a different
background from the dark pane's, else it is one theme photographed twice —
paired with a liveness read, and it verifies the shared profile's theme is
unchanged when it exits. Measured: light `rgb(255,255,255)` vs dark
`rgb(30,33,36)`, no horizontal scroll at 390, every CTA inside the viewport.

---

## 8. DELIVERED — what was actually built, and where it diverged from this plan

Written at the end of the lane, against `git diff --name-only main...HEAD`.

### Four files touched that this plan did not list

LANE-RULES: *"If it includes files that weren't in your plan, flag them."*

| file | why |
|---|---|
| `tools/gates/featured-override-redfirst.py` | the red-first harness. Implied by "red-first" in §4 but never named as a file. |
| `tools/migrations/200-featured-history-pinned.sql` | `featured_history` could no longer be read as a list of members who consented once pinning existed — and that is exactly what an audit would read it as. Additive, `IF NOT EXISTS`, and **the code never requires it to have run**. |
| `handoffs/200-featured-override-DEPLOY.md` | three deploy couplings a pull does not do, one of them with a deadline (dev2 goes dark until the `.local.php` is placed). |
| **`tools/gates/front-banner-patreon-dark-gate.py`** | ⚠️ **another lane's gate (#171/#169).** Its §B diffed the **whole front page** against `origin/main` and failed on any byte difference, which makes it a merge-blocker for every front-page lane rather than a flag check. #200's fallback band tripped it; I measured the diff first (+880 bytes, the band and nothing else) and narrowed the leg to the banner region it actually governs, reporting anything else as a named, sized NOTE. Reported to keeper on the board the same hour. |

### Two files this plan listed and the build did not need

- `platform/config/featured-consent.php` — the `.local.php` layer for that flag
  was documented **in the readers** (`index.php`, `internal-featured-pool.php`,
  `u.php`) where someone debugging it will actually look, rather than in a
  docblock one directory away.
- `archive-poc/api/v0/fp-save.php` — planned as "doc only". Left alone entirely:
  it forwards a raw `featured_member` object and, because omitted keys persist,
  carries `pinned` through unchanged. Nothing to say in the file that is not
  already said at the merge point in `_config.php`.

### The plan's biggest wrong call, and what corrected it

§1 recommended **keeping** the `profile_visibility` refusal on pins, and keeper
upheld it. **Ian overruled both of us** on 8/22 — *"Regardless of the status of
their profile. Please strip the saftey feature."* — and he was right on the
facts: asked to justify the fence before removing it, the measurement came back
**zero non-public members on live** and one test fixture on dev2 with nothing to
protect. The carve-out I argued for was defending an empty set.

What that changed downstream: the fence came out of the resolver, the refusal
came out of the dash, the status became a **winnowing filter with counts** rather
than a gate, every row got a new-tab profile link, and **gate 94 §F3 was re-aimed
from "a Private profile is refused" to "a Private profile is offered, labelled
and counted"**. A gate kept green by defending a struck-out behaviour is worse
than no gate.

### Coverage — what is measured, and what is not

**Measured, by rendering or executing:** all three flag states; the pin against a
member who fails every criterion; the same member unpinned (the control); a
pinned non-public member; the consent flag in both states with `consent_ack` set;
the three flag readers at their own directory depths; the status filter's buckets
and counts; and the admin dash rendered under **real WordPress with the branch's
class** (`ReflectionClass` asserting which file loaded).

**Asserted but not exercised end to end:** the `admin-post.php` POST round-trip
for Pin. `handle_pin()` redirects and `exit`s, and driving it for real would
write the live `config.json` that dev2's own front page reads. Its payload is
asserted per-key by gate 94 §F2, its lookup path was verified against the real
endpoint, and the resolver side is rendered against the exact config a pin
writes — but nobody has clicked the button. **Say so rather than imply otherwise.**
