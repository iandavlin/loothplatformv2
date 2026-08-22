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
- **Ian has not yet chosen the empty-state design** (A: the standing hand-placed
  card; B: a designed "this spot is open" invite). Both are implemented and both
  are asserted by gate 94 §A3; `defaults.php` ships `kind => 'member'` because it
  is the status quo. His choice is a one-line data change.
- **`business_name` being a tail of the display name is why four of six live
  members cannot render.** The `str_ends_with` test is correct — it stops a card
  saying the same three words twice — but it means the fallback almost never
  fires for members whose business is in their display name. Nobody has been asked
  whether that is the right trade.
