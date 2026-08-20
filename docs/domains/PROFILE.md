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

### 🔴 A PRE-EXISTING LEAK THIS LANE FOUND AND DID NOT FIX

While proving the exception is confined, the confinement turned out to be
narrower than the 8/16 rule assumes. **A profile's `<meta name="description">`,
`og:description` and `twitter:description` carry `at_a_glance` verbatim to
logged-out visitors, crawlers and link unfurls — even when the header block
correctly withholds it from the rendered body.**

Measured 2026-08-20 on dev2, all four members with a default header and a written
one-liner: absent from the visible body for anon, present in the head every time.
Site-wide it is **28 public members** (of 37 who have written a glance at all).

It predates this lane, it is member-facing, and reddening main for it would block
every seat — so gate 39 §G7 asserts the **body** and states this in its own
docblock rather than failing on it. It also means the featured-card republication
is a smaller delta than it looks: for these members the one-liner is already
reachable publicly. **That is a ruling for Ian, not a lane decision** — either the
meta tags should honour header visibility, or the never-republish rule should be
restated to mean the rendered page only.

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
