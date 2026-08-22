# Lane `200-latest-pick` — a selection is never thrown away

**Ian, 2026-08-22:** *"Can we just make it so when I select a user they show up
on the front page again first."*

Branch cut off fresh `main` at `40e4286`. **No new gate number minted — 99 stays
free.** No mockups: working code, verified on the rendered admin flow.

---

## ⚠️ The brief's diagnosis was wrong in both halves

The lane was handed a cause — *a leftover PIN (Dan Erlewine) outranks the newer
pool pick, so make the latest admin action win* — and asked to build precedence.
**Measured first, by rendering the real `index.php`:**

| the brief said | what the box says |
|---|---|
| a leftover **pin** outranks the pool pick | **Dan Erlewine was never a pin.** He is the hand-placed **fallback** (`featured_member_fallback`, `kind => 'member'`), which outranks nothing — it appears whenever the real pick resolves to nothing. dev2's `config.json` has **no `pinned` key at all**. |
| latest-action-wins is broken | **It already held, both directions.** Standing pin on Carl → Feature Ian Davlin → page draws **Ian Davlin**; then pin Carl → page draws **Carl**. Both handlers already write `member_uuid` *and* `pinned` explicitly on every save. |

**Built as briefed, the precedence code would have been correct, changed
nothing, and Ian's pick would still have vanished.**

## The real cause: one line

    if (!$pinned && (trim($u['avatar_url']) === '' || $role === '')) return null;

A **pool** selection was discarded whenever the member's resolved role came back
empty; the front page drew the fallback. From the dash the click looks like it
did nothing.

**Reproduced on a real member Ian can click Feature on** — Carl Ioriatti: opted
in, public, has a photo, `business_name` ("Ioriatti") is a tail of his display
name so the repeats-the-name fallback is correctly skipped and `$role` → `''`.

| | before | after |
|---|---|---|
| POOL pick (`pinned=false`) | **"This spot is open"** | Carl Ioriatti |
| PIN (`pinned=true`) | Carl Ioriatti | Carl Ioriatti |

**Why removing it is safe, and it is not a new argument:** the guard existed
because the template rendered avatar and role *unconditionally*. #200 fixed that
at the template, and the guard's own docblock has said ever since that it "is no
longer what stands between us and a broken card". #200 acted on that for pinned
picks only, because that was the scope of the ruling then. This finishes it. The
consent fence is untouched — republication keys on `$pinned` in
`lg_fm_card_role()`, not on this guard.

## Two surfaces that then told Ian a lie

Removing a refusal is half a fix; the surfaces that *announced* it are the other
half, and they live in a different tree from the resolver.

- `internal-featured-pool.php` — `card_renderable` was `$blockers === []`. It
  predicts *"what the front page will actually do"*, and the page now draws
  everything, so it is **`true`**. `card_blockers` stays: it has stopped meaning
  *"will not appear"* and now means *"this card will be THIN"*.
- `FeaturedMemberDash` — the notice read *"Featured — but the front-page band
  will stay hidden…"* and the Card column read *"Won't show yet"*. **A dash that
  says a saved pick will not appear, while it is appearing, is the same defect as
  the disappearance, wearing words.** Both now describe the card he will get.

## A latent defect the gate found while being written

§G4's first version invented a clear payload and went RED: the page still drew
the **previous member's name**. Not what the dash does (`handle_remove()` blanks
`name`/`role`), but a real property of the page — `$clean + $existing` means any
writer that blanks the uuid and forgets the name leaves a card describing someone
no longer featured, and `config.json` on both boxes still carries leftover
`bio`/`avatar`/`cta_*` from an old hand-placement whose name, bio and `cta_href`
each described a **different member**. `index.php` now requires a **resolved**
card.

⚠️ **The obvious way to write that check is wrong.** Testing
`$lg_fm['member_uuid']` on the card looks equivalent — but **the resolver's
returned array has no `member_uuid` key**, so it made every real pick undrawable
and replaced all of them with the fallback. Caught before it left the worktree by
reading what the resolver actually returns.

## Gates

**Gate 94 — 41 assertions.** §B3 restated to the opposite verdict on the same
fixture · **§G0** `_config.php` merges `$clean + $existing` so the incoming
selection wins (asserted because reversing it is a one-character edit in another
file the render legs would simulate right past) · **§G1** Feature displaces a
standing pin · **§G2** a pin displaces a standing pool pick · **§G3** exactly one
band after a three-step sequence · **§G4** clearing falls back — the dash's real
payload *and* a careless partial write.

**Gate 39** — §F3 restated to both arms of the new agreement; §F4 restated to
describe the card rather than a refusal.

⚠️ **A false red on working code, worth more than the fix.** §C3 scanned raw
source for `lg_resolve_featured_member(`, so a **docblock** explaining the flag
counted as a second, ungated call site. **A mention in a comment is not a call
site.** §C3 and §F3 now blank comment lines before scanning, **padded to the same
length**, because the brace-matching indexes into the original string and
deleting lines shifts every later offset.

**Red-first** — four new legs, each caught with the right tag: the guard put back
(§B3), the merge reversed (§G0), the resolved-card requirement dropped (§G4), and
the dash promising a hidden band (§F4, run against **gate 39**, whose probe
executes the dash class — the harness learned to run either gate). It also
reported one of its own legs **BROKEN** — the one mutating the guard this lane
removes — which is the harness working: a stale leg is a finding, not a silent
pass. Retired with the reason and replaced by its inverse.

## Verified on the rendered admin flow

`tools/preview/200-latest-pick-verify.py` — a different question from the gate:
the row Ian looks at, its words, its button, and the resulting front page.

    ok  the dash never says "Won't show yet"
    ok  a thin member's Card column reads "Thin card"
    ok  a thin member still has a live Feature button (lg_featured_member_feature)
    ok  handle_feature() writes pinned => false explicitly
    ok  a fresh pool pick of a THIN member (Carl Ioriatti) takes the front page,
        displacing a standing pin

**It does not click the real button, and says so.** `handle_feature()` redirects
and `exit`s, and driving it for real would write the live `config.json` that
dev2's own front page serves. The payload is read from the handler; everything
downstream of the merge is really rendered.

## Reported, NOT fixed

1. **A pool pick who un-ticks or goes private after being chosen still drops
   out** and the band falls back. That is the member withdrawing their own
   consent, not the page discarding an admin's click — a different act, and
   nobody has ruled on it. One line in the resolver's `SELECT` if Ian wants it
   overridden too.
2. **The leftover hand-placed fields in `config.json`** (`bio`, `avatar`,
   `cta_href`, `cta_label`, `where`) are still there on both boxes, describing
   members who are not the current pick. Nothing renders them now — the resolver
   re-resolves everything live, and a uuid-less config no longer draws at all —
   but they are a loaded gun for any future code that reads the config card
   directly.

## Deploy couplings

**None.** No symlink, no timer, no migration, no config placement — a pull is the
whole deploy.

## Files touched

    archive-poc/web/index.php                      the guard; $lg_fm_resolved
    profile-app/api/v0/internal-featured-pool.php  card_renderable mirrors it
    lg-layout-v2/src/FeaturedMemberDash.php        the note and the Card column
    tools/gates/featured-override-gate.py          §B3 restated, §G0–§G4 added
    tools/gates/featured-member-gate.py            §C3 comment scrub, §F3/§F4 restated
    tools/gates/featured-override-redfirst.py      4 legs, stale leg retired, dual-gate
    tools/preview/200-latest-pick-verify.py        NEW — the rendered admin flow
    docs/domains/PROFILE.md                        the dossier (domain rule, same commit)
    handoffs/200-latest-pick-HANDOFF.md            this file
