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

### C. Also measured, for scale

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

**The one criterion I recommend keeping — and this is the plan's one open
decision.** `profile_visibility = 'private'` is not a completeness bar, it is the
member's own switch saying "I am not public". Pinning such a member would put
their name and photo on the open web and link to a profile the public cannot
open. On both boxes this is **1 member**, so keeping it costs nothing today.
The dash will list Private members in the search marked *"cannot be pinned —
their profile is Private"* rather than hiding them, so the refusal is legible.
**Say the word and I will make it bypassable too** (with a loud dash warning);
I would rather ask than silently interpret "regardless of the criteria" as
"including the one criterion the member set themselves".

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
4. **`lg_resolve_featured_member()`** takes a `$pinned` argument: drops
   `featured_opt_in = true` from the WHERE, keeps `profile_visibility='public'`,
   forces the consent republication rule to *off* for the glance, and skips the
   avatar/role card-ready guard.
5. **The card template** guards `.lg-fm__avi` and `.lg-fm__role` with `!empty()`,
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

**Gate number minted from MAIN: 94.** `run-all.sh` on main tops out at 93 (and
already carries **two** blocks numbered 93 — `switch-menu` and `products-tab`,
a pre-existing collision I am not fixing here, only reporting). Every live
worktree was diffed against main for `run-all.sh`: only `emoji-picker-build`
touches it, and it is an old branch still on the "/19" numbering, so 94 is free.

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

**Gate 39 needs one honest edit, not a suppression.** §C's "OFF is byte-identical"
is no longer the whole truth once OFF renders a fallback band. I will restate it
as the property that actually matters — OFF publishes no *real member* — and say
so in the gate's own comment, rather than deleting an assertion that has been
doing real work.

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
    archive-poc/api/v0/fp-save.php                (read only unless it forwards `pinned`)
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

**No overlap with any live lane**: no other worktree touches any of these files
(checked by diffing all nine worktrees against main).

**Not touched, on purpose:** live, and `tools/cut/featured-member-grants.sql`
(the file is correct; it was never *applied*, which is Ian's command above).

---

## 6. What I will report but not fix

- The two `GATE 93` blocks already on main.
- `featured-consent.local.php` on dev2 has been inert since 8/20 — the reader
  this lane adds is what makes it real, which means **placing it becomes a
  behaviour change on dev2** and needs Ian's eyes before it counts as "on".
- `/srv/archive-poc/config.json` is a live decoy on both boxes.
