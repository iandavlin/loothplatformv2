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

**Still open for Ian (#107 report):** four opted-in members cannot be featured
at all until their role resolves. Whether that is fixed by relaxing the card
(render without a role) or by asking those members for a one-line "what you do"
is his call, not this lane's — the charter's law is that nothing member-facing
changes here.
