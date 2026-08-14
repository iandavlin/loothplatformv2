# Layout-v2 block coverage vs the compose/edit form — audit

Ian, 2026-08-14: *"If we don't have a block that can handle anything in the new
form, like the license, we need to spin up v2 to produce the block. We should have
a block for just about everything but the lane should check."*

Checked. **He is right that there is a gap, and right that the licence is one of
them.** This is the table before any block is built.

## Source of truth — settled first, as instructed

The docroot loads `/var/www/dev/wp-content/plugins/lg-layout-v2 ->
~/loothplatformv2-clean/lg-layout-v2`, and layout-v2 is **tracked in the monorepo**
(232 files). **No stop condition: v2 is monorepo work.**

⚠️ `~/projects/lg-layout-v2` also exists and **differs in 68 files**. It has no
`.git`, and every file checked is OLDER and SHORTER than the monorepo's
(post-header 06-16/488 lines vs 07-30/500; wysiwyg 05-23/24 vs 07-12/45). It is a
stale leftover, not a second source and not unlanded work — but it is a live trap:
editing there changes nothing and looks like it should.

## What the catalogue has

20 blocks: brand-gallery, brand-hero, callout, columns, contact-form, divider,
**download**, embed, event-header, featured-products, gallery, image, paywall,
post-footer, post-header, recent-posts, section-heading, transcript, whos-talking,
wysiwyg.

## Coverage — the loothprint form's 12 fields

"Shows on the page" = some block renders it today. "v2 can change it" = layout-v2's
own editor can edit it, via an inline-editable prop or a picker (read from each
block's `manifest.json` → `editor`, not guessed).

| # | Field the form collects | Shows on the page? | v2 can change it? | Note |
|---|---|---|---|---|
| 1 | Title | ✅ post-header, read live | ❌ | post-header's only inline prop is `tagline`. Fine — the form owns the title. |
| 2 | Description | ✅ wysiwyg `html` | ✅ inline + rich-text | |
| 3 | Featured image / hero | ✅ post-header, read live | ✅ image picker | |
| 4 | Photos | ✅ gallery `image_ids` | ✅ gallery picker | |
| 5 | **Print files (ZIP)** | ✅ callout:files `items[]` | ❌ | **GAP.** callout edits `title,body,attribution` — never `items`. |
| 6 | Video instructions | ✅ embed `url` | ✅ embed-url picker | |
| 7 | **Onshape / CAD link** | ✅ callout:links `items[]` | ❌ | **GAP**, same cause as 5. |
| 8 | **Tip jar** | ✅ callout:links `items[]` | ❌ | **GAP**, same cause as 5. |
| 9 | **Licence** | ✅ callout:note `body` | ⚠️ as free prose only | **GAP — Ian's example.** Nothing in v2 knows what a licence *is*; the four-choice list is not represented, so v2 can only retype the sentence. |
| 10 | **Type of Loothprint** | ❌ nothing renders it | ❌ | **GAP — no block at all.** |
| 11 | **Content Topic / area** | ❌ nothing renders it | ❌ | **GAP — no block at all.** |
| 12 | Commenting | n/a — not a page element | n/a | |

**Covered both ways: 4 of 12** (description, hero, photos, video).
**Shows but v2 cannot edit: 4** (print files, CAD, tip jar, licence-as-a-choice).
**Not on the page at all: 2** (both taxonomies).

## The finding worth acting on first

**There is already a `download` block, and the loothprint page does not use it.**
Its props are `file_id, url, label, title` — and `file_id` is an attachment id,
which is exactly what would let the page follow the member's ZIP instead of baking
a URL that goes stale. But: the synthesizer emits `callout` variant `files` with a
baked `url`, and the `download` block declares **no inline-editable props and no
picker**, so nobody can edit it either.

So the print-files gap is two small jobs, not one big one: point the layout at the
block that already exists, and give that block an editor affordance.

## What I would build, in order — NOT started, awaiting Ian

1. **Licence block** (his example). A real licence block with the four choices as
   choices, so the page shows the licence and v2 edits it as a *choice** rather than
   prose someone can mistype.
2. **Use `download` for print files**, with a file picker — closes gap 5 and stops
   the stale-ZIP defect in the same move.
3. **A links/items editor for callout** — closes 7 and 8 together.
4. **A taxonomy/chips block** for type + area — closes 10 and 11, and is the only
   one that is genuinely new ground.

Items 2–4 are ordinary block work. Item 1 is the one Ian named. None of it is
started: the order was to report the table first.
