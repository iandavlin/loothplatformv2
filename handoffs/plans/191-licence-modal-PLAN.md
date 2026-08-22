# PLAN — lane 191-licence-modal (#191)

## Context

The Loothprint compose form asks members to pick a Creative Commons licence, and
one of the four options **describes a licence that does not exist**:

> BY ND NC (Credit given to creator, No Derivatives, **Adaptations shared with same terms**)

"No Derivatives" and "adaptations shared with same terms" contradict each other —
the second clause is Share-Alike, a different licence. Members are choosing legal
terms off that sentence.

Ian, 8/21: *"pare it down. Could we get a i that pops up a modal with the entire
legal contract?"* — so alongside the fix, the choice gets an ⓘ that shows the real
terms of whatever is currently selected, held offline.

**Measured before planning** (the charter's report-first item, already boarded):

| where | count |
|---|---|
| dev2 postmeta `loothprint_creative_commons` = the wrong string | **3 posts, all published** |
| live postmeta, same key | the **same 3**, all published |
| dev2 baked `_lg_layout_v2` blocks containing the wrong string | the **same 3** |

`33871` stewmac-offset-diamond-fret-file-handle · `51126`
dura-gold-stickyback-sandpaper-roll-storage · `57824`
endoscope-positioning-device-the-endo-stay

Two copies each, because only 4 of 172 loothprints are synthesized at render — the
rest have the licence sentence **baked into stored layout blocks**. A migration
touching only postmeta would leave the wrong text still rendering on the page.

`loothcut_creative_commons` is a separate field, 11 rows, all on the correct
BY-NC-SA string. Unaffected. Only the fourth choice is wrong; the other three are
legally fine.

**Keeper's ruling (8/21), which this plan implements:** correct all three, both
copies, dev2 only. The framing that makes it safe: **the letters were always
right** — BY-NC-ND is a real licence — and what was wrong is the English gloss
beside them. This corrects a description to match the licence the author chose; it
changes nobody's licence. If any post's *letters* turn out wrong or ambiguous, the
migration stops and boards it.

---

## The corrected option

    BY NC ND (Credit given to creator, Non-Commercial only, No Derivatives)

Canonical CC ordering, and it matches its sibling `BY NC SA (…)` in the same list.

---

## 1 · The label fix — in code, not in the DB

New `lg_fc_licences()` in `platform/mu-plugins/lg-frontend-compose.php`: one table
holding, per licence — the **stored choice string**, a **plain summary**, the
**legal-text filename**, and the **legacy strings it supersedes**.

`lg_fc_relabel()` (line ~2059, already the route's relabel seam) gains a block for
`loothprint_creative_commons` that replaces `$field['choices']` from that table.

**In code and not by editing ACF field `field_6564e26df56ba`**, for the reason the
charter gives — it survives anyone editing the field in wp-admin — *and* because a
DB edit is not traceable to a commit and would not deploy to live anyway.
⚠️ Consequence to report, not fix: **wp-admin still shows the wrong label** to
anyone editing a loothprint there. That is Ian's call, not this lane's.

## 2 · The legacy-value safety — the defect this fix would otherwise introduce

In ACF the choice **key is the label string**. The moment the label changes, a
stored legacy value matches no choice, ACF's radio renders with **nothing
selected**, and a save then blanks that member's licence.

So the same block maps a legacy value forward **for the render only**. Kept even
after the dev2 migration, because live keeps the wrong values until Ian runs the
command, and a fresh cut of dev2 from live reintroduces them.

## 3 · Pare the description

`lg_fc_types()` line 521 — drop *"The usual choice — leave it unless you know you
want something else."* (the pattern Ian removed from the paywall control in
`171ab17`) for a plain statement of what the field is: **"How other people may use
your print files and photos."** The ⓘ carries the detail.

## 4 · The ⓘ modal

- **The button** — appended to the field label via the `acf/get_field_label`
  filter, scoped to this field, added and removed around the render like
  `lg_fc_relabel`. *Verified on the box*: `acf_get_field_label()` runs
  `esc_html()` on `$field['label']` **before** the filter, so appending markup to
  the label in `prepare_field` would render as visible text — the filter is the
  only seam. A `<button>` with `aria-*`/`data-*` survives ACF's `acf_esc_html()`
  intact (tested). Fully server-rendered, so curl can measure it.
- **The dialog** — native `<dialog>` + `showModal()`, echoed after `acf_form()` in
  `lg_fc_render()` (outside the form, so no button can ever submit it). Native
  gives real modal semantics, inert background and Escape for free; focus return
  to the ⓘ is done explicitly anyway so it can be asserted.
- **Plain summary first, complete legal text below**, in a scrollable region.
- **It follows the current selection**: the ⓘ reads the checked radio at open
  time. All four texts ship in `<template>` elements — 78.7 KB raw, **7.4 KB
  gzipped for all four together** (measured), against a 2.5 MB page budget. No
  fetch, no race, works on the edit form's prefill.
- Escape closes · focus returns · both themes.
- The code comment says why a modal is right here and #189's "no modal" rule is
  not violated: that rule was about a **file picker**; this is reference text.

## 5 · The legal text, offline

`platform/licences/cc-by-4.0.txt`, `cc-by-sa-4.0.txt`, `cc-by-nc-sa-4.0.txt`,
`cc-by-nc-nd-4.0.txt` — fetched verbatim from creativecommons.org (already
retrieved, 200s, 18–21 KB each), plus a `README.md` recording source URL and date.

Read with `dirname(__DIR__) . '/licences/'`, the **same idiom `lg_fc_enabled()`
already uses** for `platform/config/`. ⚠️ Deliberately **not** a new directory
under `mu-plugins/`: those are symlinked one-per-entry (33 of them), so a new dir
there is a deploy coupling a `git pull` does not do. This path needs none.

## 6 · The migration (keeper's ruling)

`tools/migrations/191-licence-label.php`, run through `wp eval-file`.

- **Three literal ids**, no LIKE, no globbing, no "all posts matching".
- **Both copies**: postmeta, and the `lp_license` callout body inside the
  PHP-serialized `_lg_layout_v2` blocks.
- **Idempotent**: rewrites only an exact legacy-string match; a post already
  correct is left untouched, and a second run is a no-op.
- **Refuses on ambiguity**: if a post's licence letters are not BY/NC/ND it stops
  and reports, per keeper.
- Dry-run by default, `--apply` to write. Prints **before and after for each**.
- ⚠️ `wp eval-file` runs in **function scope** (recorded trap) — everything lives
  inside one function, no top-level globals.
- Then a **rendered dev2 page** for Ian, and a **live command handed to keeper**
  with the same three literal ids. This lane never touches live.

## 7 · The gate

`tools/gates/compose-licence-gate.py` + a red-first harness. **Number: asked
keeper, 91 requested** (90 is the highest in `run-all.sh`); lanes never self-number.

Legs — chosen so none can pass vacuously:

1. **Liveness** — the form arrived (a locked-out browser serves a styled 403 that
   passes presence assertions).
2. The contradictory string is **absent**; the corrected option is **present**;
   there are exactly four choices.
3. The nudge sentence is **absent**, the new description present.
4. The ⓘ is present, keyboard-focusable, and opens a dialog.
5. **The modal follows the selection** — select each of the four in turn, open,
   assert the summary and legal heading match *that* licence. This is the leg the
   gate exists for.
6. Escape closes; focus returns to the ⓘ.
7. **Both themes**, asserting a **delta** (the dialog's own colours change), not an
   absolute — stamping `data-lguser-theme` alone photographs a light page wearing a
   dark attribute (#189), so tokens are applied inline from `app-settings.js`, and
   `lg-set-theme` is never written to the shared profile.
8. The modal's markup contains **no external host** — the text is genuinely offline.
9. **Reads the flag**, does not hardcode a state: flag OFF ⇒ the route 404s.

Measured as a **real member** — a PID-keyed `looth1` probe created and destroyed in
the run (#186's pattern; `qa-disposable` is an administrator), nonce taken from a
fresh render of the page under test.

Against **this branch**, via `tools/preview/mu-mirror.sh` +
`mu-mirror-boot.php` — the serve carries main, so a gate pointed at `/compose/`
measures main. The swap is asserted with `ReflectionFunction`.

## 8 · A URL Ian can click

`platform/nginx/lane-preview-191-licence-modal.conf`, copied from
`lane-preview-189-form-uploader.conf` (the mu-mirror variant — the older
`lane-preview-frontend-compose.conf` points at the serve's `index.php` and would
render **main**). Installed with `tools/preview/lane-preview.sh`.

    https://dev2.loothgroup.com/preview/191-licence-modal/compose/?type=loothprint

---

## Files I expect to touch

**Changed**
- `platform/mu-plugins/lg-frontend-compose.php` — the licence table, the choice
  override, the legacy map, the pared hint, the ⓘ, the dialog, CSS, JS
- `docs/domains/PAGE.md` — the domain rule (see the note below)
- `docs/CRAFT-STANDARD.md` — the gate table row, once keeper mints the number
- `tools/gates/run-all.sh` — ⚠️ **contended.** #172 deliberately did not touch it
  because other lanes held it. I will rebase-check before editing and, if it is
  held, hand keeper the block to insert rather than fight for the file.

**New**
- `platform/licences/*.txt` (4) + `README.md`
- `platform/nginx/lane-preview-191-licence-modal.conf`
- `tools/migrations/191-licence-label.php`
- `tools/gates/compose-licence-gate.py`, `tools/gates/compose-licence-redfirst.py`
- `handoffs/2026-08-21-191-licence-modal.md`
- `handoffs/plans/191-licence-modal-PLAN.md` (this plan, committed)

**Not touched**: ACF field `field_6564e26df56ba`, anything under `platform/config/`,
live, and any post other than the three named.

⚠️ **Overlap note for keeper**: `lg-frontend-compose.php` is the file lanes 185,
186 and 189 all worked. If any of them is still live, my edits are additive and in
distinct regions (`lg_fc_types` line 521, a new block in `lg_fc_relabel`, new
functions, CSS/JS appends) — but flag it before I push.

---

## Verification

1. **The count and the migration** — before/after text printed for each of the
   three, on dev2; a second run proves no-op; one rendered page for Ian.
2. **The served form as a real member** — the corrected option present, the
   contradictory string gone, the nudge gone.
3. **The modal follows the selection** — all four, in a real browser, on the
   branch preview, not on main.
4. **Keyboard**: Tab to the ⓘ, Enter opens, Escape closes, focus is back on the ⓘ.
5. **Both themes**, asserting the delta.
6. **Gates run individually** (`run-all.sh` exits early on main's gate-72 red,
   #175), plus the new gate's red-first harness.
7. **Flag OFF** proven a no-op.

## Reported, not fixed (unless Ian says otherwise)

- wp-admin's ACF field still offers the wrong label.
- Live's three posts stay wrong until Ian runs the handed command.
- The `page` label on #191 is the **seventh** in seven days that is not a
  lanes-page issue. PAGE.md carries six footnotes about this already; it needs a
  ruling, not a seventh.
