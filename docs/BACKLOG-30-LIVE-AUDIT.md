# Backlog 30 phase 2 — the same audit, against LIVE

**Read-only throughout. Nothing was executed on live.** The deletion Ian ruled
on is *prepared* in `backlog-30/live-delete-four-videos.sh` and handed over
unrun.

## 1. The decision, in one line

Of **4,973** parentless attachments on live, **1,125 are dead — 975 MB**. Four
video files are **522 MB of that, 54%**. Two of them are the same clip stored
twice.

| | count | size if deleted |
|---|---|---|
| **Dead** | 1,125 | **0.95 GB** |
| Referenced | 3,766 | 2.32 GB |
| Uncertain | 82 | 0.02 GB |

So this is still a four-file decision, not a 1,125-file one. The remaining 1,121
dead files come to about 453 MB between them — a long tail that costs more to
review than it frees.

## 2. Live is not dev2, and the agreement is the evidence

Live has **7,517** attachments and **4,973** parentless, against dev2's 7,397 and
4,879. The numbers were never going to transfer, which is why phase 1 refused to
present them as live figures.

But the *dead* set came out at **1,125 on both boxes** — an identical count that
was suspicious enough to check rather than report. Diffed path by path: **1,124
identical, exactly one differing each way**. Live has
`2026/07/tom-brantley-hero.webp`; dev2 has
`2026/08/2025-10-1-Guitar-Nut-Removal-Thumbnail.jpg`. Each was confirmed absent
from the other box's database. That is real content drift between near-identical
corpora — and two independently-run audits agreeing to within one file each way
is the strongest evidence available that the method is stable.

## 3. What was searched (same nine sources)

WordPress `looth_import` — confirmed live by `siteurl = https://loothgroup.com`,
because the `looth_dev` database sitting beside it reports 7,210/4,741, close
enough to the real numbers to pass a glance. Sources: post content and excerpt of
every non-attachment post, non-attachment postmeta, options, usermeta, comments,
`_thumbnail_id`, the BuddyBoss activity/messages/xprofile/group tables, and
**`wp_bp_media` + `wp_bp_document`**, which own member uploads by
`attachment_id` rather than by parent — 3,117 rows on live.

Postgres — `discovery.article_blobs` (what the standalone article/video/sponsor
pages actually render from), `discovery.content_item`, `discovery.comments`, and
every table in `profile_app`.

Referenced files break down as: `buddyboss-media` 3,101 · `content-scan` 775 ·
`featured-image` 173.

**Missing the BuddyBoss tables is what made phase 1 report 4,013 dead when the
real figure was 1,125.** 2,888 of those were live member forum and activity
uploads.

## 4. Where the dead bytes are

`2025` 438 files / 378 MB · `2023` 102 / 352 MB · `2024` 276 / 127 MB ·
`fea-submissions` 108 / 72 MB · `2026` 124 / 23 MB · `bb_medias` 52 / 15 MB ·
`wpforo` 13 / 3 MB · `elementor` 5.

2023 is only 102 files but 352 MB, because two of the four videos are there.

## 5. How the sizes were measured, and its limits

**Neither obvious route to the bucket worked.** `live-ro` has no rclone config,
and dev2's `r2live` remote returns 403 on both `ListBuckets` and `ListObjectsV2`
for the live bucket. What does work is reading the FUSE mount over ssh: `ls -l`
returns real byte sizes and is fast (0.007s per directory — the mount caches
directory metadata).

So sizes come from the mount, not from a bucket listing, and they cover **only
the 117 directories the parentless corpus uses** — 56,190 objects, 6.02 GB.
That is not the whole bucket and is not presented as such.

Spot-checked five dead files across different prefixes against live's own
directory listing: object counts matched exactly in all five.

## 6. The naming trap — three instances, all on live

The word **"dev" means nothing on the live box**:

- live's nginx access log is `dev.loothgroup.access.log`
- live's uploads mount is `/mnt/loothgroup-uploads-dev` — the bucket is `loothgroup2-0`
- live's WordPress docroot is `/var/www/dev`

Each reads as "this is the dev box"; each is live. Anyone deciding what is safe
to touch by pattern-matching on "dev" will be wrong three times, in the direction
that destroys member data. The prepared script confirms the target instead of
assuming it: `wp --path=$WP_PATH option get siteurl` must print
`https://loothgroup.com`.

## 7. The prepared deletion

`backlog-30/live-delete-four-videos.sh` — literal ids, **dry run by default**,
deletes nothing unless every guard passes. Guards re-prove at run time what the
audit concluded, because live moves: each id must still resolve to its expected
path, still be parentless, and still return zero across eight reference checks.

**The guard SQL has been run against live, read-only: all four pass.** What could
not be validated from here is the wp-cli half — `live-ro` cannot read
`/var/www/dev/wp-config.php` (0660), so `wp` fails for that user. Whoever runs it
has that access, and the dry run exercises it end to end.

Expected reclaim: **522.3 MB (547,620,221 bytes)**.

## 8. The list

`backlog-30/live-parentless-classified.tsv.gz` — one row per parentless
attachment: id, path, mime, date, class, which sources reference it, bytes and
object count. Committed rather than left in `/tmp`, because the evidence behind a
deletion ruling should outlast the seat that produced it.
