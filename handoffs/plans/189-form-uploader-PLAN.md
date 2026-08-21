# 189 — the form gets its own uploader. PLAN (awaiting Ian's GO)

Lane `189-form-uploader`, issue #189, `approved`.

Ian: *"Would it be worth it to forsake the wordpress media pool and put our on
interface right on the form 1 in 1 out if over ?"* — he chose the **interface**,
not the plumbing. Sharpened the same day: *"It could all be handled in form
without having a modal opened right ?"* — **yes, and that is now a requirement:
no modal at all, and no Browse-existing.**

---

## 1. What is actually there today (measured, not read)

The form is one `acf_form('lg-fc-loothprint')`. Two fields own uploads:

| field | ACF type | what it renders |
|---|---|---|
| `loothprint_more_images` | `gallery` | ACF's gallery strip; **Add** opens the WP media modal |
| `loothprint_3d_file` | `file` | ACF's file row; **Add File** opens the WP media modal |

**Those two renderers are the only thing on this page that pulls the modal in.**
Both call `acf_enqueue_uploader()` → `wp_enqueue_media()`
(`ACF_Assets::enqueue_uploader`, assets.php:316). The write-up already carries
`media_upload = 0`, so nothing else needs it.

**What ACF actually saves from is hidden inputs, and nothing else:**

```
gallery:  <input hidden name="acf[field_6547dafd3f5d6]"   value="">      ← the empty sentinel, FIRST
          <input hidden name="acf[field_6547dafd3f5d6][]" value="61698">  ← one per photo, in order
file:     <input hidden name="acf[field_6547dc013f5d7]"   value="54773">
```
(`class-acf-field-gallery.php:430,450`; `class-acf-field-file.php:132`.)

**The chunker endpoint, exactly:** `admin-ajax.php?action=bfu_chunker` takes
`_wpnonce` (`media-form`), `post_id`, `name`, `chunk`, `chunks` and the slice as
`async-upload`. On the last chunk BFU calls its own copy of
`wp_ajax_upload_attachment()` → `media_handle_upload(..., ['action' =>
'wp_handle_sideload'])` and answers with `wp_prepare_attachment_for_js()` JSON —
**`id` and `sizes` come back to us**, which is what the thumbnail strip needs.

---

## 2. The design — a RENDER swap, not a field swap

The whole plan turns on one measured fact: `acf_render_field()` runs
`acf_prepare_field()` **first** and then dispatches the type variation from the
**prepared** field (`acf-field-functions.php:800-816`). Validation and save do
not go through `acf_prepare_field` at all — they load the field via
`acf/load_field`.

**So a type set at prepare time changes the RENDERER and nothing else.**

1. **`lg_fc_relabel()`** — already the render-scoped `acf/prepare_field` filter,
   added and removed around this one render — sets
   `$field['type'] = 'lg_fc_photos'` / `'lg_fc_printfile'` for those two fields.
   ACF's gallery/file renderer never runs, so **`wp_enqueue_media()` is never
   called and the media modal is structurally absent from the page** — not
   present-and-unused. ACF still supplies the wrapper, the label, the
   instructions, the required marker and Ian's field order.
   *Stored config untouched: the field group still says `gallery`/`file`, so
   `acf_validate_values()` and `acf_update_value()` behave exactly as today.*

2. **Our two renderers** on `acf/render_field/type=lg_fc_photos|lg_fc_printfile`
   emit, in this order:
   - a drop-zone wrapping a **real `<input type="file">`** — one element that is
     the keyboard path, the click path and the no-drag fallback all at once;
   - the thumbnail strip (photos) or the single file row (print file);
   - **the hidden inputs in ACF's exact shape and order**, rewritten by the JS as
     the strip changes.

3. **Transport is #186's route, unchanged.** The JS slices the file at BFU's own
   `chunk_size` and POSTs to `admin-ajax.php?action=bfu_chunker` with `post_id` =
   the composing post. Same endpoint → same `lg_fc_chunk_guard` at priority 1,
   same `wp_handle_sideload_prefilter`, same `media_handle_upload`, same
   `add_attachment` stamp, same `post_parent`. **No second route and no second
   limit** — that is the hole that let 48 `.stl` files past a zip-only field.
   Because `wp_enqueue_media()` no longer runs, `_wpPluploadSettings` is absent,
   so the nonce and params are localized by us: `wp_create_nonce('media-form')`,
   which is the nonce BFU itself checks.

4. **Refusals name the number, in the form's voice, at every point.** A
   client-side pre-check (count and size) tells a member *before* the bytes
   leave; when the server refuses, its own sentence
   (`lg_fc_chunk_refusal()` / `lg_fc_upload_prefilter()`) is shown verbatim. The
   client check is a courtesy and never the enforcement — the server's is
   untouched.

5. **1-in-1-out.** At 10 photos a further drop does not dead-end. The strip
   enters *"pick one to swap"*, naming the incoming file; choosing a tile unlinks
   that id and uploads the new one into its place. The print file replaces
   outright on a new drop.

6. **Removing is an UNLINK, never a delete.** The tile's × removes the hidden
   input. **Nothing is deleted at that moment** — #186's stamped collector at
   publish is what deletes, and it still sees every id because `post_parent` and
   the stamp are set by the same server path as before. An **Undo** restores the
   same id without re-uploading, so a mis-swap costs nothing and no second
   attachment is ever created — which is also why a removed-then-re-added file
   cannot be double-stamped (`update_post_meta` is idempotent and no new row is
   made).

7. **The hero picker** reads `.acf-gallery-attachment` today; it moves to our
   tiles' `data-lgfc-att` and keeps its MutationObserver. Its behaviour does not
   change.

8. **Browse-existing goes**, per keeper. Nothing on this form reaches the pool.
   ⚠️ `lg_fc_scope_library()` **stays**: it is an `acf/load_field` filter, site-wide
   for those field names, and it is what keeps *wp-admin's* picker scoped to a
   post's own library. Deleting it would loosen something outside this form.

### The line I must not cross — and where it is checked
Files stay WordPress attachments. Nothing above writes a file, a row or a URL by
hand: the only thing that creates an attachment is still
`media_handle_upload()` on the far side of the chunker. Image resizing, the
layout engine's ID references, #186's reference walk and gates 88/35 all read the
same rows they read today. **If the first measurement below says otherwise I
stop and board it.**

---

## 3. The one measurement I take BEFORE writing the feature

The plan rests on "prepare-time type never reaches validation or update". That is
the #185 lesson — bracket it, do not reason about it. A probe under the gate-88
mirror renders the form and then saves a payload, asserting:
- the rendered page contains **no** `.acf-gallery` / `.acf-file-uploader`, **no**
  `wp-media`/`media-editor` script handle, and **no** `_wpPluploadSettings`;
- the hidden inputs are present with ACF's exact names and order;
- a POST of that exact shape stores **the same ids in the same order** as today.

If the third one moves, the design is wrong and I say so rather than adjusting
around it.

---

## 4. Files I expect to touch (guessed wide, per LANE-RULES)

- `platform/mu-plugins/lg-frontend-compose.php` — the type swap in
  `lg_fc_relabel()`, two renderers, an `lg_fc_upload_config()` blob for the JS,
  `lg_fc_css()`, `lg_fc_js()`, the hero-picker selector, and
  `lg_fc_shed_site_chrome()`'s comment (it currently promises the media modal).
- `tools/gates/compose-limits-gate.py`, `compose-limits-probe.php`,
  `compose-limits-redfirst.py` — **extend gate 88, mint no number** (88/89 taken;
  #175 means gates run individually).
- `platform/nginx/lane-preview-189-form-uploader.conf` (new) and a small
  `tools/preview/mu-mirror-boot.php` (new) — the existing compose preview conf
  points at `/var/www/dev/index.php`, i.e. **main's** mu-plugin, so it would show
  Ian the old form. The shim `define()`s `WPMU_PLUGIN_DIR` at a gate-88-style
  mirror and then requires the serve's `index.php`, so the preview runs THIS
  BRANCH. Nothing on the serve is modified.
- `tools/preview/compose-uploader-shots.py` (new) — Ian's pictures.
- `docs/domains/PAGE.md` — the domain rule (and the fifth `page`-label footnote).
- `handoffs/2026-08-21-189-form-uploader.md`.

Not touched: any ACF field configuration, `lg_fc_limits()`, the collector, the
stamp, the prefilter, the chunk guard, `platform/config/`.

---

## 5. What Ian gets to look at

Shots at both themes, signed in as a real member, of: **empty**, **mid-upload**,
**at the limit with the swap offered**, and **a refusal**. Plus a clickable
lane-preview URL of the branch's own form.

## 6. Verification

- As a **real member** — a PID-keyed `looth1` account created and destroyed in
  the run (`qa-disposable` is an administrator; on #186's feature that was the
  difference between 5MB and 5GB). Nonce taken from a **fresh render of the page
  under test**, not minted on the CLI.
- The swap proved end-to-end: at the limit, adding one removes exactly one, and
  **the removed file still exists** at that moment.
- Keyboard reachable; the file input works with drag-drop absent; both themes.
- Gate 88 run individually in **both flag states**, and its `Z.end` sentinel
  respected — a probe that dies early scores like a clean pass.
- Red-first mutations for every new leg, including one that puts the media modal
  back.
