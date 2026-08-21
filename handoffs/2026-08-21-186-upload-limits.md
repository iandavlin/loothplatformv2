# 186 — compose uploads: limits, in-and-out cleanup, required write-up

Branch `186-upload-limits`. Issue #186. Gate **88**.
Ian, 2026-08-21: *"There is a library being generated which is going to lead to
orphans. Can we make limits, post only and in and out?"*, sharpened the same day
to *"Basically if it doesn't launch with the post, does it get deleted on
publish?"*, plus *"We also need to make the tiny mce needed for tell us about
it."* Size ruling: **64MB**, given after the measurements below.

---

## THE NEAR-MISS — read this before changing the collector

Keeper asked for this verbatim, and it is the part that outlives the issue.

**The approved plan would have shipped a data-loss bug wearing a green gate.**
It said "at publish, delete every attachment this post does not use", and it said
"used" was the union of an enumerated list of reference kinds: the photo gallery,
the ZIP field, `_thumbnail_id`, the layout blob, the body.

Before writing the deleting half, that scan was run **read-only over all 174 real
loothprints**. Two things came back.

**1. It wanted to delete 67 attachments across 36 HEALTHY PUBLISHED posts.**
Spot-checked, they are genuine leftovers — one post carries six superseded
FretSander zips, another seven unused webp images. But destroying them the moment
an author pressed Post, on work from months ago, with no undo, is not a cleanup.
That is why the shipped collector is **stamp-scoped** (below), and why it deletes
**zero** of them.

**2. The enumeration was incomplete, and the corpus proved it.**
`post_related_links_repeater_0_related_link_image` is a real reference kind that
nobody had listed. On post 52343 the only thing standing between attachment 54773
and deletion was the loose text leg. **A name list cannot be trusted, because the
next field added to this form is not in it.**

So `lg_fc_referenced_ids()` **names no fields**. It walks every one of the post's
meta values, unserializing as it goes, and treats any integer **VALUE** as a
reference. That covers the gallery, the print file, `_thumbnail_id`, every
repeater row, ACF's `featured_image` and anything added later — by shape rather
than by name.

⚠️ **VALUES ONLY, NEVER KEYS, NEVER STRING LENGTHS.** Real row from this box:
`a:6:{i:61697;s:5:"69502";…}` — **61697 is a key and 69502 is the value**, and
only one of those is a file the post uses. A regex over serialized text matches
both, and also reads the `5` in `s:5:` as an id. The first pass had exactly that
false positive and it looked like a bug in the gallery leg until it was read
properly.

**The legs, with what each is actually worth** — measured, so nothing reads as
load-bearing when it is not:

| leg | keeps | verdict |
|---|---|---|
| structural walk of meta VALUES | 587 of 661 | the workhorse |
| body + `_lg_layout_v2_rendered_html`, by filename stem and id | **7, and nothing else keeps them** | earns its place |
| `_lg_layout_v2` blob (`image_id`/`image_ids`/`featured_image_id`) | 0 unique | **currently REDUNDANT** — it fires on 167 of 170 posts and yields 547 ids, every one already known to leg one. Kept because the blob is hand-editable in the front-end editor and tomorrow's data need not look like today's. Said plainly rather than left to read as a safety net that is carrying weight. |

**Convergence:** three independently written scan designs agree — 65, 67 and 67
unused across the same 36 posts. One scan agreeing with itself would not have
been evidence.

**The bias is deliberate: over-preserve.** A false "used" costs disk. A false
"unused" destroys a member's file.

---

## THE SECOND THING THAT WOULD HAVE SHIPPED INERT

The approved plan enforced size and type by setting `max_size` / `mime_types` on
the ACF fields. **That mechanism does not run on this form.**

- ACF validates attachments from `wp_handle_upload_prefilter` alone
  (`includes/media.php:38`).
- This box runs **tuxedo-big-file-uploads**, whose chunker calls
  `media_handle_upload()` with `overrides['action'] = 'wp_handle_sideload'`.
- WordPress dispatches that filter **dynamically** as `"{$action}_prefilter"`
  (`wp-admin/includes/file.php`, `_wp_handle_upload`).
- So the hook that fires is **`wp_handle_sideload_prefilter`**, and ACF is not on
  it.

**Proof that needed no code reading:** the print-file field declares
`mime_types = zip` and currently holds **127 zips and 48 `.stl` files**. Forty-eight
files ACF says are impossible. That is what an inert validator looks like, and a
gate reading the field setting back would have been green over all of it.

**And the ceiling was not what anyone thought.** The chunker bypasses PHP's 64M
`upload_max_filesize` entirely (which is how a 128MB ZIP exists here), and its
`by_role` table lists none of the `looth1`–`looth4` or `bbp_participant` roles our
members hold — so `get_upload_limit()` falls through to its `all` bucket:
**5,242,880,000 bytes.** Members had a 5GB limit. 64MB is a real tightening, not a
formality. *This corrected a claim already made to Ian — that 64MB was the box's
physical ceiling. It is a choice, not a constraint, and he was told so.*

---

## What shipped

`platform/mu-plugins/lg-frontend-compose.php`

- **`lg_fc_limits()`** — 10 photos, 10MB each, 64MB per print file. One place.
- **`lg_fc_upload_prefilter()`** on **both** prefilters. Size only. Refusals name
  the number and the actual size.
- **⚠️ MIME IS DELIBERATELY NOT TIGHTENED.** Members upload bare STLs today (48 of
  them). Ian asked for a count and a size; refusing STLs would be this lane
  quietly removing something that works. Flagged for his ruling, gate asserts an
  STL still passes so a later change cannot do it by accident.
- **`lg_fc_advertised_upload_limit()`** on `upload_size_limit` — the page used to
  tell BuddyBoss 5MB, ACF 200MB and the chunker 5GB. Three numbers, none true.
- **`lg_fc_gallery_cap()`** — `max = 10` so the picker stops. **Not** the
  enforcement: ACF's gallery `validate_value()` checks `min` and never `max`
  (`class-acf-field-gallery.php:789-798`), so an eleven-photo submission would
  simply save. `lg_fc_validate_photo_count()` is the real limit.
- **`lg_fc_gallery_max_wording()`** — ACF's picker refusal is the literal string
  "Maximum selection reached", which names no number. Replaced for the render.
- **`lg_fc_stamp_upload()`** on `add_attachment` — the stamp.
- **`lg_fc_collect_unused()`** on `shutdown` after `acf/save_post`. Shutdown, not
  mid-save: lg-article-materializer writes `_lg_layout_v2` after the post is
  inserted, so a collector inside the save decides "unused" against meta that is
  not finished being written.
- **`lg_fc_delete_post_files()`** on `before_delete_post` — **permanent delete
  only**.
- **`lg_fc_validate_writeup()`** — required, and it strips tags first because
  **ACF's own required check passes on an empty TinyMCE**: the editor submits
  `<p></p>`, which is a non-empty string.

## THE TRASH RULING, stated plainly

**Permanent delete takes the files. Trashing does not.** The bin is a member's
undo; destroying files on the way in turns "restore" into a post with a dead
download and missing photos, with no way back. WordPress empties the trash itself
after `EMPTY_TRASH_DAYS`, and that fires `before_delete_post` — so the files DO
go, with a grace period instead of on a misclick. Ian said *"when the post
goes"*, and a post in the bin has not gone yet. Gate 88 §F asserts both halves.
**If Ian wants files gone the instant he trashes, it is one `add_action` line.**

## Numbers Ian should have

- **44** attachments whose parent post no longer exists (category A).
- **67** attachments on **36 published loothprints** that the post does not use
  (category B).
- **56 of 174** existing loothprints have an **empty write-up** — those authors
  will be asked for one the first time they open the form to edit. The ruling
  working as intended, but real people will meet it.
- `tools/frontend-compose/stray-sweep.php` reports both and **deletes nothing**.
  Two explicit env flags are required to apply, it is not scheduled, and it was
  **never run with them**.

## Gate 88

`tools/gates/compose-limits-gate.py` + `compose-limits-probe.php` +
`compose-limits-redfirst.py`. **73 assertions, green**, across both flag states.

It **asserts behaviour, never a setting** — see above for why the obvious
assertion is green on the broken build. It **reads the flag** rather than
hardcoding a state: the same build runs twice, and the OFF build must refuse
nothing, delete nothing and require nothing. That is free, because
`lg_fc_enabled()` resolves its config relative to the mu-plugin FILE and the
`enabled => true` override is a **gitignored `.local.php` that exists only in the
serving checkout** — the #185 trap turned into an asset. `LG_FC_PREVIEW=1` arms
the ON run, through `env`, because **sudo strips the environment**.

The branch is loaded by mirroring the mu-plugin dir with one file swapped and
pointing `WPMU_PLUGIN_DIR` at it; the swap is **asserted** with
`ReflectionFunction`, because a gate that cannot say which file it measured has
measured main. Nothing on the serve is modified. Fixtures are PID-keyed and §Z
asserts the teardown — a leaked stamped row would make the next run's §E blame
the feature.

**§E is the leg that protects other people's work:** it counts stamped
attachments outside the run's own fixtures and requires **zero**, so "legacy
files are unreachable" is re-checked every run rather than remembered.

⚠️ **The gate caught a defect on its first run — in the probe, not the feature.**
`wp_insert_attachment()` fires `add_attachment`, which is where the stamping hook
lives, so a file the probe meant to create *without* a stamp was born with one and
the "legacy file is unreachable" assertion failed for a reason that had nothing to
do with the collector. The unstamped case now clears the request context for the
insert. **A fixture must actually be in the state it claims.**

## Reported, NOT fixed

1. **`member_cookies()` does not mint a member.** `loothprint-paywall-gate.py`
   mints a session for `qa-disposable`, which is `administrator` +
   `bbp_keymaster` + `looth1`. Any gate copying it and calling the result "as a
   real member" measures the ADMIN path. On this feature that was 5MB vs 5GB.
2. **The print-file field's declared `mime_types = zip` is not enforced** and 48
   STLs are already stored. Ian's ruling needed on whether STLs are welcome
   (they appear to be, in practice).
3. **The `page` label on #186 is wrong** — the fourth in four days. Recorded in
   `docs/domains/PAGE.md`; it needs a ruling, not a fifth footnote.
