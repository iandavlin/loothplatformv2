# Lane `200-featured-b` — variant B applied to the featured band

**Issue #200, reopen-scope: the visual only.** The override logic merged earlier
on `200-featured-override`; this seat applied Ian's A/B ruling and nothing else.

**Ian, 2026-08-22:** *"B is fine for featured. We haven't even announced it as a
feature."*

**Pictures (dev-gated, and the deliverable):**
<https://dev2.loothgroup.com/footer-mockups/200-featured-b/>

---

## What changed

**One data key.** `archive-poc/web/defaults.php`,
`featured_member_fallback.kind`: `'member'` → `'invite'`. The template and the
CSS for that shape had already merged with #200 (`.lg-fm--empty`, the glyph
branch, the `!empty()` guards), so **no markup and no stylesheet change was
needed** — which is also why the gate was extended rather than a number minted,
per the charter.

**Plus the defect that applying it uncovered** — see below. That is the only
other behaviour change in the lane.

## ⚠️ The finding: variant B's one button was pointing at a 404

The mock drew the CTA as `href="#"`, so it looked finished and had **never been
clicked**. The merged build carried `cta_href => '/u/'`.

**Measured on dev2:** a bare `/u/` is a real branded 404 (5,114 bytes) — `u.php`
resolves a **slug**, and this box has no self-profile alias. `/u/<real-slug>/`
answers 200, which is why the value read as correct to everyone who looked at it
instead of fetching it.

This card draws on **every** route to a missing band and to **both audiences**,
so it would have been a dead button on the front page for every visitor — worse
than the hole it replaces, which was at least honest about having nothing.

Now `'/profile/edit'`: anon → sign-in interstitial carrying
`return=/profile/edit` (verified 200, *"Sign in to edit"*); a WP session with no
`looth_id` → invisible mint hop and back; a signed-in member → 302 to
`/u/<their-slug>`, which **is** the inline editor and is exactly where the
*"include me as a possible featured member"* tick lives.

**The general lesson, recorded in PROFILE.md: a mock proves the picture, never
the plumbing.** Any control carried out of a drawn mock has never been exercised,
whatever it looks like.

## Gate

**Gate 94 extended — no new number**, as the charter directed.

- **§A4** the *rendered* no-pick page draws the shape Ian ruled. Asserted on the
  render, not on `defaults.php`: §A3's own history is the argument, since
  breaking the invite branch falls through to a perfectly drawable `'member'`
  card while every source-level check stays green. This is not the
  hardcoded-state anti-pattern — that rule is about **flags**; `kind` is a
  **ruling** with one correct value until Ian gives another.
- **§A5** the invite CTA reaches a real page, **paired with a known-bad `/u/`
  control** so the probe cannot pass vacuously.

**Gate 94: GREEN, 35 assertions.** **Red-first: 23/23** (was 21), including three
no-op controls — one added for `defaults.php`, which these are the first legs to
mutate, so an A4/A5 red cannot be the gate reacting to the file being touched.

Both new legs caught their defect with the right message:

    caught  the ruled invite shape is reverted to the hand-placed card
            -> RED [A4] ... the band draws the HAND-PLACED shape ('Dan Erlewine')
    caught  the invite card's button points back at the 404
            -> RED [A5] ... points at /u/, which answers 404

## The shots are renders, not drawings

`tools/preview/200-featured-b-shots.py` renders **this branch's** `index.php` in
the no-pick state once per shape and photographs the band inside the real front
page; the only differing input is the word Ian ruled on. Both complete pages are
published too.

⚠️ The serve is main, **and main also has a real pick**, so a plain fetch of
dev2's front page could never have drawn a fallback at all. The stylesheet the
published pages load is the serve's, verified byte-identical to this branch's for
every `.lg-fm` rule before it was trusted. Nothing on the serve was touched.

Asserted before a shot counts: liveness (a locked-out browser photographs a
styled 403 identically in both themes at every width); the light/dark **delta**,
measured `rgb(255,255,255)` vs `rgb(30,33,36)`; the shape's own name; no broken
image; `.lg-fm--empty` present and the CTA not the dead one; and the **shared**
chrome profile's theme read-only and unchanged on exit. `defaults.php` is swapped
to render the BEFORE and restored in a `finally` from an in-memory snapshot,
never `git checkout --`, and the run refuses to continue if the restore did not
take.

**Two defects in this lane's own picture page were caught only by looking at
it** — desktop crops squeezed unreadable by a two-column grid, and an orphaned
fourth phone shot. The probe reported 8 images, none broken, no stray entities,
no horizontal scroll. Fourth time on this surface.

## Reported, NOT fixed

1. ⚠️ **On a flag-OFF box the invite card sends members to a page with no tick on
   it.** The band draws in all three flag states (the empty-pool law), but
   `u.php` gates the opt-in block on `$lg_fmOn && $isOwner` (lines 214, 970).
   Measured: the repo's tracked default is `enabled => false`; dev2 overrides to
   `true` via `platform/config/featured-members.local.php` in the serving
   checkout, so the journey is whole **here**. **Live is OFF**, so until Ian arms
   it there, *"Put me forward"* lands a member on their own profile with nothing
   to tick. Left alone because the live flag posture is Ian's call and the flag is
   expected to go on once he runs the grant — but whoever flips either switch
   should flip both, or gate the CTA on the flag.
2. **The `'member'` fallback fields are still carried** and still drawn by gate 94
   §A3. Deliberate — a shape nobody draws is a shape nobody notices has rotted —
   but the road back to A is one word, which is what §A4 now watches.
3. **Two stale comments in `index.php` were corrected here** rather than reported:
   it still claimed *"FLAG OFF IS BYTE-IDENTICAL"* (untrue since #200's empty-pool
   law) and still described the hand-typed Dan Erlewine band as what ships. The
   first was already wrong before this lane; both are fixed in the same commit as
   the behaviour they describe.

## Deploy couplings

**None.** No new file needs a symlink, no timer, no migration, no config
placement. `defaults.php` and `index.php` are already served out of the checkout,
so a `git pull` is the whole deploy. dev2's existing
`featured-members.local.php` is untouched.

## Files touched

    archive-poc/web/defaults.php                 kind => invite; the CTA; the ruling recorded
    archive-poc/web/index.php                    two stale comments corrected
    tools/gates/featured-override-gate.py        gate 94 §A4 + §A5
    tools/gates/featured-override-redfirst.py    two legs + a defaults.php no-op control
    tools/preview/200-featured-b-shots.py        NEW — the before/after render + shot run
    footer-mockups/200-featured-b/               NEW — the pictures and both full pages
    docs/domains/PROFILE.md                      the dossier (domain rule, same commit)
    handoffs/200-featured-b-HANDOFF.md           this file

`tools/gates/run-all.sh` deliberately **not** touched — gate 94 is already
registered, and this lane minted no number.
