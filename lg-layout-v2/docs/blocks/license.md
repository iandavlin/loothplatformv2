# `license` block

The licence a member publishes their loothprint under, rendered as a **choice**
— the CC code, the clauses it actually imposes, and a link to the deed — rather
than as a sentence someone retyped.

Ian, 2026-08-14: *"If we don't have a block that can handle anything in the new
form, like the license, we need to spin up v2 to produce the block."*

## Purpose

One sentence: show which Creative Commons licence a post carries, in a form the
reader can act on and the author can change without typing prose.

Today `default_loothprint_layout()` drops the raw ACF string into a
`callout` variant `note` as `<p>…</p>`. That renders, but:

- v2 can only edit it as **free text** (`callout.body` is inline-editable), so
  the four choices are not choices — a typo silently becomes the licence.
- Nothing on the page knows what the licence *means*. `grep -rli licen[cs]e
  blocks/` returned nothing before this block: v2 had no concept of a licence.
- The prose is **baked into the stored layout**, so a member who changes the
  licence in the form keeps serving the old one on the page — the same
  stale-bake defect as the print-files ZIP.

## The data, as measured (not assumed)

`loothprint_creative_commons` is an ACF **radio** (`allow_null=0`,
`other_choice=0`) whose choice *keys are the prose itself*. Four choices, and on
dev2 every stored row matches one exactly — there is no free-text drift:

| Stored value | Posts | Code |
|---|---|---|
| `BY NC SA (Credit given to creator, Non-Commercial only, Adaptations shared with same terms)` | 251 | `by-nc-sa` *(ACF default)* |
| `BY ND NC (Credit given to creator, No Derivatives, Adaptations shared with same terms)` | 3 | `by-nc-nd` |
| `BY (Credit given to creator)` | 2 | `by` |
| `BY SA (Credit given to creator, Adaptations shared with same terms)` | 1 | `by-sa` |

⚠️ **The fourth choice's wording is self-contradictory and it is a form bug, not
a block bug.** `BY ND NC` promises *"No Derivatives"* **and** *"Adaptations
shared with same terms"* in the same sentence — ND forbids adaptations outright,
so there is nothing for a share-alike term to govern. The two clauses cannot
both hold. This block maps it to the real licence it names (`by-nc-nd`) and
renders **credit / non-commercial / no-derivatives**, dropping the impossible
share-alike clause. It does **not** rewrite the stored value. The wording should
be fixed in ACF; that is Ian's call and is reported, not taken.

Note also the code order is non-canonical in the source (`BY ND NC`, canonical
`BY-NC-ND`). Matching is order-insensitive on the CC element tokens, so both
spellings resolve.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `code` | enum | no | `""` | `by`, `by-sa`, `by-nc-sa`, `by-nc-nd`. **`""` = follow the post's licence meta live.** |
| `title` | string | no | `"License"` | Eyebrow above the row. Empty = no eyebrow. |
| `show_deed` | bool | no | `true` | Link the licence name to the canonical CC deed. |
| `variant` | enum | no | `note` | `note` (prose-weight, matches today's look) or `compact` (single row). |

### Why `code: ""` means "follow the post"

This is the whole point of the block, so it is a default and not an option.

`post-header` already reads title/hero/author **live** from the post, which is
why those never go stale. Everything else in a stored v2 layout is baked. A
synthesized loothprint page emits `{"type":"license"}` with no `code`, so the
page resolves the licence at render from `loothprint_creative_commons` — the
member changes it in the form and **the page follows**, with no re-synthesis and
no sync job.

Setting an explicit `code` (via the picker) is a deliberate override: it pins
the block to one licence and stops it tracking the post. That is the right
behaviour for a hand-built page that is not a loothprint, and the picker says so.

The resolution is guarded with `function_exists('get_post_meta')`, matching
post-header, so the standalone/no-WP render path degrades to "no licence" rather
than fataling.

## Visual reference

`note` variant (default — sits where today's licence callout sits):

```
┌──────────────────────────────────────────────────────┐
│ LICENSE                                              │
│                                                      │
│  (cc) Attribution–NonCommercial–ShareAlike 4.0    ↗  │
│                                                      │
│  ┌───────────────┐ ┌──────────────────┐ ┌──────────┐ │
│  │ ⓑ Credit      │ │ ⓝ Non-commercial │ │ ⓢ Share- │ │
│  │   required    │ │   use only       │ │   alike  │ │
│  └───────────────┘ └──────────────────┘ └──────────┘ │
└──────────────────────────────────────────────────────┘
```

`compact` variant — one line, for dense pages:

```
(cc) BY-NC-SA  ·  Credit required · Non-commercial · Share-alike        ↗
```

The clause chips are the point: they are what turns a sentence into a licence a
reader can scan. Each chip is one CC element, so a reader sees the obligations
without decoding an acronym.

## Variable contract

### Container

- `padding`, `margin-block` — block rhythm, matched to `callout` so the licence
  keeps its current place in the loothprint page's vertical flow.
- `bg`, `border-color`, `radius` — the aside's shell.

### Text

- `title-color` — the eyebrow.
- `accent-color` — the licence name + the deed link.
- `chip-bg`, `chip-color` — the clause chips. Declared separately because a chip
  must stay legible when the container `bg` is themed; folding them into
  `accent-color` is what would make a dark-theme chip vanish.

Defaults mirror the `callout` `note` variant (`bg #fdfaf0`, border `#ecb351`,
title `#b8842b`) so this block drops into the existing loothprint page without
moving the furniture.

## Editor affordances

- `insertable: true`.
- `inline_editable_props: ["title"]` — the eyebrow only. **The licence itself is
  deliberately NOT inline-editable**: making it contenteditable is exactly the
  "retype the prose" failure this block exists to end.
- `custom_picker: "license-choice"` — a four-option chooser plus a *Follow the
  post's licence* option that clears `code` back to `""`.

The picker is a new framework picker (`EditorPickers` + the FE editor's
`runCustomPicker`), not a per-block escape hatch, per the onboarding rule.

## Accessibility notes

- The block is an `<aside>` with `aria-label` naming the licence, so a screen
  reader reaches it as a labelled region rather than an unexplained `(cc)`.
- The deed link carries the full licence name in its accessible text — never
  "click here" or a bare glyph — and `rel="license noopener"`, which is the
  standard machine-readable signal for a licence link.
- Clause chips are plain text with a decorative glyph marked `aria-hidden`; the
  meaning lives in the words, so nothing depends on the icon rendering.
- Chip contrast is a declared var pair (`chip-bg` / `chip-color`) precisely so a
  theme change cannot silently drop it below contrast.

## Opt-outs

None. The block participates in normal column/gallery normalization and inherits
nothing special. `inherits_global: false`, matching `callout` — its own defaults
are the truth.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
