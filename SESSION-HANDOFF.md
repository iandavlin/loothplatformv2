# SESSION HANDOFF — lane `frontend-compose`, re-chartered as **the layout-v2 lane**

**Written 2026-08-14 at 97% context. Assume you are a fresh lane with zero
context: this is your charter. Everything below was measured on this box, not
remembered — where something is unproven it says so.**

| | |
|---|---|
| Branch | `frontend-compose`, pushed, tip **`8002184`** |
| Base | main **`a29c5fd`** — ⚠️ you are **10 behind**; rebase before your first gate run |
| State | **29 commits, NOTHING MERGED.** Working tree clean, nothing session-only |
| Flag | `platform/config/frontend-compose.php` → `'enabled' => false`. Everything is inert on the serve |
| Merge | **NOT merge-ready and do not declare it.** keeper merges on the lane's word; the block work below lands first |
| Ian | has ruled five times (below). He approved the four-block plan: *"Yes — build all four."* |

---

## YOUR JOB NOW, in order

Ian, 2026-08-14: *"If we don't have a block that can handle anything in the new
form, like the license, we need to spin up v2 to produce the block."* Then, on the
plan: **"Yes — build all four."** Build them in this order:

1. **A licence block.** His named example. The four Creative Commons choices as
   *choices*, not prose.
2. **Point print files at the existing `download` block, and give it a file
   picker.** Closes the stale-ZIP defect in the same move (see below).
3. **An items editor for `callout`.** Closes the CAD link and the tip jar together.
4. **A taxonomy/chips block** for Type of Loothprint + Content Topic. The only
   genuinely new ground.

Full evidence: **`docs/V2-BLOCK-COVERAGE.md`**. Read it before you start.

---

## (a) V2's SOURCE OF TRUTH — settled, build in the monorepo

The docroot loads `/var/www/dev/wp-content/plugins/lg-layout-v2 ->
~/loothplatformv2-clean/lg-layout-v2`, and layout-v2 is **tracked in the monorepo**
(232 files). **v2 work is monorepo work — build in your worktree, as normal.**
No stop condition; this was checked first because keeper required it.

⚠️ **`~/projects/lg-layout-v2` also exists and differs in 68 files. Do not edit it.**
It has no `.git`, and every file sampled is OLDER and SHORTER than the monorepo's
(post-header 06-16/488 lines vs 07-30/500; wysiwyg 05-23/24 vs 07-12/45). It is a
stale leftover — not a second source, not unlanded work — but editing it changes
nothing on the serve while looking exactly like it should.

---

## (b) THE FOUR BLOCKS — what is already measured

**Catalogue: 20 blocks.** Editability comes from each block's
`manifest.json` → `editor.inline_editable_props` / `custom_picker`. Read it there;
do not infer from the renderer.

Coverage of the 12 loothprint fields: **4 covered both ways** (description, hero,
photos, video), **4 display but v2 cannot edit** (print files, CAD, tip jar,
licence-as-a-choice), **2 not on the page at all** (both taxonomies).

**Licence (block 1).** `grep -rli licen[cs]e lg-layout-v2/blocks/` returns
**nothing** — v2 has no idea what a licence is. `default_loothprint_layout()`
drops the licence *sentence* into a generic `callout`, whose `body` happens to be
inline-editable, so today v2 can only retype the prose. The four choices live in
ACF (`loothprint_creative_commons`, radio, default *BY NC SA…*).

**Print files (block 2) — the cheap win.** A **`download` block already exists and
the loothprint page does not use it.** Props: `file_id, url, label, title`.
`file_id` is an attachment id — exactly what would let the page follow a replaced
ZIP. The synthesizer emits `callout` variant `files` with a **baked `url`**
instead, and `download` declares no inline-editable props and no picker. Two small
jobs: use the block, then give it a picker.

**Why any of this matters — the defect it fixes.** A layout-v2 page **stores** its
content. Measured: `post-header` reads title/hero/author/tier live, so those
track; everything else is baked (`wysiwyg.html`, `gallery.image_ids`, `embed.url`,
the download `url`+`label`, the licence `body`). So a member replaces their ZIP,
the form says saved, **and the page keeps serving the old file.** It still
downloads, so nobody notices it is wrong. That is reported to Ian and is why
block 2 matters more than its size suggests.

---

## (c) BRANCH STATE — 29 commits, none merged

Nothing of this lane is in main. In dependency order, what is on the branch:

* **The route.** `platform/mu-plugins/lg-frontend-compose.php` —
  `/compose/?type=loothprint` (create) and `/compose/?id=<post>` (edit).
  `&embed=1` serves it furniture-free for the composer's iframe.
* **Edit is ownership-gated** on `current_user_can('edit_post',$id)`, and the type
  is derived from the **stored post**, never from `?type=`.
* **The composer type toggle** — Discussion ↔ Loothprint, in
  `bb-mirror/web/_chrome.php` + `forums.js` + `forums.css`. Driven in a real
  browser through the preview, not read.
* **The flag is a shared tracked config** (`platform/config/frontend-compose.php`)
  read by BOTH WordPress and bb-mirror, so the toggle and the form cannot disagree.
  Same pattern as `platform/config/post-follow.php`.
* **Gate 35** (`tools/gates/compose-gate.py`) — 10 assertions, per-state, reading
  `lg_fc_enabled()` off the box. **Every assertion falsified by mutation.**
  ⚠️ **34 is the stripe seat's** — annotated as a deliberate gap in `run-all.sh`.
  **Gate numbers come from keeper. Never mint one.**
* **Three decision pages** at `/footer-mockups/frontend-compose-build/`
  (`index.html`, `toggle.html`, `freeze.html`), also committed under
  `footer-mockups/` so they survive a box rebuild.

### Run the gate
```bash
sudo ln -sfn ~/worktrees/frontend-compose/platform/mu-plugins/lg-frontend-compose.php \
             /var/www/dev/wp-content/mu-plugins/lg-frontend-compose.php
python3 tools/gates/compose-gate.py --type loothprint --allowed bangers \
  --denied erin.vogel --owner patreon_77159883 --stranger bangers --post 72155
sudo rm -f /var/www/dev/wp-content/mu-plugins/lg-frontend-compose.php   # ALWAYS restore
```
`--denied` must be an account the tier genuinely refuses — loothprint is open to
ALL members, so that means someone without `edit_posts`/`upload_files`
(`erin.vogel`), **not** an ordinary member. To exercise the ON path, flip
`platform/config/frontend-compose.php` and flip it back.

### The lane preview (already installed and surviving reboots)
`https://dev2.loothgroup.com/preview/frontend-compose/hub/?compose=1` —
`sudo bash tools/preview/lane-preview.sh up|down frontend-compose`.
**You need it**: `/srv/bb-mirror` symlinks to `loothplatformv2-clean`, so the hub
serves **main's** `forums.js`/`_chrome.php` and a branch edit is invisible on
dev2. The preview conf arms `LG_FC_PREVIEW` on **both** its hub and compose
locations — arming only one gives a preview whose JS is present and whose markup
is absent.

---

## (d) THE FORM IS DETAILS-ONLY — the interim shape, and it stays

Ian, 2026-08-14: *"I want all the old posts and the new posts to be handled by
layout-v2. I thought we made this decision."*

* **The PAGE belongs to layout-v2** — every loothprint, old and new. The 169 with
  stored pages keep them, untouched.
* **The FORM owns the DETAILS** — licence, type/area, print files, title: exactly
  the gap this audit measured.
* **The form must never synthesize or replace a page.** It does not today.
* This holds **until the block coverage closes**. The four blocks are what let the
  page show and edit what the form collects.

`freeze.html` records the ruling; the earlier A/B/C options are marked WITHDRAWN
and kept only as the record of how it got there.

---

## TRAPS THIS LANE PAID FOR — do not re-learn them

1. **Assert on the value, not the document.** I greped a page for a post's title
   and reported "prefilled"; it had matched the `<title>` tag while the input was
   empty. That hid a real data-loss bug — a rendered-but-empty field **saves
   empty**, so an edit form that fails to prefill blanks the member's post.
2. **Counting CSS text is not counting a control.** Grepping `lgfc__frozen` gave
   3 vs 4 because the stylesheet contains the class. Assert on the rendered
   element.
3. **`querySelector` returns the FIRST match, not the right one.** `[data-ntm-open]`
   found a hidden button measuring 0×0; `[type=submit]` on the legacy form returns
   **Add Media**, not the submit.
4. **Hit-test before clicking** (`elementFromPoint`) — a transparent chrome wrapper
   swallowed a click that "succeeded".
5. **Variable scope.** The toggle silently never rendered because `$lg_can_post` is
   assigned at file scope ~430 lines *below* the function it was read in.
6. **A flex item with a set height still gets shrunk.** The iframe was set to
   1630px and rendered at 535 until `flex: 0 0 auto`.
7. **WP's clock here runs 4h behind server local.** Compare post timestamps against
   `current_time()`, never `date`.
8. **Never name a script `enum.py`** — it shadows the stdlib and Python dies with a
   circular-import trace.
9. **Restore the box every time**: 40 mu-plugins, none of yours, and zero probe
   rows. Every probe force-deletes and proves it.

---

## OPEN, NOT BLOCKED ON IAN

* **Do the eight legacy "Add Post" forms actually submit?** Still unsettled and
  fully written up in `tools/frontend-compose/legacy-submit-notes.md`, including
  the three attempts and why each proved less than it looked. Next run: fill every
  required field including the repeater rows and **watch the network** for
  `frontend_admin/form_submit`, rather than inferring from the DOM.
* **Should the form keep the page's download link, licence and photos in step when
  a member edits those details?** Reported to Ian, not yet answered. Blocks 1–3
  may make it moot.
