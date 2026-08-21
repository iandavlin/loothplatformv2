# 186 — compose uploads: limits, in-and-out cleanup, required write-up

Branch `186-upload-limits`. Issue #186. Gate **88**.
Ian, 2026-08-21: *"There is a library being generated which is going to lead to
orphans. Can we make limits, post only and in and out?"*, sharpened the same day
to *"Basically if it doesn't launch with the post, does it get deleted on
publish?"*, plus *"We also need to make the tiny mce needed for tell us about
it."* Size ruling: **128MB per print file** — Ian, *"128 is fine"*, given after the
measurements below moved him off 64MB.

---

## THE NEAR-MISS — read this before changing the collector

Keeper asked for this verbatim, and it is the part that outlives the issue.

**The approved plan would have shipped a data-loss bug wearing a green gate.**
It said "at publish, delete every attachment this post does not use", and it said
"used" was the union of an enumerated list of reference kinds: the photo gallery,
the ZIP field, `_thumbnail_id`, the layout blob, the body.

Before writing the deleting half, that scan was run **read-only over all 174 real
loothprints**. Two things came back.

**1. It wanted to delete 65 attachments across 36 HEALTHY PUBLISHED posts.**
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

**Convergence:** four independently written scan designs agree to within two —
65, 67, 67, and the shipped tool's 65 — across the same 36 posts. One scan
agreeing with itself would not have been evidence. The spread is how loosely each
matched; the shipped tool is the most conservative, because it alone holds back a
file that is another post's lead image.

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
**5,242,880,000 bytes.** Members had a 5GB limit.

### ⚠️ 128MB IS A CHOICE, NOT A CEILING — and that distinction changed the number

Ian first held **64MB**, on keeper's working assumption, which I then justified to
him with the claim that FPM's 64M `upload_max_filesize` was the box's hard limit.
**That justification was wrong**, and I corrected it to him rather than letting a
number stand on a bad fact. The chunker walks straight past `php.ini`; the box is
not the constraint. Told that, he moved to **128MB** — *"128 is fine"* — the number
that fits every print file that exists.

The three facts that belong beside it, so the next person does not re-derive them:

| | |
|---|---|
| what members can upload **without** this cap | **5,242,880,000 bytes (5GB)** — BFU's `all` bucket, because no `looth*` role is in its `by_role` table |
| the real corpus | 174 print files · median **0.3MB** · p90 **4.7MB** · largest **128.4MB** |
| what 128MB refuses | **exactly one file** — that 128.4MB outlier, which could never have come through this form anyway |

So 128MB is a policy choice sitting between "every file anyone has ever made" and
"five gigabytes". It is one constant, `lg_fc_limits()`.

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

## ⚠️ WHERE THE REFUSAL FIRES, RELATIVE TO THE BYTES

`wp-content/uploads` is a **symlink to `/mnt/loothgroup-uploads-dev`**, an rclone
FUSE mount of Cloudflare R2. Member uploads never live on this box. But the
chunker's **spool does**: tuxedo-big-file-uploads accumulates parts in
`wp-content/bfu-temp/<blog>-<sha1(name)>.part`, and `wp-content` is on the root
filesystem — measured 2026-08-21 at **29G, 84% used, 4.6G free**.

| stage | where the bytes are | which of our checks has run |
|---|---|---|
| each chunk POSTs to `admin-ajax.php?action=bfu_chunker` | PHP temp (`/tmp`, **root disk**) | **`lg_fc_chunk_guard`, priority 1** — refuses here |
| BFU appends to the `.part` file | `wp-content/bfu-temp`, **root disk** | — |
| last chunk → `media_handle_upload()` | file fully assembled, **root disk** | **`lg_fc_upload_prefilter`** on `wp_handle_sideload_prefilter` |
| `move_uploaded_file` into `uploads/` | **R2** | (only if both passed) |

**So: not one byte of a refused upload ever reaches R2** — that was already true of
the prefilter alone. What the prefilter could *not* protect is the **spool**,
because it only runs on the last chunk, once the whole file is already on local
disk. `lg_fc_chunk_guard` refuses at the **first chunk that crosses the cap**, so
at most one chunk of overshoot touches the disk.

### ⚠️ A LIVE RISK THIS LANE DID NOT CREATE AND DOES NOT FULLY FIX

**5GB effective member limit, 4.6G free on root.** One member uploading one large
file can fill this box's root filesystem, today, through wp-admin or any other
form the chunker serves. The guard above covers **compose uploads only** — it is
scoped to our post types on purpose. Everything else still spools against 4.6G.
Reported as its own finding; it wants a `bfu_temp_dir` move or a sane `by_role`
entry, and that is not this lane's call.

Two smaller things found in the same read, neither fixed here:
- **BFU's `.part` path has no user or session in it** (`sha1($fileName)` only), so
  two members uploading files with the same name share one spool file and would
  interleave. That is why `lg_fc_chunk_guard` **does not unlink** the part on
  refusal — one member's refusal must not destroy another's upload. BFU reaps
  parts older than 24 hours.
- On chunk 0 the guard treats the accumulated size as **zero**, because BFU opens
  the part with `'wb'` and truncates. Reading the stale size would latch a refusal
  onto that filename for 24 hours — a member refused once could never upload *any*
  file of that name again, however small.

## THE TRASH RULING, stated plainly

**Permanent delete takes the files. Trashing does not.** The bin is a member's
undo; destroying files on the way in turns "restore" into a post with a dead
download and missing photos, with no way back. WordPress empties the trash itself
after `EMPTY_TRASH_DAYS`, and that fires `before_delete_post` — so the files DO
go, with a grace period instead of on a misclick. Ian said *"when the post
goes"*, and a post in the bin has not gone yet. Gate 88 §F asserts both halves.
**If Ian wants files gone the instant he trashes, it is one `add_action` line.**

## POST ONLY — the paths actually exercised, named

Measured as a **real `looth1` member** (a per-run account, deleted after — *not*
`qa-disposable`, which is an administrator), through the real `bfu_chunker`
endpoint on `admin-ajax.php`, with the nonce read out of a fresh render of the
very form under test:

| path | result |
|---|---|
| photo → gallery field (`field_6547dafd3f5d6`) | **uploaded, `post_parent` = the composing post** ✓ |
| ZIP → print-file field (`field_6547dc013f5d7`) | **uploaded, `post_parent` = the composing post**, mime `application/zip` ✓ |
| the media modal's browse tab | `library = uploadedTo` on **both** fields, read off the rendered form as that member ✓ |
| the write-up editor | `media_upload = 0`; no `insert-media` / `wp-media-buttons` markup in the field ✓ |
| **drag-and-drop** | **NOT separately exercised.** plupload's drop handler posts to the same URL with the same `multipart_params` — both come from the one `_wpPluploadSettings` blob — so it is the same code path, but this is stated rather than claimed as tested. |
| **ZIP replace** | the upload half is the ZIP row above; the delete-the-old half is gate 88 §D. |

## Numbers Ian should have

- **Category A — 44** attachments whose parent post no longer exists. ⚠️ **Only
  39 are safe: 5 are still embedded in another live page**, and the sweep holds
  them back. A missing parent is not proof a file is unused, and that check is
  the difference between a cleanup and five broken articles.
- **Category B — 65** attachments on **36 published loothprints** that the post
  does not use. *(Earlier ad-hoc scans in this lane reported 65–67. The shipped
  tool's number is the authoritative one and is the most conservative by
  construction: it carries a cross-post lead-image guard the throwaway scans did
  not, so it can only ever preserve more.)*
- **56 of 174** existing loothprints have an **empty write-up** — those authors
  will be asked for one the first time they open the form to edit. The ruling
  working as intended, but real people will meet it.
- `tools/frontend-compose/stray-sweep.php` reports both and **deletes nothing**.
  Two explicit env flags are required to apply, it is not scheduled, and it was
  **never run with them**.
  ⚠️ **Run it on a quiet box.** It reports LIVE state: the first real run picked
  up `lg186 probe 391680on`, a PID-keyed fixture belonging to gate 88, which was
  running at that moment, and counted its two files among the strays. Nothing was
  at risk — dry run, two flags, and a human — but a count taken mid-gate is a
  count of someone else's temporary rows too.

## Gate 88

`tools/gates/compose-limits-gate.py` + `compose-limits-probe.php` +
`compose-limits-redfirst.py`. **110 assertions, green**, across both flag states.

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

**Red-first: 19 mutations + 2 no-op controls.** `M3` is the original defect
exactly — register only ACF's hook — and the gate must go red on it.

⚠️ **`M6` STAYED GREEN on the first pass, and that was the most useful result of
the whole run.** It makes the reference walk read array **keys** as well as
values — the precise trap the code carries a warning about — and the gate did not
notice, because the fixture was keyed `0,1,2` and so could not tell the two walks
apart. **The warning was documented but not under test.** The gallery fixture now
uses the real shape from post 61698 (`[unused_id => gallery_id]`), and `M6`
bites. A red-first that finds nothing has usually found something about itself.

### ⚠️ Two legs exist because the obvious assertion is TAUTOLOGICAL

§B and §C call `apply_filters()` with the hook name themselves. That proves the
**callback** refuses when it is called. It proves **nothing** about whether ACF
ever calls it — a filter on a mistyped hook, or one that bails on a condition only
absent in a real dispatch, passes every one of those assertions and refuses
nothing when a member presses Post.

- **§C2** drives ACF's own dispatcher with a real `$_POST` payload
  (`acf_validate_save_post()` → `acf_validate_values($_POST['acf'])`).
- **§D2** asserts the collection is actually **queued by `acf/save_post`**, and
  queued **once** per post however many times the save fires. It counts the
  `shutdown` callbacks either side rather than firing `shutdown`, which inside a
  probe would run every other plugin's handler early.

`M13`–`M15` in the red-first are the mutations only these two can see. `M13` is
the realistic one: make the write-up validator bail unless `$field['required']`
is set. §C passes (its fixture sets `required`); §C2 goes red, because
`required` is set at **render** time via `acf/prepare_field`, and that hook does
not run during validation. That is a bug a careful developer would plausibly
write, and only §C2 catches it.

### ⚠️⚠️ THE GATE REPORTED GREEN ON A RUN IT HAD NOT FINISHED

The most transferable thing this lane produced, and it was nearly missed.

Adding §H cut the flag-ON run from **56 assertions to 26**, and the gate said
**GREEN**. Nothing failed — because nothing ran. **A probe that dies part way
emits fewer PASSes and ZERO FAILs, and that scores exactly like a clean pass.**
The only reason it was caught is that the assertion count moved and I happened to
read it.

The cause is worth knowing on its own: **`wp_send_json()` ends in a bare `die`
unless `wp_doing_ajax()` is true.** Filtering `wp_die_handler` /
`wp_die_ajax_handler` / `wp_die_json_handler` does nothing about it, because that
path never calls `wp_die()` at all. Arming `wp_doing_ajax` sends it down the
`wp_die()` route where a handler filter can reach it.

**The fix is structural, not a patch to that one line.** The probe now ends with a
`Z.end` sentinel that asserts nothing about the feature — only that the file ran
to its last line — and the gate **refuses to score** a run that does not reach it:
`CANNOT RUN`, exit 2, never a verdict. Proved by truncating the probe on purpose:
30 assertions, zero failures, and the gate refused to score it.

**Any gate whose probe emits a stream of assertions has this hole.** Counting
PASSes is not the same as knowing the run completed, and "nothing failed" must
never be allowed to read like "everything passed".

⚠️ **The gate caught a defect on its first run — in the probe, not the feature.**
`wp_insert_attachment()` fires `add_attachment`, which is where the stamping hook
lives, so a file the probe meant to create *without* a stamp was born with one and
the "legacy file is unreachable" assertion failed for a reason that had nothing to
do with the collector. The unstamped case now clears the request context for the
insert. **A fixture must actually be in the state it claims.**

## Reported, NOT fixed

0. **⚠️ A 5GB member upload limit against 4.6G free on root** — see *A live risk
   this lane did not create* above. The single most consequential thing found
   here, and it is not this lane's to fix.

1. **`member_cookies()` does not mint a member — copy the pattern below instead.**
   `loothprint-paywall-gate.py` mints a session for `qa-disposable`, which is
   `administrator` + `bbp_keymaster` + `looth1`. Any gate copying it and calling
   the result "as a real member" measures the ADMIN path. On this feature that was
   the difference between 5MB and 5GB.

   **THE PATTERN THE NEXT LANE SHOULD COPY** — a PID-keyed `looth1` probe, created
   and destroyed inside the run (`feedback-gate-probe-must-be-per-run`):

   ```php
   $u = wp_insert_user([
       'user_login' => 'lg186probe-' . $TAG,          // $TAG = getenv('LG186_TAG'), the PID
       'user_pass'  => wp_generate_password(24),
       'user_email' => 'lg186probe-' . $TAG . '@example.invalid',
       'role'       => 'looth1',                      // a REAL member role, not administrator
   ]);
   // … the run …
   require_once ABSPATH . 'wp-admin/includes/user.php';
   wp_delete_user($u);
   ```

   Real member roles on this box, by headcount: `bbp_participant` (1,736),
   `looth3` (711), `looth1` (503), `looth2` (473), `subscriber` (177),
   `looth4` (15). `administrator` is 8. For an HTTP probe, mint the cookie from
   that user and take the **nonce out of a fresh render of the page under test** —
   a CLI-minted nonce carries a different session token and returns a bare `-1`
   that reads exactly like "the member is not allowed to upload".
2. **The print-file field's declared `mime_types = zip` is not enforced** and 48
   STLs are already stored. Ian's ruling needed on whether STLs are welcome
   (they appear to be, in practice).
3. **`compose-richtext-gate.py` CANNOT RUN on this box, and it is NOT mine.**
   It dies with `could not run the shipped functions inside WordPress: PHP
   Warning: Constant DISABLE_WP_CRON already defined …` — a wp-cli warning it
   treats as fatal output. **Attributed properly rather than assumed**: I
   snapshotted my file, put `origin/main`'s copy of `lg-frontend-compose.php` in
   its place, re-ran, got the identical CANNOT RUN and exit 2, and restored
   byte-for-byte. It fails the same way on main. It matters because a CANNOT RUN
   is exit 2, which makes `run-all.sh` report GATES INCOMPLETE — not red, but not
   green either.
   *(`compose-gate.py` also exits 2, but only because it requires `--allowed` /
   `--denied` or `--baseline`; that is its documented usage, not a failure.
   `compose-media-gate.py` — the one that actually covers this lane's territory,
   "no orphans, and each post keeps its own library" — is **GREEN**.)*

4. **The `page` label on #186 is wrong** — the fourth in four days. Recorded in
   `docs/domains/PAGE.md`; it needs a ruling, not a fifth footnote.
