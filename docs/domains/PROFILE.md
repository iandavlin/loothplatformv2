# PROFILE — profiles, directory, location, featured members

Location model: members set two precision dials (members-see / public-see:
street|city|state|private); owner always sees street. Display prefers what the
member TYPED (prefer_typed_address ON everywhere since 8/18, #ledger-item
location-address): users.location_address is the frozen import column — its
only writer was the one-time BB import. Item 20 (forced City/State directory)
KILLED 8/19 — member dials govern (Ian re-ruling). Dead-code sweep of its
dormant flag: #134 (backlog).

## Featured members — the pool, the dash, and the two different "ready"s (#107, 8/19–8/20)

**Ian's ruling, 8/19, both halves verbatim on #107:** *"I'd also like to be able
to select a member for features even if they don't hit the completion numbers…
the dash should allow me to select anyone"*, clarified the same night to
**"opted in only"**. So: **the consent tickbox is the ONE hard gate. Profile
completion never blocks his selection.** Privacy still does — a member who has
gone Private is refused, and that is not the wall he overruled. He explicitly
rejected the "literally anyone" reading that would have reversed the consent
pillar.

**Where the rule lives now.** `FeaturedMemberDash::selection_block_reason()` is
the whole of it — consent + privacy, nothing else — and BOTH the render and the
`admin-post.php` handler call it, so the button an admin sees and the refusal
the POST can give cannot drift apart. `eligible` on the pool endpoint was
verified unchanged and is correct at the source: consent is the SQL `WHERE`,
`eligible` is live privacy only. `card_ready` never leaked into it; the wall
lived only in the dash.

### ⚠️ THERE ARE TWO DIFFERENT "IS THIS MEMBER READY" TESTS AND THEY DISAGREE

| | what it tests | who uses it |
|---|---|---|
| `card_ready` | photo **+ what_you_do + location** (`Completeness::CARD_ITEMS`) | the completeness score shown to members |
| `card_renderable` | photo **+ role**, where role = `at_a_glance` **only if the header block is public**, else `business_name` | the dash's "will this actually show?" |

They are not interchangeable, in **both** directions: `card_ready` demands a
location the card does not need (it hides its own), and it is blind to the
header visibility the card's role depends on.

**Measured on dev2 2026-08-20 — 8 opted in, 7 card_ready, only 3 renderable.**
Rick Liftig, Stephen Martin, Eric Haskins and Karl Borum all read
`card_ready: true`, all had an enabled **Feature** button, and all four resolve
to no band. Cause: their header block is members-only (so the glance is
withheld) and their `business_name` is a tail of their display name (so the
fallback is skipped) — role resolves to `''`.

**An empty role has TWO causes, and they need opposite advice.** Of the five
members whose card cannot render, **four have already written a one-liner** —
it is simply members-only. Only Carl Ioriatti has written nothing. So
`card_blockers` names the cause (`what_you_do` vs `what_you_do_members_only`),
and the dash says *"has their one-line 'what you do' set to members-only, so
the public card may not repeat it"* for the four rather than telling them to
add a field they already filled in. Getting this wrong would have been
confident, specific, wrong advice about 4 of 5 — the same failure class as the
"Ready" label it replaces.

**Proven end to end, not inferred.** Featuring Rick through the real
`admin-post.php` path returned *"Saved and pushed to archive-poc"* and took the
band off the front page entirely: **74,456 → 72,838 bytes, zero `lg-fm__`
markers.** dev2 was restored byte-identical afterwards.

**The rule that follows:** the front-page resolver
(`lg_resolve_featured_member`, `archive-poc/web/index.php`) keeps its own guard —
no avatar or no role ⇒ `return null` — and it must, because the card's template
renders both unconditionally; bypassing it ships an `<img src="">` and a blank
line to the open web. So **removing the dash's wall without a warning would not
have fixed the silence, it would have widened it.** The pool endpoint therefore
reports `card_renderable` / `card_blockers` — the resolver's own verdict, its
rule reproduced exactly — and the dash says *"Won't show yet — needs …"*, labels
the button **Feature anyway**, and answers a warned save with the consequence
instead of a bare "Saved and pushed".

**Traps this leaves behind, for whoever is next:**
- The header-visibility rule that empties those four roles is **correct** (8/16:
  a members-only glance must not be republished on the public front page). Do
  not "fix" it by removing it.
- `card_renderable` **mirrors a rule that lives in another process** —
  archive-poc, which profile-app cannot call. Gate 39 §F3 goes RED if the
  resolver's guard changes without the predictor following. Keep them in the
  same commit.
- **An absent `card_renderable` is not `false`.** It means the pool endpoint is
  older than the dash (a half-finished deploy) — the dash reads it as *unknown*
  and stays quiet rather than warning about cards that are fine. The dash and
  the endpoint deploy together.
- The dash and the pool are **admin-only**; no flag, stated rather than skipped.
  Nothing member-facing renders differently — the resolver is untouched.

**Answered 8/20 — see the consent section below.** The "still open" question here
(relax the card, or ask those members for a one-liner) was ruled on the same day:
neither. The tick itself is the consent.

## The tick is consent — #107 follow-up, Ian 8/20

**Ruling (decision box, verbatim):** *"the tick is consent — a member ticking
'include me as a possible featured member' consents to their one-line 'what you
do' appearing on the public featured card."*

So the featured card — **and only the featured card** — may repeat an opted-in
member's `at_a_glance` even when their header block is members-only. The 8/16
never-republish rule stands everywhere else, unchanged.

**Where the rule lives:** `lg_fm_card_role()` in `archive-poc/web/index.php`.
Deliberately PURE — no DB, no config read, no globals — for two reasons that
have both been paid for: the pool endpoint in profile-app predicts this verdict
for the dash from a different process against a different database and cannot
call it, and `index.php` is a whole rendered page a gate cannot `require`.
Gate 39 §G3 lifts the function out by name and executes it over a 12-case truth
table, so the rule is *run*, not read.

### A members-only one-liner reaches the public card by TWO routes and no others

| route | what it is |
|---|---|
| **informed** | the tick was made at or after `informed_copy_since` — the moment the new tickbox copy reached members. `featured_opt_in_at` is stamped on every real false→true transition and NULLed on untick, so "re-confirming" re-stamps and needs no extra plumbing. |
| **acked** | an admin featured them while the dash spelled out what that would publish. `consent_ack` rides in `config.json` beside `member_uuid`. |

These are Ian's own two clauses — *"until they re-confirm OR Ian features them
knowingly"* — with both doing real work. **Everything else falls back to the
pre-#107 behaviour**: flag off, cutover unset, cutover unparseable, no opt-in
stamp. There is no third way in and no input that fails open.

**`consent_ack`'s ABSENCE is the protection against a silent upgrade**, and it is
the case that is easy to miss: a member already on the front page when the flag
flips would otherwise have their card switch quietly from their business name to
their members-only one-liner with nobody clicking anything. A selection written
before this shipped carries no ack, and neither does one written by `fp-save.php`
(the front-end editor path, which forwards a raw `featured_member` object it
knows nothing about). So the flip changes the NEXT pick, never the live one.
The dash writes `consent_ack` explicitly false on every save — a stale true from
an earlier selection would be consent from a member who never gave it.

**Measured on the real stack, 2026-08-20, the real pool of 8:**

| state | renderable | notes |
|---|---|---|
| flag OFF | 3 | byte-identical to the pre-change baseline |
| ON, every tick old-copy, no ack | 3 | byte-identical to OFF — no silent upgrade |
| ON, every tick old-copy, admin acked | 7 | the four unblock |
| ON, every tick informed | 7 | Carl Ioriatti stays blocked, correctly — he has no one-liner at all |

### ⚠️ A GRANT MUST LAND BEFORE THE FLAG FLIPS, AND ITS ABSENCE IS SILENT

The resolver runs as the Postgres role `archive-poc` against profile_app under
**column-scoped** grants, and it now reads `users.featured_opt_in_at`. That
column was not granted. Measured before the change: `SELECT featured_opt_in_at`
as that role returns **`permission denied for table users`** — an exception, not
a null — and the call site's try/catch degrades it to "no band" so the front page
cannot 500. **The visible symptom of a missed grant is the featured band
vanishing for every visitor, with nothing anywhere to say why.**

    sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql

Applied and verified on dev2 8/20; **live needs it before the flip**, and
re-applying after any profile_app restore (grants do not survive one). Gate 39
§G2 asserts the role can really read the column, so a blank band is a RED rather
than a mystery.

### The dash tells the admin which picks publish something new

The pool reports three new facts, and the dash reads them with the same
absent-key discipline as `card_renderable` — a missing key means the endpoint is
older than the dash, which is *unknown*, never "no problem here":

- `consent_informed` — null, not false, when the flag is off or no cutover is
  set. False there would read as a member having declined something.
- `glance_needs_ack` — featuring them publishes members-only text under a tick
  that predates the wording. False the moment they re-confirm, and false for a
  member whose glance is already public, since nothing is being republished.
- `header_vis_explicit` — **did they choose it, or is it just the default?**
  1,917 of 1,933 members have never opened their header settings, so telling Ian
  "they set this to members-only" would be wrong about almost everyone. The dash
  says *"they have never opened their header settings — members-only is the
  platform default, not something they picked"* instead.

Rendered against the real pool: OFF → 5 "Won't show yet", 0 consent notices;
ON/old-copy → 1 "Won't show yet" (Carl) and 4 "Will publish their one-liner";
ON/informed → 6 plain "Feature", 1 "Feature anyway". **Never a disabled button in
any state** — #107's original ruling still holds.

### One thing that CANNOT be verified until this merges, and why

The consent path was proven link by link on real infrastructure — the rule
executed over a truth table, the resolver against the real DB, the dash rendered
against the branch's pool, and `consent_ack` round-tripped through the real
`/archive-api/v0/_config` webhook. What could **not** be proven on dev2 is the
whole chain through a real `admin-post.php` click.

The reason is structural, not laziness: `FeaturedMemberDash::fetch_pool()` reads
`https://127.0.0.1/profile-api/v0/internal/featured-pool`, and that route is
served out of `~/loothplatformv2-clean` — i.e. **main**. A branch's dash always
gets main's pool. Main's pool has no `glance_needs_ack` key, so `consent_notice()`
correctly returns null (absent ≠ false), `consent_ack` is written false, and the
consent-warned branch of the handler is never entered. A real click on dev2
therefore re-proves the ordinary path and says nothing about the new one.

The nearest honest substitute — used here — is WordPress's own
`pre_http_request` filter to hand the shipped `render_page()` the branch's pool
without touching a line of shipped code. That produced the 2/5/0 → 4-notice →
6/1/0 matrix above.

**So after this merges, one thing is still worth doing on the serve:** feature an
old-copy ticker through the real dash and confirm `consent_ack: true` reaches
`config.json` and the band renders. ⚠️ `config.json` is owned by `archive-poc`,
so restoring it afterwards needs `sudo cp` — a plain `cp` fails with permission
denied, and if you only diff the rendered page you will not notice, because the
front page re-resolves live and looks identical either way.

### Traps this leaves behind

- `informed_copy_since` **must carry an explicit UTC offset**. It is compared
  against a Postgres `timestamptz`; a naive local string is read in PHP's default
  timezone and can mark a tick informed hours before the copy existed. Gate §G1
  refuses an ON without one.
- The flag file and the grant are **one deploy**, not two. See above.
- The dash and the pool endpoint deploy together, as before — and now the
  resolver joins them, since `consent_ack` is written by one and read by another.
- A preview of any of this needs `platform/nginx/lane-preview-107-consent-followup.conf`:
  every surface here is symlinked out of the serving checkout, so `/u/<slug>` on
  dev2 renders main no matter what is committed. Use **named** nginx captures —
  the dev-gate map resets `$1`-`$9` before fastcgi params are built.

---

## The meta tags obey the header ceiling — #166, CLOSED 2026-08-20

Ian, 8/20: *"Fix meta leak."* The leak #107's lane found and filed is fixed;
the section below records what it was, what the ruling turned out to be, and
the two traps it leaves.

**What it was.** A profile's `<meta name="description">`, `og:description` and
`twitter:description` carried `at_a_glance` **verbatim** to logged-out
visitors, crawlers and link unfurls, even while the header block correctly
withheld it from the rendered body. `profile-app/web/u.php` read `$seoGlance`
straight off the column with no visibility condition, while every other
consumer applied the ceiling. **42 members on LIVE, 28 on dev2**, of 56 who
have written a one-liner at all.

**The number that decided it.** Of live's 42, **ZERO had chosen members-only** —
`header_vis_explicit` is false for every one. It is the platform default that
1,917 of 1,933 members have never opened. So this was never a policy question
about what members had asked for: the head and the body simply disagreed about
what the untouched default meant. That is what made it a defect rather than the
two-coherent-positions ruling it was filed as.

**The fix** is the ceiling, applied to the head:

```php
$seoGlance = Block::headerCeiling($subjectId) === 'public' ? <glance> : '';
```

`Block::headerCeiling()` is the **body's own rule, called** — not
reimplemented. A second copy of "is the header public" is precisely how the two
surfaces drifted apart, so the fix deliberately adds no new predicate; anything
other than `public` fails closed. The public-safe generic branch already
existed for members with no one-liner, so the change only alters WHICH members
reach it. A public-header member's page is **byte-identical** across the change
(107,851 bytes, sha 48a2360c…).

**`/p/` had the same defect and it was worse per page**: practice `tagline` AND
`about` under `PRACTICE_HEADER_DEFAULT = 'members'`, and `about` is the long
field. 3 practices on both boxes. Fixed in the same commit via
`practiceHeaderCeiling()` (Ian ruled 8/20 to fix both together).

**Shipped UNFLAGGED, deliberately.** The flag law exists because dev2 serves
main, so member-facing work cannot be verified before merge. Here the risk is
inverted — **the OFF state IS the leaking state**, so a flag defaulted OFF
merges a fix that does nothing while 42 members stay indexed. Precedent:
789b480, the contrast fix. Worst case if the test were wrong in the strict
direction is a public-header member getting a generic snippet: an SEO
regression, visible and reversible, never a leak.

**SEO consequence, stated not buried:** those 42 profiles' Google snippets
change from the member's own sentence to *"Name — Business on The Looth Group…"*.
That is the point of the fix.

### ⚠️ THIS IS NOT THE #107 CONSENT EXCEPTION AND MUST NOT BECOME IT

#107's ruling — *"the tick is consent"* — lets the **featured card** repeat an
opted-in member's members-only one-liner. **A tick is not permission to put the
line in Google.** That consent covers the card and nothing else, so the head
reads header visibility ONLY: no flag, no `featured_opt_in`, no `consent_ack`.

Two gates hold this between them and neither is redundant:

| | asserts | on |
|---|---|---|
| gate 39 §G7 | the rendered **body** withholds it | the surface #107 reasoned about |
| **gate 83 §E** | the **head** withholds it, for an OPTED-IN member | the surface §G7 does not fail on |

Gate 83 §F additionally refuses the consent vocabulary in the SEO block's
**code** — the laundering path, where someone "helpfully" extends the card's
exception to the meta tags.

### Traps this leaves behind

- **Gate 83 audits whatever `LG_GATE_HOST` serves, which is MAIN.** `/u/` and
  `/p/` are symlinked out of the serving checkout. A lane must run
  `tools/preview/lane-preview.sh up <lane>` and set
  `LG_MGL_PREFIX=/preview/<lane>`, or it is being told about main.
  `platform/nginx/lane-preview-166-meta-leak.conf` is the worked example.
- **§B is the assertion that makes gate 83 mean anything.** Deleting all three
  meta tags satisfies the leak check perfectly, and 14 live members have a
  legitimately public one-liner that is good SEO. The fix is a **ceiling, not a
  deletion**. Never drop the public-header side of the bracket.
- **Gate 39 §G7's liveness marker is weaker than it looks** and was left alone
  on purpose. `"lg-idrow" not in body` is checked against the FULL response,
  and `lg-idrow` appears **12 times in the head as CSS rules** and zero times in
  the body of a members-only profile seen anonymously — where the body is the
  join gate. "The page rendered" is currently proven by a stylesheet. Not wrong
  today, but it is the assertion class that passes for the wrong reason;
  tightening it to `lg-gate` belongs to whoever owns that fence. Gate 83 uses
  `lg-gate` for exactly this reason.
- **The JSON-LD is clean** (name/url/image/worksFor only) — re-checked, and it
  stays that way only if nobody adds a `description` key to `$seoLd`.
- **`business_name` is treated as public** across the platform (the featured
  card's resolver uses it as the public-safe fallback), which is why the
  generic description may name it. If that ever changes, this description and
  the featured card change together.

---

## The header's account chip is ONE LINE — #173, CLOSED 2026-08-21

Ian, 8/20, with a screenshot, signed in as *Massimiliano Monterosso Maxmonte
Guitars*: *"Verbose names in the profile icon in the header? Maybe do a ....."*
Ian, 8/21, hitting it himself as *Ian Davlin The Looth Group*: *"Something
changed in the header. We are stacking words that used to be inline."*

**What it was.** `.lg-chrome__account-name` carried exactly one declaration — a
font. No `white-space`, no cap. It is a blockified flex item inside
`.lg-chrome__account`, so a long `wp_users.display_name` simply wrapped.

### ⚠️ THE HEADER BAR NEVER CHANGED HEIGHT, AND THAT IS THE TRAP

`.lg-chrome__inner` is `height: 60px` **fixed**. So *"the header is still 60px
tall"* is true of the broken state and of the fixed one — an assertion built on
it would have been green on the very screenshot Ian sent. What grew was the
**button inside** the bar: 40px → 49 → 62 → 88, spilling out of it. Measure the
button and the line count, never the bar.

**Measured on the real table** (`wp_users` on dev2, 1,933 rows). The long tail
here is business-suffixed names, not long personal ones — the worst is 71
characters, `Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New Braunfels,
TX)`. Lines rendered by the name span, before the fix:

| name | 1440 | 1280 | 1200 | 1100 | 1024 | 900 | 640 |
|---|---|---|---|---|---|---|---|
| Ian Davlin The Looth Group, **Join pill on** | 1 | 1 | 1 | **2** | **2** | **3** | hidden |
| same, no Join pill | 1 | 1 | 1 | 1 | **2** | **2** | hidden |
| Massimiliano … Maxmonte Guitars | 1 | 1 | **2** | **2** | **3** | **3** | hidden |
| Dave Staudte (71 ch) | **2** | **2** | **2** | **3** | **4** | **6** | hidden |

**The Join pill is the second aggravator and it is worth ~76px of headroom.**
Ian's name holds one line to 1100 without it and breaks at 1100 with it. Keeper
added him to the Stripe cohort at ~13:30 on 8/21 so he could test checkout under
#181, and under #170's `allowlist` state a signed-in cohort member gets a real
Join pill — working as designed. **His name had not changed; the row had.**

### The max-width is a measurement, not a number someone liked

```css
max-width: clamp(0px, calc(100vw - 934px), 220px);
white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
```

Space left for the name at viewport `W` is `W − 934`, where 934 = logo 138.3 +
nav min-content 419.2 + aside-without-name 269.5 + the inner's two 18px gaps +
its 48px padding + 15px scrollbar + the chip's own 8px gap. The **220 cap is
Ian's own name** (200.3px rendered) plus room, so he never sees his own name
truncated on a desktop; the 40- and 71-character business names get the ellipsis.

⚠️ **A FLAT `max-width` CANNOT BE RIGHT AT 1440 AND 1024 AT ONCE.** One generous
enough for the wide case turns the vertical wrap into a **horizontal** overflow
at the narrow one — the same defect pointing sideways. That is what gate 87 §D
mutation M3 demonstrates.

Below **1000** the clamp has under half a dozen characters left to give (66px at
1000, 16px at 950, 0 by 934), so the name drops there rather than rendering a
chip that says only `…` while still spending 8px of gap. The **≤820 rule stays**:
subsumed, not replaced — `archive.css` and `forums.css` mirror it.

**The full name is hidden, never lost**: `title=` on the span (hover) and its own
`role="presentation"` row at the top of the opened account menu (touch, and the
only path left at ≤1000 where the chip has no name at all). That row wraps on
purpose. Before #173 the menu carried **no name at all**.

### Traps this leaves behind

- ⚠️ **THE MENU'S CSS RULE MUST NOT GO IN `site-header.php`'s INLINE CRITICAL
  BLOCK.** That block is emitted on **every** render including the anonymous
  one, so the first draft grew the anon response by **745 bytes**. Caught by the
  byte-identity check, not by review. The panel tokens still resolve from the
  stylesheet — they are declared on `.lg-chrome` in dark by that block and fall
  back to the app's own tokens in light.
- **`archive-poc/web/archive.css` carries a second copy of the chrome block.**
  The front page loads it *before* `site-header.css`, so that copy wins there —
  but a surface loading archive.css alone would keep the defect. Both are fixed;
  gate 87 §A asserts both. `lg-shell/lg-shared/*` is a stale 23KB fork of a 66KB
  file that nothing serves — left alone.
- ⚠️ **PRE-EXISTING, FILED SEPARATELY, AND NO NAME CAP CAN FIX IT**: with the
  Join pill present, **821–885px already overflows horizontally with a
  THREE-character name** — `documentElement.scrollWidth` **872** at a 821px
  viewport with the name `Ian`, against 806 usable. Logo 138.3 + nav min-content
  419.2 + aside 269.5 + gaps 36 + padding 48 = 911. It is the **pill's** width,
  not the name's, and it predates #173. (The fix incidentally *reduces* it to
  841, because the name is hidden at that width.)
- **Gate 79's authed byte-identity leg was re-anchored** from `origin/main` to
  the tree's own `absent` render. It meant *"the join flag moves no bytes for
  this viewer"*, but anchoring it to a historical file made it *"the authed
  header may never change again"* — #173 reddened eleven of them at once. The
  **anon** leg keeps its `origin/main` anchor: that is #170's cacheability
  claim, and it is what red-first mutation 7 fires at.
- **Gate 79 was reading PROSE as a selector.** Its `.lg-chrome__join` scan
  matched the words `(lg-shared/site-header.css .lg-chrome__join)` inside a `/* */`
  comment — a standing RED on main, re-diagnosed twice. Comments are stripped
  now. ⚠️ It was in **two** files, not the one the charter named:
  `membership-pages/web/` and `lg-patreon-stripe-poller/assets/` both carry a
  copy of `lg-shortcodes.css`.
- ⚠️ **THE ACCOUNT NAME WAS NOT THE ONLY THING STACKING IN IAN'S BAND.** With
  the Join pill on, the `Looth Group` wordmark stacked at 1100/1024 (fixed by
  this change — the narrower chip gave the row its space back) and the nav's
  two-word items `The Hub` / `The Map` stack from 1154 down (**not** fixed, and
  identical on main). Without the pill this change un-stacks the nav too, at
  1100/1024/1010. `white-space: nowrap` on `.lg-chrome__menu a` is not the
  answer: it raises the nav's min-content floor from 419.2 to ~460 and converts
  the stack into a ~40px horizontal overflow — the same trade gate 87's M3
  mutation demonstrates. What frees real room is hiding the wordmark earlier,
  collapsing the nav below 820, or shortening those two labels; all three are
  Ian's calls and belong with the 821–885px finding, decided once for both.


---

## #200 — the override, the empty band, and the two things that actually broke live (8/22)

Ian, verbatim: *"The changes made to featured member has removed members from the
front page. The override I wanted would still have them on the frontpage even if
they didn't meet the criteria."*

### ⚠️ THE ISSUE'S STATED CAUSE WAS HALF THE STORY, AND MEASURING FIRST IS THE POINT

The card said "no member passes the eligibility criteria". Measured on live the
same hour, there were **two causes, either one sufficient on its own**:

1. **`tools/cut/featured-member-grants.sql` had never been APPLIED to live.**
   `has_column_privilege('archive-poc','users','featured_opt_in_at','SELECT')`
   was **false**, and `profile_sections` likewise. The resolver selects that
   column, Postgres raises `permission denied for table users`, the call site's
   `try/catch` swallows it, and the band disappears **for every visitor and for
   every pick, however perfect**. This file and `featured-consent.php` both warn
   about it in capitals. The warning was right; nobody had run the file.
2. **Even with the grant, the pick does not resolve.** Live's selected member has
   a members-only header, an empty `at_a_glance`, and a `business_name` that is a
   tail of their display name, so `role` resolves to `''` and the card-ready
   guard returns null.

**Measured across live's whole opted-in pool — 6 opted in and public, 2 render:**

| member | header | one-liner | business_name | renders |
|---|---|---|---|---|
| John Greenwald Epiphany Guitars, LLC | members | yes | tail of name | **no** |
| Jonathan Scott Chisels & Picks ← was live | members | no | tail of name | **no** |
| Bryan Parris | members | yes | Parris Guitars | yes |
| BodyShopGuitar | members | yes | (none) | **no** |
| ERIC OTTAVIANO MINOR FRET SJ | public | no | tail of name | **no** |
| Ian Davlin | public | yes | The Looth Group | yes |

### ⚠️ THE STOPGAP COULD NEVER HAVE WORKED, TWICE OVER — AND THIS IS THE GENERAL LESSON

Keeper handed Ian *"place a `featured-members.local.php` on live to turn it off"*.

- **Nothing read that file.** All three readers — `archive-poc/web/index.php`,
  `profile-app/web/u.php`, `profile-app/api/v0/me-featured.php` — took the
  tracked config plus `getenv`/`$_SERVER` and nothing in between. **A flag whose
  documented override is not wired is worse than a flag with no override, because
  the register says it has one.**
- **And even wired, flag-OFF was not the old band.** With a `member_uuid` in
  `config.json`, the off branch set `$lg_fm = null` outright. Measured by CLI
  render: **flag ON → 1 band, flag OFF → 0 bands.** "OFF is byte-identical" had
  only ever been proven against a config *without* a uuid; gate 39 §C greps
  source and had never rendered that pairing.

**The same class was already live one flag over:** dev2's
`featured-consent.local.php`, placed 2026-08-20 saying `enabled => true`, was read
by nothing — the box was believed to be running the consent rule ON and was
running it OFF for two days. Both flags now resolve **tracked → `.local.php` →
env/`$_SERVER`**, and gate 94 §D executes all three readers and fails if they
disagree.

### The override, and exactly what it does and does not bypass

| criterion | consented pick | **pinned pick** |
|---|---|---|
| `featured_opt_in` (the tick) | required | **bypassed** |
| `profile_visibility = 'public'` | required | **bypassed** |
| avatar present | required (else no band) | bypassed — the line is omitted |
| resolved `role` non-empty | required (else no band) | bypassed — the line is omitted |
| a members-only `at_a_glance` republished | per consent-A | **per consent-A, same as anyone** |

⚠️ **THE PIN IS ABSOLUTE, AND THAT IS IAN'S OVERRULE OF KEEPER, 8/22:** *"If I
select a user for featured member I want them shown. Regardless of the status of
their profile. Please strip the saftey feature. I want to know what it is in the
dash. I want a link to check out their profile. Open in new tab. I don't want to
dissapear the band."* Keeper had earlier upheld a privacy carve-out (with an
explicit "Ian can overrule" note); this is the overrule, and the fence was
stripped rather than narrowed.

**What was measured before the fence came out**, because the question worth
asking was what a pin could newly expose beyond a name, a photo and a link — a
members-only About or one-liner belonging to somebody whose whole profile is
private. **Live has ZERO members who are not public; dev2 has exactly one, a test
fixture with neither.** The exposure is not small, it is nil on both boxes, so no
carve-out was kept and none is hiding behind a comment. ⚠️ **One forward-looking
fact, recorded rather than fenced:** if a member ever goes Private while their
About section is marked public, a pinned card would publish that About.

**The one-liner follows the same rule as for anybody else.** Ian: *"if pinned,
show what the band shows for anyone else."* So `$pinned` is deliberately **not**
consulted in `lg_fm_card_role()`. A pinned member reaches `$mayRepublish` through
the same two doors as everyone, and #107's own wording opens the second for them
— *"until they re-confirm OR Ian features them knowingly"* — so the dash records
`consent_ack` on a pin and this is #107 being **exercised**, not routed around.
The `informed` door stays shut for a pin by construction: no tick, so
`featured_opt_in_at` is null. **Today it changes nothing on either box** — the
featured-consent flag is OFF, so `$mayRepublish` is false for everybody.

**The dash informs; it never blocks.** Status (`consented` / `never asked` /
`private profile`) is a **winnowing filter with counts**, not a label — Ian: *"The
privacy status was more for a stat for winnowing selections in the dash I
thought."* The buckets are mutually exclusive and computed by the **same SQL
expression** that produces the counts, so a filter's number and its rows cannot
disagree; the counts cover the whole match set, not the 25 rows shown. Every row
carries a `target="_blank" rel="noopener"` link to the member's profile, and
every row has a button.

**And pinning never writes `users.featured_opt_in`.** Gate 39 §D keeps
`me-featured.php` off the admin-impersonation allowlist so an admin cannot tick a
member's consent; pinning must not become the door that rule closes. Gate 94 §F1
asserts it directly.

### The empty-pool law — and the defect the gate found in it

Ian: *"with zero eligible members and zero picks, the band must render the old
hand-placed content or a designed fallback — never nothing."*

- `featured_member_fallback` is a **new TOP-LEVEL key in `defaults.php`**, not a
  sub-key. `config.json`'s overlay replaces `featured_member` **wholesale** and
  the webhook's `$allowed_keys` does not carry the fallback, so no dash write and
  no stale config can clobber it. That is not tidiness: live's `featured_member`
  had already drifted into a card whose **name, bio and cta_href each described a
  different member**, and falling back to it would have published a card
  describing nobody.
- **"Remove from front page" stopped meaning "hide the band".** It used to post
  `enabled => false` — the very hole the law forbids, one click away from a button
  that reads like "remove this member". It now clears the pick and blanks the
  uuid; wanting no band at all is a separate, labelled button.
- ⚠️ **"NO BAND" AND "A BAND WITH NOTHING IN IT" ARE THE SAME DEFECT.** `$lg_fm`
  is a config map, so `{enabled: true}` with no `member_uuid` — exactly what the
  new Clear writes — is **truthy**, skipped the resolver, and rendered a card with
  an empty `<h2>`. Caught by gate 94 §A1 **only because it prints the name**; the
  first version counted that a band existed and went green on a blank card. The
  test is now "is there anything to draw", not "is `$lg_fm` null".
- **A missing grant now degrades to a visible fallback rather than a hole.** Still
  wrong, but no longer invisible — and gate 39 §G2 says why.

### The card template, and a byte-identity claim that was wrong until it was measured

The resolver's null-return guard existed because the template rendered avatar and
role **unconditionally**, so an empty either shipped `<img src="">` and a blank
line. #200 guards them instead, which is what makes the pin safe.

⚠️ **The conditionals sit at COLUMN 0, and that is load-bearing.** PHP eats the one
newline after a closing `?>`, so a tag at column 0 contributes nothing while the
guarded line keeps its indentation. Written indented first, and diffing the two
renders showed the whole card's whitespace move; at column 0 the card region is
**byte-identical** to main for any card that has both fields. The claim was made
before it was measured, and measuring made it false — then true.

### Where the pin lives

`config.json`'s `featured_member.pinned`, written on **every** save, `false`
included: `_config.php` merges with `$clean + $existing` and PHP's `+` keeps the
left operand's keys, so **an omitted key persists** and a stale `true` would
silently reclassify a consented member as one an admin placed. Same discipline
and same reason as `consent_ack`.

`discovery.featured_history` grew a `pinned` column
(`tools/migrations/200-featured-history-pinned.sql`, applied on dev2, live is
Ian's), because after this ruling that table can no longer be read as a list of
members who consented — and that is exactly what someone auditing consent would
read it as. **Both the writer and the reader probe `information_schema` first**:
an INSERT naming a missing column dies inside a catch and would silently stop
recording stints, and a SELECT naming one would 500 the read so the dash says
*"No one has been featured yet"* about a table full of rows. An absent key renders
as a dash, never as *"opted in"*.

### Still open — reported, not fixed

- **The band always draws the pinned member.** Ian: *"I don't want to dissapear
  the band. Same band. Vis if I select the member."* With the fences stripped, the
  only way a pick fails to resolve is the member's row not existing at all, and
  that lands on the empty-pool fallback rather than on nothing. Gate 94 §B4
  renders a pinned non-public member and asserts the card shows **them**, not the
  fallback — a fallback there would be the disappearance he is describing wearing
  a different card.

- **Live needs the grant**, and it is Ian's to run:
  `sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql`.
  Without it the band shows the fallback rather than a real member.
- **Live needs the history migration** before its stints record how they happened.
- ~~**Ian has not yet chosen the empty-state design**~~ — **RULED B, 2026-08-22**:
  *"B is fine for featured. We haven't even announced it as a feature."*
  `defaults.php` ships `kind => 'invite'`, so the no-pick band now says *"This
  spot is open"* and recruits. See the #200-B section below — applying it found a
  defect the mock could not.
- **`business_name` being a tail of the display name is why four of six live
  members cannot render.** The `str_ends_with` test is correct — it stops a card
  saying the same three words twice — but it means the fallback almost never
  fires for members whose business is in their display name. Nobody has been asked
  whether that is the right trade.

---

## #200-B — applying the ruled empty state, and the button nobody had pressed (8/22)

Ian ruled **B** on 2026-08-22 having seen both drawn side by side. The change he
ruled on is **one data key** — `featured_member_fallback.kind`, `'member'` →
`'invite'` in `archive-poc/web/defaults.php`. The template and the CSS for that
shape had already merged with #200, so there was no markup and no stylesheet
change. Pictures of the result, on the real front page:
<https://dev2.loothgroup.com/footer-mockups/200-featured-b/>

### ⚠️ THE TRANSFERABLE ONE: A MOCK'S `href="#"` MEANS THE CONTROL IS UNTESTED

Variant B has **exactly one control**. The mock drew it as
`<a class="lg-fm__cta" href="#">Put me forward</a>`, so it looked complete and was
never once clicked; the merged build carried `cta_href => '/u/'`.

**MEASURED on dev2: a bare `/u/` with no slug is a real branded 404** — 5,114
bytes of "not found" page — because `u.php` resolves a **slug** and this box has
no self-profile alias. `/u/<real-slug>/` answers 200, which is why the value
looked right to everyone who read it instead of fetching it.

**A card whose only control dead-ends is worse than the hole it replaced**, which
was at least honest about having nothing. And the empty-pool law means this card
draws on **every** route to a missing band, to **both audiences** — so it would
have been a dead button on the front page for every visitor.

The fix is `'/profile/edit'`, the one URL that means "me" for every audience the
band renders to:

| viewer | what `/profile/edit` does |
|---|---|
| anon | login interstitial carrying `return=/profile/edit` (200, "Sign in to edit") |
| WP session, no `looth_id` | invisible hop through `/looth-auth/issue`, comes back |
| signed-in member | 302 to `/u/<their-slug>` — **which IS the inline editor**, and where the "include me as a possible featured member" tick lives (`u.php`, guarded by `$lg_fmOn && $isOwner`) |

**The general rule: a mock proves the picture, never the plumbing.** Any control
carried from a drawn mock into shipped code has never been exercised, whatever it
looks like. Fetch its destination before believing the value.

### The gate: assert the RENDER, not the file — and pair the reachability probe

Gate 94 grew two sections, both red-first proven (harness now 23/23, with a third
no-op control added for `defaults.php`, which these are the first legs to mutate):

- **§A4 — the no-pick page draws the shape Ian ruled.** Asserted on the *rendered
  page*, not on `defaults.php`. Reading `kind => 'invite'` out of the file proves
  somebody typed it; §A3's own history is the argument that this is not enough —
  breaking the invite branch made it fall through to a perfectly drawable
  `'member'` card while every source-level check stayed green.
  ⚠️ **This is NOT the hardcoded-state mistake** that
  `feedback-gate-reads-the-flag-not-a-hardcoded-state` warns about. That rule is
  about **flags**, which have several legitimate states and must not be pinned to
  one. `kind` is a **ruling** with one correct value until Ian gives another, and
  pinning it is the entire point: the `'member'` fields are deliberately still
  carried, so one word puts a person who was featured in June back on the front
  page while every other assertion stays green.
- **§A5 — the invite card's button reaches a real page, PAIRED WITH A KNOWN-BAD
  CONTROL.** `/u/` must come back 404 in the same probe; if it does not, the probe
  cannot tell reachable from unreachable and its green proves nothing
  (`feedback-absence-assertion-needs-liveness`). The fetch goes over the loopback,
  which the dev gate allows, so it needs no token — a token-gated fetch would be
  measuring the gate page.

### Checked rather than assumed, and worth copying

- **The invite-only CSS introduces three tokens** — `--lg-sage-3`,
  `--lg-sage-tint`, `--lg-sage-d` — and all three carry dark values on **both**
  dark paths (`html[data-lguser-theme="dark"]` and the `prefers-color-scheme`
  media query). #189 found three tokens on this box with no dark value at all, and
  a token with no dark value **silently stays light**. Check the tokens a new rule
  introduces, not only the rule.
- **The shots are renders, not drawings.** `tools/preview/200-featured-b-shots.py`
  renders this branch's `index.php` twice, once per shape, and photographs the
  band inside the real page — the serve is main, and main also has a real pick, so
  a plain fetch of dev2's front page could never have drawn a fallback at all. It
  asserts liveness, the light/dark delta (`rgb(255,255,255)` vs `rgb(30,33,36)`),
  the shape's own name, no broken image, and that the shared chrome profile's
  theme is unchanged on exit.
- **Two defects in the lane's own picture page were caught only by LOOKING at
  it** — unreadable squeezed desktop crops, and an orphaned fourth phone shot.
  Neither was reachable by any assertion in the harness, which happily reported 8
  images, none broken, no stray entities and no horizontal scroll. **Fourth time
  on this surface.**

### Reported by #200-B, not fixed

- ⚠️ **On a box where the featured flag is OFF, the invite card sends members to a
  page with no tick on it.** The band draws in all three flag states (the
  empty-pool law), but `u.php` renders the opt-in block only when
  `$lg_fmOn && $isOwner`. dev2 is ON via its `.local.php`, so the journey is whole
  here; **live's tracked default is OFF**, so until Ian arms it there, "Put me
  forward" would land a member on their own profile with nothing to tick. Not
  fixed because the live flag posture is Ian's call and the flag is expected to go
  on once he runs the grant — but it is a coherence trap for whoever flips either
  switch, and the honest options are to gate the CTA on the flag or to arm the
  flag in the same window as the band.
- The `'member'` fallback fields are still carried and still drawn by gate 94 §A3.
  Deliberate — a shape nobody draws is a shape nobody notices has rotted — but it
  does mean the road back to A is one word, which is what §A4 now watches.

---

## #195 — the View-as switcher says Edit, not Me (8/22)

Ian, verbatim: *"in the profile, I'd like the view as controls in the privacy
area to read edit instead of me."*

The other two positions name an AUDIENCE. **Me** looked like a third audience and
was not one: it is the member's own working view, the one where things can be
changed — which is what the panel's own hint already said, *"This IS your
editor."* `view=me` **is** edit mode (`$editing = ($isOwner && $role === 'me') ||
$adminEditing`, `u.php:127`; the same on `p.php:50`). **Edit** names what the
position does.

### ⚠️ THE LABEL MOVED AND THE VALUE DID NOT, AND THAT IS THE WHOLE RISK

Every consumer on this platform keys on the **value** `me`; **nothing anywhere
reads the visible text**:

| | where |
|---|---|
| `?view=me` | the URL each position links to (`$viewLink`, `u.php:135`) |
| `$role === 'me'` | the edit-mode predicate, and the `aria-current` highlight |
| `data-role="me"` | the SSR editor's button |
| `BOOT.role \|\| 'me'` | `edit.js:13` |
| `.lg-shell--owner` + `.lg-pmp` | `privacy-sheet.js`'s surface gate — markup, not a label |

Verified before the change: no gate, no test, no CSS selector and no stored
preference reads the label. `grep` of `tools/gates/` for `view=me`, `lg-viewas`
and `"View as"` returned **nothing** — the surface had no gate at all. That is
why the rename was safe, and **gate 97 asserts the pair per position** (text is
the new label AND the href/value still says `me`) so it stays safe. Asserting
only the text would go green on the one change that costs members their editor:
someone "tidying" `?view=me` to `?view=edit` to match the new word.

### THREE SWITCHERS EXIST. TWO ARE REACHABLE, AND THE THIRD IS NOT

| file:line | surface | reachable |
|---|---|---|
| `profile-app/web/u.php:929` | `/u/<slug>?view=me` — the privacy panel Ian named | yes |
| `profile-app/web/p.php:293` | `/p/<slug>?view=me` — the practice page | yes |
| `profile-app/web/_render.php:166` | `/profile/edit` — the SSR editor topbar | **no** |

**Why the third is dead, measured rather than inferred:** `edit.php:44` 302s any
member with a slug straight to `/u/<slug>`, and `looth_render_editor()` has
exactly one caller. The only slug-less rows are **ids 1, 1702 and 1703 — on dev2
AND on live — and all three are unclaimed**, so they hit the claim interstitial
first. Zero members, both boxes. Keeper's ruling: change the string anyway
(dead surfaces revive), build no render proof for it.

**`/p/` changed too**, on keeper's Q1 ruling: it is the same switcher over the
same values, and a practice owner moves between the two surfaces. One position
must not wear two names.

### ⚠️ THE RENAME PUTS A SECOND "Edit" ON THE SAME STRIP — REPORTED, NOT FIXED

Measured, and the two are not the same scale:

| surface | the other Edit | who sees it |
|---|---|---|
| `/p/` | `.lg-viewas__edit` — a white chip reading **"Edit profile"**, `href=/u/<slug>?view=me`, in that very row | **every practice owner** — 3 on live, 3 on dev2 |
| `/u/` | `.lg-chrome__edit` — the header pill reading **"Edit"**, `aria-label="WP Admin"`, `href=/wp-admin/`, gated on `manage_options` | **admins only** — 5 on live, Ian among them |

Keeper ruled option (a): change both, touch neither neighbour, and put the
adjacency in front of Ian as a picture rather than deciding for him. Renaming
`/p/`'s chip (e.g. to "Your member profile") is his call, asked with the shots.

### Shipped UNFLAGGED, deliberately

One word of visible text: no value, no behaviour, no new state. A flag would be
more code than the change and would merge a no-op. Precedent: #166, and 789b480
before it. The spend went into gate 97 instead, on a surface that had none.

### Traps this leaves behind

- **Gate 97 audits whatever `LG_VIEWAS_BASE` points at, and the default is the
  bare serve, which is MAIN.** `/u/` and `/p/` are symlinked out of the serving
  checkout. A lane runs `tools/preview/lane-preview.sh up <lane>` and sets
  `LG_VIEWAS_BASE=https://dev2.loothgroup.com/preview/<lane>`;
  `platform/nginx/lane-preview-195-edit-label.conf` is the worked example, and
  its `&$args` is load-bearing — without it every preview URL renders the
  default view and the three positions cannot be told apart.
- **`/p/` ASKED COLD RENDERS THE NON-OWNER PAGE** (52KB vs 104KB), because only
  `/u/` performs the invisible `looth_id` mint hop through `/looth-auth/issue`.
  A harness that fetches `/p/` first gets a page carrying the seg's *stylesheet*
  and none of its markup — which reads exactly like "the switcher is gone", and
  did. Warm on `/u/` first.
- ⚠️ **EVERY CLASS NAME IN THIS FEATURE IS ALSO A CSS RULE IN THE SAME RESPONSE.**
  `"lg-shell--owner" in html` is TRUE of a page whose body is plain
  `class="lg-shell"` — it appears five times as a stylesheet rule on `/u/`. As a
  liveness check it passes on a signed-out render; as a behaviour check it
  reported that `?view=member` renders edit chrome, a confident finding about a
  healthy page. Strip `<style>`/`<script>` and read the class off the ELEMENT.
- **`privacy-sheet.js:350-351` hides `.lg-viewas__vis` and `.lg-viewas__disc`.
  Neither class exists in any PHP** — only its `.lg-pmp` rule does anything. And
  `data-lg-privacy` measured **null** on dev2 `/u/` at 390 and 1440, so that
  sheet is not running there at all. Untouched by #195; filed here because the
  next person to work this panel will assume it is live.
- **`docs/CRAFT-STANDARD.md` carries TWO rows pointing at `switch-menu-gate.py`**
  — 93 and 95 — left by keeper's 93→95 renumbering. Minting from the table's max
  is still safe; reading it as an inventory is not.
