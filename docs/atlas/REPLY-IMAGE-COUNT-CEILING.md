# Reply image count — where the ceiling actually lives

*`reply-images-count` lane, 2026-07-27, audited from dev2 at main `8bef903`.
Written so the next person does not re-derive this. Three lanes have now gone
looking for this limit (`reply-images` @`26be43a`, `reply-images-6` @`af8eb89`,
this one); all three found the same thing in a different order.*

Previs (behind the dev gate) — §9 is what shipped after Ian ruled max 6:
**https://dev2.loothgroup.com/mockups/reply-images/**

---

## 1. The one-line answer

**There is no upload limit. There is a render limit, and it is `LIMIT 1`.**

```
bb-mirror/web/forums/_topic-replies.php:47-54
    LEFT JOIN LATERAL (
      SELECT url FROM forums.attachment
       WHERE parent_kind = 'reply' AND parent_id = r.id
       ORDER BY id ASC LIMIT 1          <-- the entire limit
    ) reply_img ON true
```

One `reply_image_url` column → one `<img class="reply-stub__img">` in
`_reply-render.php:589`. Everything upstream of that — composer, endpoint,
BuddyBoss, PHP, nginx — will happily accept and store any number.

**This is a live defect, not just a missing feature.** Measured on the dev2
mirror (which is a faithful copy of live):

| | |
|---|---|
| published replies | 5,118 |
| replies carrying ≥1 photo | 504 |
| replies carrying **>1** photo — showing only the first | **229** |
| images stored but never rendered | **367** |
| most photos on one published reply | **5** |

229 replies are silently hiding images their authors successfully uploaded, with
no "+N more" and no indication anything is missing.

> Counting note: `forums.attachment` alone reports 506 parents and a maximum of
> 11, which is what a first pass finds. Two of those parents are **orphans** —
> their `forums.reply` rows no longer exist (synthetic leftovers from the
> `reply-images-6` lane). Join to `forums.reply … status='publish'` and the real
> figures are the ones above. It matters: the orphans are the only thing that
> ever made the cap look like it would truncate live content.

---

## 2. The ceiling table

Checked layer by layer. Only the last one binds.

| Layer | Where | Value | Binds? |
|---|---|---|---|
| Composer — file input | `hub-polish.js:3662` (`#lrs-comp-file`), `:4179` (`#lgc-file`), `forums.js:160` (`lgComposerTray`) | no `multiple`; takes `files[0]`; **no count check anywhere** | No — but 1 tap per photo |
| Composer — pending array | `lrsMediaIds`, `lcpMediaIds`, `frmTray` | **uncapped** | No |
| Write endpoint | `bb-mirror/api/v0/reply.php:229-262, :288` | `media_ids[]` / `keep_media_ids[]`, **no count check** | No |
| BuddyBoss | `bp_media_allowed_per_batch` = **3** | reaches only Dropzone `maxFiles` in the *native* theme (`bp-nouveau/includes/media/functions.php:141`) + a REST settings read-out (`class-bp-rest-settings-endpoint.php:1338`). **Zero server-side enforcement** — grep for `bp_media_allowed_upload_media_per_batch` returns settings UI, telemetry and that Dropzone config, nothing on a save path | No — advisory |
| BuddyBoss per-file size | `bp_media_allowed_size` = 5 | 5 MB per file | No (per file) |
| PHP | pool `looth-dev` (`/etc/php/8.3/fpm/pool.d/looth-dev.conf`) | `upload_max_filesize` 64M, `post_max_size` 64M, `memory_limit` 512M; `max_file_uploads` 20 (php.ini) | No — **uploads are one file per request**, so the 20 never applies |
| nginx | `platform/nginx/dev2.loothgroup.com.conf:41` | `client_max_body_size 8000M` | No |
| **RENDER — hub replies list** | **`_topic-replies.php:53`** | **`ORDER BY id ASC LIMIT 1`** | **YES** |

### Traps this table closes

- **`max_file_uploads` is a red herring here.** It is the classic silent-truncation
  trap, but it caps files *per request* and every composer uploads **one file per
  POST** into a pending array (`hub-polish.js:3745`, `:4292`; `forums.js:163`).
  Twenty photos is twenty requests of one file each.
- **BuddyBoss's cap of 3 is not a cap.** It is a Dropzone hint for a theme we do
  not use. This is why 10- and 11-image replies exist in the store.
- **`grep -c` counts lines.** The per-batch grep looks like enforcement until you
  read the six call sites; none of them is a write path.

---

## 3. Every surface that renders a reply, and what it does with N images

| Surface | File | Behaviour at N | Correct? |
|---|---|---|---|
| Hub replies sheet / discussion modal | `_topic-replies.php` → `_reply-render.php:581` | **1 image**, fetched at `w=800` via `lg_cover_src()` default, **no `srcset`, no `width`/`height`** | **No** — caps at 1 *and* violates the image craft rule |
| Single-topic page | `_single-topic.php:256` `render_attachments()` | **all N**, `srcset` 240/480/800 + `sizes` + `width`/`height` + `loading=lazy` + lightbox; desktop wrap-grid, **mobile swipe carousel** (`forums.css:3537`) | **Yes — this is the reference implementation** |
| Feed card teaser | `_feed.php:888-899` | 1, deliberately, deferred behind "Show image" | Yes — a teaser should stay at 1 |
| Feed card cover fallback | `_feed.php:560-570` | 1 reply image promoted as the card cover when the topic has none | Yes |
| Weekly digest / email | `lg-weekly-digest/templates/sections/forum.php:16-21` | **at most one** 240px thumb, sourced from the featured image or the first `<img>` in `post_content`. Reply photos are `bp_media` attachments and are **not** in `post_content` — they never reach the email at all | **Structurally immune** to the count |

**The good pattern already exists 200 lines from the code that caps at one.**
Any build here should lift `render_attachments()`'s contract rather than invent one.

---

## 4. What actually breaks above 1 — measured, not predicted

Measured in a real browser at 390×844 DPR 2 (phone) and 820px (desktop), against
the real `/hub/forums.css` and real photos through the real `/img.php`.
Harness: `footer-mockups/reply-images/{gen.py,shoot.py}`.

> These are the **exploration** numbers, taken against hand-written previs markup
> before the ruling, and they include counts (9, 20) that are now out of scope.
> They are kept because they are what the decision was made from. For what the
> shipped code actually measures, see §9.

### Height — this is the whole design question

| Reply | Phone stub height | Desktop |
|---|---|---|
| Today, 1 image | 354 px | — |
| 2 images | 261 px | — |
| 3 images | 247 px | — |
| 5 images | 369 px | — |
| 9 images, display-capped at 6 + "+3" | **355 px** | 578 px |
| 20 images, display-capped at 6 + "+14" | **355 px** | 578 px |
| 9 images, **uncapped** | — | 808 px |
| 20 images, **uncapped** | — | **1,729 px** |

**A display cap makes height independent of count.** With one, a 20-photo reply is
355 px — one pixel taller than the single-image reply the hub renders today.
Without one, a single 20-photo reply is 1,729 px on desktop and buries the other
four replies on the page.

### Weight

Real photos through `/img.php` (WebP out): **11.6 KB** at w=240, **35.8 KB** at
w=480, **79.0 KB** at w=800, vs ~187 KB for the raw JPEG.

The payload unit is **not one reply** — `_topic-replies.php:110` paginates
`$PER = 5`, so a "Load 5 more" tap fetches five stubs at once.

| Scenario | Tiles per page of 5 | Bytes if all decode |
|---|---|---|
| Today — 1 per reply at `w=800` | 5 | 395 KB |
| 6 tiles at `w=240` (DPR 1) | 30 | 348 KB — *less than today* |
| 6 tiles at `w=480` (DPR 2–3) | 30 | 1.07 MB |
| 20 uncapped, DPR 2–3 | 100 | 3.6 MB |

Measured page height with all five replies carrying photos: **1,522 px** phone,
**2,455 px** desktop. With `loading=lazy` + intrinsic `width`/`height` on every
tile, only about the first two replies decode on a phone screen — real first paint
for a display-capped grid is nearer **430 KB**.

**The hub over-fetches today.** The single reply image is pulled at `w=800`
(79 KB) into a box CSS limits to `max-height:240px`, with no `srcset` and no
intrinsic dimensions — roughly 7× the bytes it needs, plus a layout shift.
Fixing that is worth doing whatever number gets picked.

### Upload UX

The concern that a higher count needs per-file progress, per-file failure handling
and per-file removal is **already answered — in composer v2 only**.
`lgcUploadPhoto()` (`hub-polish.js:4300-4380`) already implements an optimistic
tile before the first byte leaves, per-file progress, per-file failure with a `↻`
retry, per-file removal, a supersede guard so a stale retry cannot land, and a
generation guard for the composer being reopened underneath it.

What it does **not** have: any count check, and `multiple` on `#lgc-file`. It is
only ever handed `files[0]`. The legacy `lrs` composer (`hub-polish.js:3745`) has
none of the per-file machinery.

**So the composer work is three small changes inside one existing function** —
provided it lands after `composer-p3` collapses the five create paths onto that
seam. See §6.

---

## 5. Prior art — do not rebuild this

Two lanes already built pieces of this and neither merged. Check them before
writing code.

| Branch | SHA | What is in it |
|---|---|---|
| `reply-images` | `26be43a` | earlier exploration |
| `reply-images-6` | `af8eb89` | **Phase 1, committed and verified green in a real serve window**: render up to 6 (`json_agg` replacing the `LIMIT 1`), `.reply-stub__gallery` CSS, server **edit** cap `LG_REPLY_MEDIA_MAX=6` in `reply.php` PUT, client guard in all three composers, plus the `bbp_new_reply` bell fix. Phase 2 (owned WebP endpoint + dual-read materializer) left uncommitted in that worktree. |

`reply-images-6` stalled on one thing: **the server CREATE cap has nowhere to
live**, because hub reply-create posts to native BuddyBoss REST, not to
`reply.php` (`reply.php` only handles edit/delete for the hub). That question is
dissolved by `composer-p3`, which deletes the native create paths (W1–W5) — after
p3, create goes through the owned endpoint and the cap has a home.

---

## 6. What this needs from `composer-p3`

Per `COMPOSER-V2-P3-INVENTORY.md`, phase 3 repoints E1–E6 at
`openComposerSheet()` and deletes native create paths W1–W5. Three asks, none of
which is a fork of the composer:

1. **A cap constant on the seam** — `openComposerSheet()` accepts or exposes a max
   attachment count, and `lgcUploadPhoto()` refuses past it using the error
   affordance it already has.
2. **A count read-out** in the strip ("6 of 10"), so the cap is visible before it
   is hit rather than as a rejection at the eleventh tap.
3. **`multiple` on `#lgc-file`**, with `lgcUploadPhoto()` called per file. The
   per-file machinery already exists; it is only handed one file.

Nothing is needed from p3 on the render path or the edit path.

---

## 7. THE RULING — **max 6** (Ian, keeper-relayed, 2026-07-27)

Recommendation accepted at the alternative: **6 attached, all 6 shown.** Built in
this lane; §9 is what shipped.

**Nothing existing is truncated by 6.** The most photos on any published reply is
**five**. The two rows in `forums.attachment` holding 10 and 11 are *orphans* —
their `forums.reply` rows no longer exist (leftovers from `reply-images-6`'s
synthetic tests). So the cap costs zero live content, and the `+N` affordance
exists only so that an over-cap reply is shown as truncated rather than dropped
silently the way `LIMIT 1` did.

Measured against published replies at build time:

| | |
|---|---|
| replies carrying images | 504 |
| unchanged (single image) | 275 |
| **replies that stop hiding images** | **229** |
| **images that become visible for the first time** | **367** |
| replies truncated by the cap | **0** |

---

## 8. Gotchas banked while measuring

- **`sizes` must track the layout, not the count.** The first cut of the previs
  declared a flat `sizes="33vw"`; the browser probe caught the 2-up and 4-up
  layouts (half-width tiles) pulling the `w=800` candidate — a 79 KB fetch for a
  160 px tile, the exact defect this doc accuses the current code of. Assert the
  *picked* candidate (`img.currentSrc`) in any gate, never just that a `srcset`
  attribute is present.
- **`/forums.css` is served at `/hub/forums.css`**, not at the docroot root.
- **Previs frames go to `/var/www/dev/mockups/<lane>/`** (an existing shared,
  group-writable dir; `wd-recap`, `mobile-nav`, `navshots` are neighbours). New
  subdirectories there are purely additive — no served file is mutated, no `/srv`
  symlink repointed, no fpm reload — so this does **not** need an overlay window.
  Directory listing is off; link the `index.html` by path.
- **The mirror DB is reachable read-only as `sudo -u postgres psql -d looth`**
  (peer auth over the unix socket; roles are `bb-mirror`, `looth-dev`,
  `profile-app`, `looth_ro`).
- **Headless chrome on dev2**: `--host-resolver-rules="MAP dev2.loothgroup.com
  127.0.0.1"` puts the browser on loopback, which the dev gate authorizes via
  `geo $loothdev_src_local` — no cookie needed, no trip through the CF edge.
  Launch + drive + kill in ONE bash call; the box is 3.8 GB at a 4-lane cap.

---

## 9. What shipped (lane `reply-images-count`, 2026-07-27)

Two pieces, as ruled: render what members already posted, and make 6 real.

### The single constant

`bb-mirror/config.php` → `LG_REPLY_IMG_MAX = 6`. Both the renderer and the write
endpoint `require` that file, so the number cannot drift between what is stored
and what is shown. `auth.php` ships it to the browser as `reply_image_max` so the
composer's guard is the same 6 the server enforces — with the conservative value
as the fallback when the field is absent (stale cached JS must land **on** the
cap, never on "no cap").

### Piece 1 — render (the live defect)

| File | Change |
|---|---|
| `web/forums/_topic-replies.php` | the `LIMIT 1` LATERAL becomes a `json_agg` of `{url,w,h}` ordered by `position, id` and sliced to the constant, plus an **uncapped** `total` so an over-cap reply can show `+N`. Ordering verified identical to the old `id ASC` across all 506 rows, so the first image — and the feed teaser that reuses it — is unchanged. |
| `web/forums/_reply-render.php` | accepts either the rich `reply_images` list or the legacy single `reply_image_url` (the feed teaser still sends one). 1 image → **byte-identical legacy markup**; 2-6 → `.reply-stub__gallery`; over-cap → 6 tiles + `+N`. Every tile carries `srcset` (240/480/800), `sizes`, intrinsic `width`/`height`, `loading=lazy`. |
| `web/forums.css` | `.reply-stub__gallery` — 6-column grid with span rules (2/4 half-width, 3/6 thirds, 5 = three thirds then two halves), so no count leaves an orphan gap. Tiles keep `.reply-stub__img`, so the existing lightbox handler needs no JS change. |

**`sizes` must track the span rule, not the count.** A flat `33vw` makes the
half-width 2-up and 4-up tiles pull the `w=800` candidate — 79 KB for a 160 px
tile, the exact over-fetch the gallery exists to end. `verify.sh` asserts both
directions (each layout declares its own rule and never the other one).

### Piece 2 — the cap at the door

| Where | Enforcement | Bites today? |
|---|---|---|
| `api/v0/reply.php` POST | 422 `too_many_media` **before** `rest_do_request` | **Yes** — composer v2 posts creates here |
| `api/v0/reply.php` PUT | 422 on the **resulting** set (kept + added) before `wp_update_post`, so an edit cannot walk a reply past the cap one photo at a time | **Yes** — all edits go here |
| composer v2 `lgcUploadPhoto` | refuses past the cap, disables the photo button, live "3 of 6" read-out | **Yes** |
| `lgComposerTray({max:6})` | same guard for the legacy desktop trays (frm reply modal, single-topic reply form) | Yes — **interim**, see below |

The composer counts **chips in the strip**, not `lcpMediaIds`: an upload in
flight, a failed-and-retryable one, and a photo kept on an edit each occupy a
slot exactly as the member sees them. Counting the ids array would miss all
three and let someone queue past the cap. Topic composing shares this composer
and is deliberately **not** capped — this is a reply cap.

**The one honest gap.** The legacy desktop reply composers still POST BuddyBoss
REST direct on create (inventory W1/W5), so `reply.php`'s 422 never sees them and
their client guard is all that holds the cap on that surface.
**`composer-p3` deletes exactly those paths** — once it lands, the server is the
backstop everywhere and `lgComposerTray`'s `max` is belt-and-braces. It was added
rather than deferred because without it the cap is cosmetic on desktop today.

### Verification

`footer-mockups/reply-images/verify.sh` — **36 assertions, green**, run through
the real renderer against the live mirror with no serve window
(`sudo -u postgres bash verify.sh`). It proves the single-image case is
**byte-identical** by rendering the same reply through the pre-change renderer
pulled straight out of git and diffing, checks cell counts and the full image
contract for 2-6, checks `sizes` both ways, checks the `+N`, and checks that both
server caps sit *before* the writes they guard.

`render-harness.php` is the reusable piece: it requires the shipping renderer and
runs the shipping query, so reply markup can be verified — and previewed — without
deploying anything.

**Not proven, and it needs a serve window plus a real device:** the 422 exercised
by an actual over-cap request, the composer guard and read-out driven by a real
finger, and the gallery on Ian's phone.

### Previs

https://dev2.loothgroup.com/mockups/reply-images/ — 1 through 6, the over-limit
case, real reply 58510, and a page of five, phone and desktop. Frames are the
**actual shipped output**: markup from `render-harness.php`, stylesheet is this
branch's `forums.css` copied whole.

Measured stub heights, 390 px phone at DPR 2: 1 image **332 px**, 2 **237**,
3 **223**, 4 **360**, 5 **345**, **6 → 331 px** — a six-photo reply is a pixel
shorter than the single-image reply the hub renders today.
