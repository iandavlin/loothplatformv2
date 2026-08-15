# Backlog 30 — historical media orphan audit (PHASE 1: classification only)

**Nothing has been deleted, changed or moved.** This is a classified list for
Ian to rule on. Every number below was measured on **dev2** — see §6.

## 1. The short version

Of **7,397** attachments on dev2, **4,879 have no parent post**. Parentless is
*not* the same as unused: files can be embedded in content without ever being
attached to it. After scanning everything that could hold a reference:

| | count | size if deleted | what it means |
|---|---|---|---|
| **Dead** | **1,125** | **0.95 GB** | no reference found in any source listed in §3 |
| **Referenced** | 3,672 | 2.11 GB | in use somewhere despite having no parent |
| **Uncertain** | 82 | 0.02 GB | needs a human look — see §4 |

"Size if deleted" counts the original **and** its WP-generated size variants,
since removing an attachment removes all of them: 6,298 objects for the dead set.

**Most of the dead set is small, and most of its bytes are four videos.**
`nut-making.mp4` (147 MB), two copies of `3D-Clamp-Feet` (132 MB each) and
`Loothsaber-Chisel.mp4` (110 MB) are 55% of the 0.95 GB between them. The other
1,121 files come to about 430 MB. If the goal is reclaiming space, those four
are the decision; if the goal is tidying the library, they are a rounding error.

Dead by area: 2025 (438 files / 378 MB), 2024 (276 / 127 MB), 2026 (124 / 21 MB),
`fea-submissions` (108 / 72 MB), 2023 (102 / 352 MB), `bb_medias` (52 / 15 MB),
`wpforo` (13), `elementor` (5).

## 2. The single most important caveat

**"Dead" means "no mention found in the places I looked."** It does not mean
"provably unused". So the honest way to read the table is alongside §3, which
lists exactly what was searched — and the first version of this audit proves
why that matters: scanning WordPress alone produced **4,162 dead**, and adding
the other sources rescued **143 files that were genuinely in use**. 116 of them
were referenced *only* in the materialised article blobs, which is not a
WordPress table at all.

The matcher is deliberately **generous**: any mention of a filename anywhere
counts as a reference. For a list that feeds a deletion decision, a false
"referenced" costs nothing — the file is kept — while a false "dead" loses
member content.

### The correction that matters most

An earlier version of this audit said **4,013 dead**. It was wrong by 2,888
files, and the reason is worth keeping: **BuddyBoss does not use `post_parent`.**
Member uploads — forum and activity images, documents — are tracked in
`wp_bp_media` and `wp_bp_document`, keyed on `attachment_id`. There are 3,021
such rows. Those files are parentless *by design*, and acting on the earlier
number would have deleted roughly 2,900 member uploads: other people's photos of
their own repair work.

It surfaced because the size pass claimed 3,095 dead files had no object in the
bucket at all. That was a tidy story — dead rows whose files were long gone — and
it was false: spot-checking six of them found **all six present**, under
`bb_medias/` prefixes the directory enumeration had never covered. The lesson is
not "check BuddyBoss"; it is that a satisfying explanation is the most dangerous
moment in an audit.

## 3. What was searched

WordPress: `post_content` and `post_excerpt` of every non-attachment post (all
statuses, so drafts and trashed content count), all `postmeta` belonging to
non-attachment posts (this is where the layout-v2 blocks live, PHP-serialised),
`options`, `usermeta`, `comments`, `_thumbnail_id` (featured images), plus the
BuddyBoss tables that are *not* posts — activity and its meta, private messages,
xprofile fields, group descriptions and meta, reactions — and, decisively,
**`wp_bp_media` and `wp_bp_document`**, which own member uploads by
`attachment_id` rather than by parent.

Postgres: `discovery.article_blobs` (**the standalone article/video/sponsor
pages render from here, not from WordPress**), `discovery.content_item`,
`discovery.comments`, and every table in the `profile_app` database, which is
where avatars, profile highlights and sponsor imagery live.

**Attachment-owned rows were excluded on purpose.** An attachment carries its
own filename in `guid`, `_wp_attached_file` and `_wp_attachment_metadata`
(which also lists every generated size), so a naive scan finds every file
referencing *itself* and cheerfully reports that all 4,879 are in use.

### Where the referenced ones are referenced

`buddyboss-media` 3,003 · `article-blobs` 556 · `wordpress` 497 ·
`featured-image` 175 · `buddyboss/profile-app` 81. (A file can appear in more
than one.)

## 4. What "uncertain" means

Mostly **shared basenames**: two or more parentless uploads normalise to the
same filename, so a text match proves *something* is referenced but not *which
one*. Also included: attachments with no `_wp_attached_file` at all, so there is
no filename to match on.

## 5. The two 404 thumbnails — a different problem entirely

`seth-thumb.jpg` and `vb.jpg` are **not orphaned media**. They are dead URLs
baked into cached HTML: each appears 42 times in `article_blobs` inside related-
post carousel entries, and **nowhere else** — no attachment row, nothing in
`wp_posts` or `wp_postmeta`, and no object in the bucket.

What happened: the blobs were materialised on **2026-07-22** carrying the then-
current featured image. On **2026-07-26** a replacement image was uploaded
(`seth-hero.jpg`, attachment 72180) and set as featured; the old file was
removed. The 42 blobs were never re-materialised, so they still serve the dead
URL. `seth-hero.jpg` is present, and 10 other blobs cite it correctly.

**The general fault, not the two files:** the post's `post_modified` is
2026-07-19 — *earlier* than both events. Swapping a featured image writes
`_thumbnail_id` postmeta and does **not** bump `post_modified`, so anything
deciding staleness from it never notices. The same class already bit the forum
mirror. Two 404s are the visible symptom; the invisible one is that *any*
featured-image swap can leave stale blobs behind.

**Suggested action:** re-materialise those blobs, and decide separately whether
meta-only changes should mark a blob stale. Re-uploading two files would paper
over it.

## 6. Where these numbers come from, and where they do not

Everything here is **dev2** — its database and its bucket
(`loothgroup-uploads-dev`). Ian will presumably be ruling on **live**, which is
a different database and a different bucket. The corpora should be close, since
dev2 is built from live's image, but they are not the same thing and this audit
has not been run against live. Say the word and it can be, read-only.

Two measurement notes:

- **The uploads are not on local disk.** They are a Cloudflare R2 bucket mounted
  over rclone FUSE, so `du` reports **0 bytes** and `find -type f` returns
  **zero files** while `ls` works fine. Any size figure produced with those
  tools would be confidently wrong. Sizes come from `rclone` against the bucket.
- **The bucket also holds `_rzcache/`**, the image-resizer cache behind
  `/img.php?w=`. That is derived data which regenerates itself, and it is
  excluded from the figures — counting it as orphaned media would have inflated
  them.

## 7. The list itself

`final_sized.tsv` in the lane's scratchpad carries one row per parentless
attachment: id, path, mime, upload date, class, which sources reference it, the
bytes it would free and how many objects that is. It is a spreadsheet, not a
verdict — the classes are mine, the ruling is Ian's.

## 8. What I would check before deleting anything

1. **Run this against live.** Every figure here is dev2. The corpora should be
   close, but "should be" is not a basis for deleting member content.
2. **The four videos are the whole space story.** Confirm those individually —
   they are 55% of the recoverable bytes, and two of them look like the same
   clip uploaded twice (`3D-Clamp-Feet.mp4` and `3D-Clamp-Feet-1.mp4`).
3. **Spot-check the dead list by eye.** 1,125 rows is small enough to skim, and
   a human recognises "that's someone's repair photo" faster than any scan.
4. Nineteen dead rows have no object in the bucket at all — deleting those frees
   nothing and only tidies the database. One of them
   (`woocommerce-placeholder.png`) sits at the bucket root, which this audit's
   path matching does not address; it is a plugin asset, not member content.