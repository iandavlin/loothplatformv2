# 173 — PLAN: the header chip stops swelling the row

Lane `173-header-name`, branch `173-header-name` (cut from origin/main, 0 ahead /
0 behind at plan time). Issue #173, labels `approved`, `profile`.

Ian, 8/20 (screenshot, signed in as *Massimiliano Monterosso Maxmonte Guitars*):
*"Verbose names in the profile icon in the header? Maybe do a ....."*
Ian, 8/21, hitting it himself as *Ian Davlin The Looth Group*: *"Something
changed in the header. We are stacking words that used to be inline."*

---

## 1. What it actually is — measured, not guessed

`.lg-chrome__account-name` (`lg-shared/site-header.css:286`) is **one
declaration — a font**. No `white-space`, no cap. It is a blockified flex item
inside `.lg-chrome__account`, so a long `wp_users.display_name` simply wraps.

`.lg-chrome__inner` is `height: 60px` **fixed**, so the wrap does not grow the
bar — the account button grows (40px → 49 → 62 → 88) and **spills out of it**.
That is the "stacking words" in Ian's screenshot: the bar height never changes,
the chip overflows it.

### The real names, from the real table

`wp_users` on dev2, 1,933 rows. Longest display names are business-suffixed:

| chars | display_name |
|---|---|
| 71 | `Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New Braunfels, TX)` |
| 69 | `Dan Wolf & Steve Baker Chicago Fret Works Guitar & Amp Repair` |
| 67 | `Jackson Larwa Artichoke Community Music / Wind River String Company` |
| 40 | `Massimiliano Monterosso Maxmonte Guitars` (the original report) |
| 25 | `Ian Davlin The Looth Group` (8/21) |

### Where each one breaks today

Measured in the box's headless Chrome against the **real** stylesheet, header
rendered standalone (the `stripe-testgroup-pages-gate` harness pattern — no WP,
no DB, no login). Lines = rendered line count of the name span.

| name | 1440 | 1280 | 1200 | 1100 | 1024 | 950 | 900 | 821 | 640 |
|---|---|---|---|---|---|---|---|---|---|
| Ian, **Join pill on** | 1 | 1 | 1 | **2** | **2** | **3** | **3** | **3** | hidden |
| Ian, no Join pill | 1 | 1 | 1 | 1 | **2** | **2** | **2** | **3** | hidden |
| Massimiliano, Join on | 1 | 1 | **2** | **2** | **3** | **3** | **3** | **3** | hidden |
| Dave Staudte (71ch), Join on | **2** | **2** | **2** | **3** | **4** | **6** | **6** | **6** | hidden |

Two things fall out of that table:

- **The Join pill is worth ~76px of headroom**, exactly as the 8/21 comment
  says: Ian's own name holds one line to 1100 without it and breaks at 1100
  with it. Keeper adding him to the cohort at ~13:30 is what tipped his row.
- **Phone widths are already clean** — verified, not assumed:
  `@media (max-width: 820px) { .lg-chrome__account-name { display: none } }`
  is real and fires (`matchMedia` true at 640/390, computed `display: none`).

### Pre-existing, NOT this lane's — reported so nobody re-diagnoses it

With the Join pill present, **821–885px already overflows horizontally with a
THREE-CHARACTER name**: `documentElement.scrollWidth` = 872 at a 821px viewport,
`Ian`. Logo 138.3 + nav min-content 419.2 + aside-without-name 269.5 + gaps 36 +
padding 48 = 911 > 806 usable. That band is a #170 consequence, it predates this
issue, and no name cap can fix it. Numbers go in the report; I am not chasing it.

---

## 2. The fix

### 2a. The clamp — `lg-shared/site-header.css`

```css
.lg-chrome__account-name {
  font: 600 13px/1 var(--lg-font-sans);
  max-width: clamp(0px, calc(100vw - 934px), 220px);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
```

A flat `max-width` cannot be right at two widths at once, and the issue asks for
one that holds **at Ian's width, with the pill**. Space for the name at viewport
`W` is, measured: `W − 934`, where 934 = logo 138.3 + nav min-content 419.2 +
aside-without-name 269.5 + gaps 36 + padding 48 + scrollbar 15 + the chip's own
8px gap, rounded. So the clamp **is** that measurement, not a guessed number:

- ≥1154px → the full 220px cap. Ian's own name is 200.3px, so **he never sees
  his own name truncated on a normal desktop**.
- 1100 → 166px, 1024 → 90px, and it reaches 0 at 934 — below which the ≤820
  rule takes over anyway.
- Every width, every state: **one line, no vertical spill**, which is the
  defect Ian reported.

The alternative — `min-width:0` down the flex chain plus `flex-shrink:0` on the
siblings — self-adjusts with no magic constant, but it touches
`.lg-chrome__join`, which is **shared with the anon cluster** and is exactly
what this lane is told not to disturb. Rejected for that reason. The constant's
brittleness is answered by a gate leg (§3, the overflow-baseline assertion)
rather than by hope.

### 2b. The full name is hidden, never lost — `lg-shared/site-header.php`

- `title="<?= $h($display_name) ?>"` on the name span (hover recovery).
- A full-name row at the top of the opened account menu — the recovery path that
  works on touch, where `title` does not, and the only one left at ≤820 where the
  chip's name is already gone:
  `<li role="presentation" class="lg-chrome__account-menu-name">…</li>`,
  allowed to wrap so a 71-char name is readable rather than clipped again.

Both edits sit **inside the `authenticated` branch**. The anon render does not
change by a byte — and the `<?php` tags stay at their current indentation,
because indented tags emit their own leading whitespace into every render
(the 9-byte leak gate 79 §C exists to catch).

### 2c. The mirrored copy — `archive-poc/web/archive.css:919`

archive.css carries its own copy of the chrome block. The front page loads
archive.css **then** site-header.css, so site-header wins there — but the two are
a deliberate mirror and any surface loading archive.css alone keeps the defect.
Same four declarations, same menu-row rule.

`lg-shell/lg-shared/*` also holds a copy. It is a 23KB stale fork of a 66KB file
and nothing serves it (`/srv/lg-shared/` matches `lg-shared/` byte-for-byte).
**Not touching it**; confirming and reporting.

### 2d. Gate 79's pre-existing red — one line, as the charter guessed

`tools/gates/header-join-gate.py:358` scans every stylesheet for a selector that
scopes `.lg-chrome__join`. It matches **prose inside a CSS comment** in
`membership-pages/web/lg-shortcodes.css:1025` — the #169/#171 contrast work left
the words `(lg-shared/site-header.css .lg-chrome__join)` in a comment, and
`.*\S\s+\.lg-chrome__join` reads that as a descendant selector. Fix is one line,
the same instinct gate 85 uses on PHP: strip `/* … */` before matching. Not my
markup, not my defect — fixing it and saying so.

---

## 3. Gate 87 — because this is the SECOND time

`lg-shared/site-header.css:377`'s own comment says the ≤820 hide exists because
the display name *"is what tips a busy admin aside into a two-line wrap"*. That
was discovery #1. This is #2 at desktop widths, so CRAFT-STANDARD's law says it
gets encoded before it is fixed again. 87 is free in both `run-all.sh` and the
table (highest is 86), verified from main, not from this branch.

`tools/gates/header-name-clamp-gate.py` (+ `-redfirst.py`). Renders the header
standalone through `php` with a synthetic `$ctx`, inlines this worktree's own
CSS, drives the box's headless Chrome over **widths × both themes × Join-pill
on/off** with the five worst real names, and asserts:

1. **Liveness first** — logo + nav + account button actually present. A blank or
   locked-out page must not pass having measured nothing.
2. **Exactly one line** at every width where the name is displayed, every name.
3. **The truncation is an ellipsis, not silent clipping** —
   `scrollWidth > clientWidth` whenever the name is clamped.
4. **No new horizontal overflow**: `documentElement.scrollWidth` for a 71-char
   name ≤ the same measurement with a 3-char name, at the same width and state.
   *This is the leg that keeps the 934 honest* — add a seventh nav item and this
   goes red rather than the constant silently being wrong.
5. The **full name** is in `title=` and in the opened menu, character for
   character.
6. **≤640 the name is still `display:none`** — the existing phone rule is not
   collateral damage.
7. **Anon**: no `.lg-chrome__account-name` in the DOM at all, and the anon HTML
   `cmp`-identical to `origin/main`.
8. **Exit 2 (CANNOT RUN)**, never 0, if Chrome is unreachable — `run-all.sh`
   reads 0 green / 2 cannot-run / anything else red.

Red-first: mutations on file **snapshots** (never `git checkout --`), each
reddening its own named assertion, plus a no-op proven inert.

---

## 4. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES:

| file | why |
|---|---|
| `lg-shared/site-header.css` | the clamp + the menu-row rule |
| `lg-shared/site-header.php` | `title=` + the menu name row |
| `archive-poc/web/archive.css` | the mirrored chrome block |
| `tools/gates/header-name-clamp-gate.py` | **new** — gate 87 |
| `tools/gates/header-name-clamp-redfirst.py` | **new** — its mutations |
| `tools/gates/header-join-gate.py` | the one-line comment strip (gate 79's pre-existing red) |
| `tools/gates/run-all.sh` | wire gate 87 |
| `docs/CRAFT-STANDARD.md` | row 87 |
| `docs/domains/PROFILE.md` | dossier update, same commit as the close |
| `handoffs/plans/173-header-name-PLAN.md` | this file |
| `handoffs/2026-08-21-173-header-name.md` | the handoff |
| `platform/nginx/lane-preview-173-header-name.conf` | **new** — so Ian gets a clickable URL, not a filesystem path |

Not touching: `lg-shell/lg-shared/*` (stale fork, nothing serves it),
`membership-pages/web/lg-shortcodes.css` (the gate-79 red is fixed in the gate,
not by editing someone else's comment), anything under `platform/config/`.

## 5. Verification

- Gate 87 **green on branch and RED on main** — if it cannot go red on main it
  is not measuring the defect.
- Gate 79 run individually (run-all exits early on main's gate-72 red, #175),
  attributed against `~/loothplatformv2-clean`.
- Anon render `cmp`-identical to `origin/main` in every state.
- A lane preview conf + screenshots: both themes × 1440/1280/1100/1024/900/390 ×
  Join-pill on/off, with Ian's own name and the 71-char worst case. Ian decides
  from pictures, so he gets a URL and shots, not a description.

## 6. Judgment calls I made rather than asking

- **220px cap** so Ian's own 200.3px name is never truncated on a normal
  desktop; the clamp does the narrowing below that.
- **The account-menu name row is new markup.** The issue rules the full name
  lives there; today the menu has no name at all, so this is the ruling being
  implemented, not scope creep.
- **The 821–885 pre-existing overflow is reported, not fixed.**
