# 195-edit-label — PLAN

**Issue #195** — *"in the profile, I'd like the view as controls in the privacy
area to read edit instead of me."* (Ian, 2026-08-22, verbatim.)

Status: written after measuring, held at the plan-mode wall pending keeper's
ruling on Q1–Q3 (boarded 2026-08-22 16:5x). **No code touched yet.**

---

## What the control actually is — measured, not assumed

Three `Public / Member / Me` switchers exist in the tree. **Two are reachable.**

| # | file:line | surface | panel | reachable |
|---|---|---|---|---|
| 1 | `profile-app/web/u.php:929` | `/u/<slug>?view=me` | the **privacy panel** — row label "View as", group `aria-label="Profile controls"` | **yes — this is the one Ian named** |
| 2 | `profile-app/web/p.php:293` | `/p/<slug>?view=me` | the practice page — group `aria-label="Preview your practice as"` | **yes** |
| 3 | `profile-app/web/_render.php:166` | `/profile/edit` | SSR editor topbar, "👁 viewing as" | **no — zero members, both boxes** |

**Why #3 is unreachable, measured rather than inferred.** `edit.php:44` 302s any
member with a slug straight to `/u/<slug>`; `looth_render_editor()` is called
from exactly one place (`edit.php:57`) and only after that redirect is skipped.
The only slug-less rows are ids **1, 1702, 1703 on dev2 AND on live** — all
three `claimed = f`, so they hit the claim interstitial before the editor
renders. Nobody can see this switcher.

**The panel's own hint already argues for the rename.** `/u/`'s privacy panel
ends with *"This IS your editor — click any field … to edit it in place."*
`view=me` **is** edit mode: `$editing = ($isOwner && $role === 'me') ||
$adminEditing` (`u.php:127`), and the same on `/p/` (`p.php:50`). "Edit" names
what the position does; "Me" names an audience that isn't one.

## Nothing keys on the string "Me"

Every consumer keys on the **value** `me`, never the visible text:

- `?view=me` in `$viewLink` (`u.php:135`, `p.php`), `$role === 'me'`
- `data-role="me"` and `BOOT.role || 'me'` (`edit.js:13,24,38`) — the unreachable editor
- `privacy-sheet.js` gates on `.lg-shell--owner` + `.lg-pmp`, not on any label

**There is no gate on this surface at all.** `grep` of `tools/gates/` for
`view=me`, `lg-viewas` and `"View as"` returns nothing. No test, no CSS
selector, no stored preference reads the text. Changing the label cannot break
a consumer; changing the **value** would break all of them, so the gate I add
asserts text and href **together**.

## Before-shots — 8/8 clean

`tools/preview/195-label-shots.py before`, both surfaces × light/dark ×
1440/390, each hit-tested with `elementFromPoint` and liveness-asserted
(`.lg-shell--owner` present, no `.lg-gate`, no 403 text). Output:
`~/projects/footer-mockups/195-edit-label/before/`.

---

## ⚠️ The rename puts a SECOND "Edit" on the same strip, in both places

Measured, and the two are **not** the same scale:

| surface | the other "Edit" | who sees it |
|---|---|---|
| `/p/` | `.lg-viewas__edit`, white chip, text **"Edit profile"**, href `/u/<slug>?view=me` — same row, ~1000px right on desktop | **every practice owner** — 3 on live, 3 on dev2 |
| `/u/` | `.lg-chrome__edit`, header pill, text **"Edit"**, `aria-label="WP Admin"`, href `/wp-admin/` — gated on `manage_options` | **admins only** — 5 on live, Ian among them |

After the change `/p/`'s row reads `Public | Member | Edit … Sections … Edit
profile` — two Edits, one meaning *preview this practice as myself*, the other
*leave and go edit my member profile*. This is the reason Q2 is boarded rather
than decided.

---

## Files I expect to touch

Guessing **wider** rather than narrower, per LANE-RULES.

**Certain**
- `profile-app/web/u.php` — the label at :929 (the control Ian named)
- `tools/preview/195-label-shots.py` — the before/after harness (already added, uncommitted)
- `docs/domains/PROFILE.md` — LANE-RULES: a `profile`-labelled issue updates its dossier in the closing commit
- `handoffs/plans/195-edit-label-PLAN.md` — this file
- `handoffs/2026-08-22-195-edit-label.md` — the handoff

**Conditional on Q1 (scope) — expect to touch**
- `profile-app/web/p.php` — the label at :293

**Conditional on Q2 (collision)**
- `profile-app/web/p.php` — the `.lg-viewas__edit` chip text, only under option (b)

**Conditional on Q3 (gate) — expect to touch**
- `tools/gates/viewas-label-gate.py` — **new**, number minted from **main** not this branch (`feedback-gate-number-from-main-not-branch`)
- `tools/gates/run-all.sh` — registering it
- `docs/CRAFT-STANDARD.md` — the gate table row

**Probable**
- `docs/atlas/PROFILE-SYSTEM-AUDIT.md:64` — instructs members verbatim to *"click 'View as → Me'"*; stale the moment this lands (`feedback-update-what-points-at-the-work-not-just-the-work`)
- `docs/atlas/PROFILE-GUIDE-SHOTLIST.md` — B6/B7 rows name the switcher

**Deliberately NOT touching**
- `profile-app/web/_render.php:166` — unreachable; reported, not changed, unless keeper says otherwise
- `footer-mockups/**` (5 files, 11 occurrences) — mockups, not shipped
- `docs/plan-*.md`, `docs/archive/*.md` — historical records of decisions
- any value: `?view=me`, `$role==='me'`, `data-role="me"` all stay exactly as they are

## Steps

1. Hold for keeper's Q1–Q3 ruling. **(here)**
2. Change the label(s) — text only, values untouched.
3. Add the gate: per position, assert the **text** is the new label **and** the
   **href** still carries the old value, on both reachable surfaces, both
   themes, both widths. Red-first it with a mutation per assertion, including a
   mutation that moves the *value* — the failure this gate exists to catch.
4. After-shots through a **lane preview** (`/u/` and `/p/` are symlinked out of
   the serving checkout, so dev2 renders **main** without one — the #166 trap).
5. Update the dossier + the stale atlas instruction in the same commit.
6. Publish before/after pairs, board DONE with pictures.

## Open questions (boarded to keeper)

- **Q1 scope** — `/u/` only (literal), or `/u/` + `/p/` (one position, one name). *Recommend both.*
- **Q2 collision** — (a) change both, leave neighbours; (b) change both + rename `/p/`'s chip; (c) `/u/` only. *Recommend (a).*
- **Q3 flag** — unflagged (one word, no behaviour, precedent #166) with a new gate instead. *Recommend unflagged + gate.*

## Reported, not fixed

- `privacy-sheet.js:350-351` hides `.lg-viewas__vis` and `.lg-viewas__disc`.
  **Neither class exists in any PHP** — only its `.lg-pmp` rule does anything.
  And `data-lg-privacy` measured **null** on dev2 `/u/` at 390 and 1440, so that
  sheet is not running there at all.
- The `/u/` privacy panel is the same `.lg-viewas` seg on phone and desktop —
  there is no separate phone control to keep in step.
