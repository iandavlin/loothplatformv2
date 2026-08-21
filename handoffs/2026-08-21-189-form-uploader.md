# 189 — the form gets its own uploader

Branch `189-form-uploader`. Issue #189. Gate **88, extended** (no number minted).

Ian, 2026-08-21: *"Would it be worth it to forsake the wordpress media pool and
put our on interface right on the form 1 in 1 out if over ?"* — offered the
deeper version (our own storage) and the interface-only version, he chose the
interface. Sharpened the same day, via keeper: *"It could all be handled in form
without having a modal opened right ?"* — **yes, and no Browse-existing either.**

---

## What it is, in one sentence

**A render swap over the existing pipeline.** Files are still ordinary WordPress
attachments, created by the same `media_handle_upload()` on the far side of the
same `bfu_chunker` endpoint, parented to the same post, carrying the same #186
stamp. Nothing here writes a file, a row or a URL by hand.

## The mechanism, and the one fact it turns on

`acf_render_field()` runs `acf_prepare_field()` **first** and then dispatches the
type variation from the **prepared** field (`acf-field-functions.php:800`).
Validation and save never go through `acf_prepare_field` at all — they load the
field through `acf/load_field`.

**So a type set at prepare time swaps the RENDERER and nothing else.**
`lg_fc_relabel()` — already the render-scoped `acf/prepare_field` filter, added
and removed around this one render — sets `gallery` → `lg_fc_photos` and `file` →
`lg_fc_printfile`, **by shape rather than by a name list**, and ACF's own
renderers never run.

Bracketed on a real WordPress before a line was written:

    swapped render    did_action('wp_enqueue_media') 0 → 0 , no .acf-gallery
    ACF's own render  did_action('wp_enqueue_media') 0 → 1 , .acf-gallery present
    validation saw type='gallery'   ·   update saw type='gallery'
    ids stored ['61698','61697'] — byte-identical to today

⚠️ **The second line matters as much as the first.** `enqueue_uploader()` carries
a once-only latch, so a baseline measured *after* the swap looks clean for free.
Ordering is what stops the assertion being vacuous.

---

## ⚠️⚠️ THE THING THAT OUTLIVES THIS ISSUE: ACF's front-end wysiwyg gets TinyMCE from the UPLOADER

This cost the most and is the most transferable.

`ACF_Assets::enqueue_uploader()` does three things behind one latch:
`wp_enqueue_media()`, register `print_uploader_scripts()`, and fire
`acf/enqueue_uploader`. Two of those are load-bearing for things that have
nothing to do with uploading:

- **`print_uploader_scripts()` prints a hidden `wp_editor('', 'acf_content')`,
  and that is the ONLY thing that brings TinyMCE to a front-end ACF form.** ACF's
  wysiwyg field does **not** call `wp_editor()` for the field — it hand-renders a
  bare textarea and clones the hidden editor's settings client-side.
- **`acf/enqueue_uploader` is where `acf.data.toolbars` is localized**, including
  our `lgfc_light` toolbar (`class-acf-field-wysiwyg.php:51`).

So arming the latch — the obvious way to stop `wp_enqueue_media()` — produces
**#185's exact defect** (a write-up rendered as a plain textarea with no toolbar)
by a completely different route. It shipped for about an hour and **a
26-assertion browser suite went green over it**, because the suite asserted
nothing about the editor. It was caught by *looking at the screenshot*.

Bisected through the nginx preview, one variant per row:

| variant | bytes | tmce-active | tinymce | media js |
|---|---|---|---|---|
| main | 254068 | 2 | 8 | 4 |
| door-closer removed | 272348 | 2 | 8 | 4 |
| `do_action` only, no latch | 254068 | 2 | 8 | 4 |
| **latch only** | 191494 | **0** | **0** | 0 |

**The seam is core's, not ACF's.** Let `enqueue_uploader()` run in full and take
back only what `wp_enqueue_media()` added — two script roots (`media-editor`,
`media-audiovideo`; everything else is on the page only as their dependency), two
styles, and the `wp_footer` templates action.

⚠️ **Timing is half the fix.** The wysiwyg enqueues during the **body**, long
after `wp_enqueue_scripts` has fired, so the ordinary dequeue hook is a dequeue of
nothing. `wp_footer:1` (before `wp_print_media_templates` at 10) and
`wp_print_footer_scripts:0` are the two moments that work.

Result, on the served page, signed in as a real member:

| | main | branch |
|---|---|---|
| page | 254,068 b | **200,288 b** |
| media-models / media-views / media-editor / plupload / moxie / wp-plupload | 7 | **0** |
| media templates in the footer | 1 | **0** |
| `class="acf-gallery"` | 9 | **0** |
| `tmce-active` · tinymce script | 2 · 1 | **2 · 1** |

**The modal is absent, not hidden.** `window.wp.media` is `undefined`; there is
no modal object on the page to open.

---

## The other bug worth carrying: a file field takes a SCALAR

My first build gave the print-file tile `name="acf[key][]"`, matching the gallery.
ACF's file `update_value()` runs the value through `acf_idval()`, and
**`acf_idval(['54773'])` looks for an `ID` key, finds none and returns `0`** — so
the field would have **saved empty while the tile on screen showed the file**.

Both controls now put the **empty sentinel first**:

- gallery — PHP promotes the scalar to an array when the first `[]` arrives, so
  an emptied strip still posts a value instead of dropping out of `$_POST`;
- print file — no promotion; the last `acf[key]` simply wins, so the tile's own
  scalar overrides the sentinel.

Both cases now fall out of ordinary form semantics with **no input enabled or
disabled by script**: a JS error can lose the *adding* of a file, never silently
blank one the member already had.

Gate 88 §I2 asserts this by feeding the rendered hidden inputs through
**`parse_str()` — PHP's own form decoder** — and reading the array that comes out.

---

## What a member gets

- **Drop-zone** wrapping a **real, visible `<input type="file">`**. One element
  covers drag-and-drop, a click target the size of the box, the no-drag fallback
  and the keyboard path, with no ARIA standing in for a control that isn't there.
- **Thumbnails** (WordPress's own 150px `thumbnail` derivative, drawn at 72px —
  never the member's original), each with a filename and a 28px remove button.
- **Progress** per file, with a bar and a percentage.
- **1 in, 1 out.** At ten photos a further file is not refused and not dropped:
  the strip becomes a set of choices and the member says which one leaves. Extra
  files queue and are offered one at a time; *Leave things as they are* cancels
  the lot. The print file has one slot, so a new one replaces the old outright —
  Ian's 2026-08-16 ruling that an edit may swap the file.
- **Removing is an unlink, with Undo.** Nothing is deleted at that moment;
  #186's stamped collector at publish is the only thing that deletes. Undo puts
  the **same attachment row** back with no upload, which is also why a
  removed-then-re-added file cannot be double-stamped.
- **Refusals name the number**, in the form's voice, before the bytes leave —
  and when the server refuses, its own sentence is shown verbatim.

## The wording/formatter split

The refusal **wording** lives in `lg_fc_size_refusal_template()` and travels to
the browser with `%s` still in it, so it cannot drift. Only the byte **formatter**
is mirrored in JS — and gate 88 §J **executes the shipped `mb()` in node** against
`lg_fc_mb()` over ten real byte values rather than trusting a comment.

---

## Verification

### As a real member, through the real chunker

A per-run `looth1` account (`qa-disposable` is an **administrator**; on #186's
feature that was the difference between 5MB and 5GB), with the nonce read from a
**fresh render of the page under test**.

| | result |
|---|---|
| small photo, 1 chunk | uploaded, `uploadedTo` = the composing post |
| 9MB photo, 3 chunks | uploaded, `uploadedTo` = the composing post |
| **12MB photo** | **refused on chunk 3 of 4** — *"That file is bigger than 10MB, so it can't go up here."* |
| 5MB zip, 2 chunks | uploaded, mime `application/zip`, `uploadedTo` = the composing post |

DB after: 3 attachments with `post_parent` = the composing post, **all stamped**,
images carrying **3 generated sizes** (`thumbnail`, `medium`, `medium_large` —
`large` does not apply to a 900px source). The refused file left **no attachment
row**, and the spool held **8 MB, not 12** — the guard refuses at the first chunk
that crosses the cap, so overshoot is bounded by the chunk size. That is why the
chunk is **4MB and not BFU's 20MB**: `wp-content/bfu-temp` is on the root disk,
measured at 4.6G free.

### In a real browser — `tools/preview/form-uploader-shots.py`, 31/31

Liveness first (a browser that lost the gate cookie gets a styled 403 that is
identical in both themes at every width). Then: the modal is absent; the file
input is really visible and takes focus; the editor booted, has a toolbar and is
not `html-active`; a photo uploads and becomes a tile; × unlinks and **the
attachment still exists on the server**; Undo restores **the same id**; ten
photos fill the strip; an eleventh offers a swap; taking it removes **exactly
one** and adds **exactly one**, and the removed file **still exists**; an
over-size file is refused, naming the number, in a `role="alert"` region.

⚠️ **Drag-and-drop is not synthesized**, and that is said rather than claimed.
CDP cannot fabricate a `DataTransfer` with real files. The drop handler and the
file input call the same `accept()` one line apart, so it is the same code path —
but only the input path is exercised.

### Gate 88 — extended, no number minted

**184 assertions green across both flag states** (was 110). New §I covers the
render swap, the decode-to-value-shape, the modal's absence from the **enqueue**,
the editor surviving, and the transport still being #186's. New §J executes the
shipped JS formatter in node.

Red-first: **9 mutations added** (M20–M28), four of which are defects this lane
actually made — the `[]` on the file field, the armed latch, the missing
sentinel, a second upload route.

⚠️ **M2's anchor had gone stale** and the harness reported it SKIPPED. A mutation
that no longer applies is a leg no longer under test and it reads exactly like a
leg that passed. Retargeted at the extracted template.

---

## ⚠️ Gate 88 §E was red on MAIN, for a legitimate reason, and would have blocked every lane

§E required **zero** stamped attachments outside the run's own fixtures. That was
true only until somebody used the form. Measured 2026-08-21: **eleven stamped
attachments on one member's in-progress auto-draft** (`mikelle.davlin`, post
73742), uploaded through the real compose form on the serve minutes earlier.
Nothing wrong — that is #186 working — but the gate called them *"files the
collector could delete"* and went RED.

**Restated, not loosened.** The property §E protects is that the collector can
only reach files this form created, on the post it created them for. So:

- a stamp must **agree with the attachment's `post_parent`**, and that parent
  must be a type this form composes — a stamp pointing anywhere else still fails;
- **no stamped attachment may predate the feature**, which is the half the 65
  historical leftovers on 36 published loothprints actually depend on.

`Z.teardown_clean` had the same shape and got the same treatment: it now counts
**this run's** rows, not every row on the box.

**And this lane leaked three of its own.** The transport test uploaded as a
member it never deleted. Cleaned up, and `form-uploader-shots.py`'s teardown is
now explicit and **asserted** rather than trusting `wp_delete_user` to cascade.

---

## Fixed here, but PRE-EXISTING on main

**The hero picker rendered visible with an empty strip on an empty form.**
`.lgfc .acf-fields>.acf-field{…display:block}` overrides the `hidden` attribute —
**an author `display` beats the UA's `[hidden]{display:none}` outright, whatever
the specificity.** The rule, the markup and the hero script are all unchanged by
this lane and main renders it the same way. Fixed because it is one line, in this
lane's own file, directly under the strip this lane rewrote, and in every picture
Ian is being given.

The same bug bit twice more in **my own** new CSS — the swap bar and the swap
overlay both carried `hidden` under an author `display`. Worth remembering as a
class, not as three incidents.

---

## Deleted rather than left inert

- `lg_fc_gallery_max_wording()` — a scoped `gettext` filter that reworded ACF's
  "Maximum selection reached" inside a picker that no longer renders.
- **69 lines of CSS** aimed at `.acf-gallery*` and `.acf-file-uploader*`.
  Two findings from those lines are kept in a tombstone because they are facts
  about this page rather than about the old markup: **this page loads no ACF
  stylesheet at all**, and **`:empty` does not match an element containing a
  whitespace text node**. The drop-zone wording those rules carried in
  `::before`/`::after` content is now real markup a screen reader reaches.

## Kept on purpose

`lg_fc_scope_library()` stays. It is an `acf/load_field` filter with **site-wide
reach for those field names**, and it is what keeps *wp-admin's* own picker
scoped to a post's library. Deleting it with the modal would have loosened
something outside this form. Boarded to keeper rather than quietly done.

---

## The preview — and the general answer to "the serve only carries merged code"

`tools/preview/mu-mirror.sh` + `tools/preview/mu-mirror-boot.php` +
`platform/nginx/lane-preview-189-form-uploader.conf`.

The existing `lane-preview-frontend-compose.conf` would **not** do: it points
`SCRIPT_FILENAME` at `/var/www/dev/index.php`, which loads the **serve's**
mu-plugins — it arms the flag but renders main's form. Gate 88 already knew how
to load a branch's mu-plugin under wp-cli (mirror the dir, swap one file, define
`WPMU_PLUGIN_DIR` first, because core sets it with `if ( ! defined(...) )`).
This makes that reusable **over HTTP, to a real browser**: nginx points
`SCRIPT_FILENAME` at a shim that defines the constant and then requires the
serve's own `index.php`. Real WordPress, real DB, real theme, one branch file,
**nothing on the serve modified**.

`LG_MU_MIRROR` is a `fastcgi_param` — only an nginx conf can set it, never a
query string or a visitor header — read from `$_SERVER` (it is not in `getenv()`,
a recorded box trap), and checked against a fixed prefix. The shim **fails loud**
rather than falling through to the serve's code, because a preview that quietly
served main is the whole failure it exists to prevent.

Repointing the one symlink in that mirror is also how main-vs-branch was compared
throughout this lane. It is the cheapest attribution tool on this box.

    bash tools/preview/mu-mirror.sh \
      /home/ubuntu/worktrees/189-form-uploader/platform/mu-plugins/lg-frontend-compose.php \
      /home/ubuntu/.lg-preview/189-form-uploader/mu
    tools/preview/lane-preview.sh up 189-form-uploader

    https://dev2.loothgroup.com/preview/189-form-uploader/compose/?type=loothprint

---

## Reported, NOT fixed

1. **The 5GB member upload limit against 4.6G free on root** (#186's finding,
   still open). This lane's 4MB chunk **reduces** compose overshoot from 20MB to
   4MB, but everything else on the box still spools against 4.6G. Wants a
   `bfu_temp_dir` move or a sane BFU `by_role` entry.
2. **The print-file field's declared `mime_types = zip` is still not enforced**,
   and 48 STLs are already stored. This lane deliberately did not tighten it —
   Ian's ruling is still outstanding. Our uploader sets `accept="image/*"` on the
   photo input only, which is a hint to the file picker and not a rule.
3. **No cancel on an upload in flight.** A 128MB print file is 32 requests and
   there is no way to stop it once started. `AbortController` would do it; it was
   left out to keep the build lean and is stated rather than omitted silently.
4. **The `page` label on #189 is wrong** — the fifth in five days, after #171,
   #179, #185 and #186. Recorded in `docs/domains/PAGE.md`. It needs a ruling,
   not a sixth footnote.
5. **`member_cookies()` in `loothprint-paywall-gate.py` still does not mint a
   member** (#186's finding). It mints `qa-disposable`, an administrator.
