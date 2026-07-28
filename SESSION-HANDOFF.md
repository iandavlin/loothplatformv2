# SESSION HANDOFF — lane `reply-images-count`

**Written 2026-07-27 as the lane was cycled for RAM. Assume you are a fresh lane
with zero context — this is your charter.**

| | |
|---|---|
| Branch | `reply-images-count`, pushed |
| State | **MERGED AND SERVING ON DEV2** (2026-07-28, Ian approved). Not merge-ready — *shipped*. |
| Serve baseline | HEAD **`645b4cff935b`**, tree **`e42d6a639820`**, branch `main`, porcelain empty. **Re-read it from `~/keeper-baseline/`, never from this file** — it moves. |
| Shipped alongside | composer v2 phase 3, in the same pull |
| LIVE | **NOT touched.** Live deploy is Ian's. |
| Ian | asked for it by name, and passed it on dev2 on real member content |

> ⚠️ **THE OLD BASELINE `fa67f02` / tree `74b3975` IS DEAD.** Restoring anything to
> it now would be a **regression, not a restore** — it predates this lane's own
> merge. Older sections of this file and the 7/27 receipt still name it; there it
> is correct *as history*. It is never a restore target again.

---

## 1. What this lane did, in one breath

Ian asked for more images on a discussion reply. The investigation found there
was **never an upload limit** — not in the composers, not at the write endpoint,
not in BuddyBoss (its `bp_media_allowed_per_batch=3` only ever reached a Dropzone
`maxFiles` in a theme we do not use). The entire ceiling was one line of SQL in
the **render**: `ORDER BY id ASC LIMIT 1` in `_topic-replies.php`.

So members had been attaching 2–5 photos for years and seeing only the first.
**On LIVE: 233 replies were hiding images, 374 images had never been seen by
anyone.** Ian independently hit this ("we can still only do one image per reply,
can we fix that?") without knowing the diagnosis.

Ian ruled **max 6**. The lane built two things: render up to 6, and enforce 6 at
the door.

**Read `docs/atlas/REPLY-IMAGE-COUNT-CEILING.md` before touching anything.** It
is the canonical write-up: the per-layer ceiling table, every surface that
renders a reply, measured heights and weights, §9 what shipped, §10 housekeeping
owed. This handoff is only "what to do next".

---

## 2. THE NUMBERS — quote LIVE, never dev2

| | LIVE | dev2 |
|---|---|---|
| replies that stop hiding images | **233** | 230 (moves!) |
| images that become visible | **374** | 368 |
| replies truncated by a cap of 6 | **0** | 0 |
| most photos on any published reply | **5** | 5 |

**The number is 233 / 374. The 236 / 380 this file used to carry is WITHDRAWN.**
It counted the commas in `wp_postmeta.bp_media_ids`, which is a stale list of
ids — some point at `wp_bp_media` rows that were later deleted, and the comma
method cannot tell a live reference from a dead one. Resolving every id against
`wp_bp_media` on LIVE gives 233 / 374, and LIVE's Postgres mirror independently
gives 233 / 374. Two stores, exact agreement; the mirror was right all along.

The four replies that made up the difference: 58201 (lists 3, 0 alive), 58209
(2, 0), 58294 (3, 0), 58462 (3, **2**). 236 − 3 = 233; 380 − 6 = 374.

Say to Ian: *"233 replies are hiding 374 images."* Nothing about a re-sync — I
briefly reported that and it was wrong; those images are **deleted**, not
un-mirrored. Atlas §1 has the retraction in full.

Three traps in that one table, all of which have already caught someone:

1. **dev2 is not a copy of live.** An earlier revision of the atlas doc said it
   was. It is not, and dev2 also takes writes from test lanes, so its number
   moves *during a session* (229 at 16:20, 230 at 21:30 the same day).
2. **Never count reply images off `forums.attachment` alone** — always join to
   `forums.reply … status='publish'`. Attachment-only counts include **orphans**
   (rows whose reply is gone), which is where the phantom "max 11 images per
   reply" came from — the thing that made a cap of 6 look lossy to two separate
   people on two separate days.
3. **`grep -c` counts LINES.** The renderer emits one line; occurrence counts
   need `grep -o | wc -l`. This bit this lane despite being written in its own
   brief.

Live is readable read-only: `ssh live-ro` (there is a `~/.my.cnf` there with a
`looth_ro` MySQL user; no sudo, wp-config unreadable, but `mysql` works). The DB
is **`looth_import`** (siteurl loothgroup.com). Query is in the atlas doc §1.

---

## 3. WHAT IS ALREADY PROVEN — verify the receipts, do not redo the work

keeper's status notes have twice listed the 422 and the composer guard as still
owed. **Both were closed in the third serve window on 2026-07-27**, and the
evidence is committed and re-runnable at:

```
footer-mockups/reply-images/serve-window-20260727/
  README.md                    the full request/response tables
  proofB.json                  per-photo counter / disable / refusal state
  proofC.json                  per-surface picked candidate + broken count
  drive-composer-guard.py      real touch + real uploads, 390x844 DPR2
  drive-gallery-surfaces.py    mobile / desktop / logged-out on reply 58510
  B2,B3,B4,B5*.png             3 of 6, 6 of 6, 7th refused, posted gallery
```

**Your first move is to read that README (2 minutes), not to re-run it (a serve
window plus an engine).** If keeper wants independent confirmation rather than
the artifact, §4 tells you how to reproduce each.

Summary of what those receipts show:

- **422 under a real over-cap request — CLOSED.** Six real HTTP calls to the real
  endpoint. 7 via `media_ids` → 422; 7 via `bbp_media` (*the shape composer v2
  actually sends*) → 422; 6 → 200. Edit: keep 4 + add 3 → 422; **add-only PUT on
  reply 58510, which holds four real stored images** → 422 with `post_modified`
  still `2025-09-02`, proving the cap counts existing media and fires *before*
  the write; keep 3 + add 3 → 200. **Negative control:** after the files were
  restored, the identical over-cap POST returned **200** — which is what rules
  out the 422 coming from anything but this lane's code.
- **Composer guard under a real finger — CLOSED, one caveat.** Real trusted touch
  to open the composer, six real photos through the real input to the real BB
  media endpoint, counter `1 of 6` → `6 of 6`, amber and photo button disabled at
  6, 7th refused with the chip count **held at 6**, counter width 38 ==
  scrollWidth 38 (readable, not ellipsed). Posted, then asserted on the **store**
  (6 rows in `forums.attachment` + `bp_media_ids 3401..3406`), not the DOM.
  *Caveat:* file **selection** used CDP `DOM.setFileInputFiles` — no automation
  can drive an OS picker. Everything downstream of it is the genuine path.
- **Gallery on a real device — path proven here, phone closed by Ian.** Existing
  member reply 58510 on mobile logged-in, desktop logged-in and mobile
  **logged-out**: 4 cells, 0 broken, intrinsic dims, lazy, anon correctly
  scrubbed. Ian closed the real phone leg himself on reply **71991** rendering
  5 of 5 previously-unseen images.

### What the two owed proofs need NOW that the code is merged

This changed with the 2026-07-28 deploy and it makes the ask **cheaper**, not
dearer. **Neither proof needs a file overlay any more.** The code under test IS
the serve — verified by diffing all eight files against the serving checkout:
five byte-identical, and the three that differ (`forums.css`, `forums.js`,
`hub-polish.js`) differ only because composer-p3 shipped in the same pull, with
every one of this lane's markers intact (gallery block 12 hits, `max: 6` at two
tray sites, `reply_image_max`/`lgcPhotoN` counts matching exactly, both server
422 sites present at `reply.php:242` and `:310`).

So there is nothing to overlay, nothing to `git checkout HEAD --` afterwards, and
no fpm reload. What is actually needed:

| Proof | Needs | Does NOT need |
|---|---|---|
| 422 under a real over-cap request | permission to create + delete test data on dev2 | overlay, engine, fpm reload, restore |
| composer guard under a real finger | the **browser engine slot** + test data | overlay, fpm reload, restore |

**The negative control has to change, and it gets stronger.** Last time the
control was "restore the files, re-POST 7, get 200" — that proved the 422 was
this lane's code. That control is now impossible without un-shipping, and
un-shipping is a serve mutation nobody should want. The replacement is a
**boundary differential on the shipped endpoint**: same session, same thread,
same payload shape — **6 → 200, 7 → 422**. One image apart. That isolates the cap
better than the restore control did, because it holds everything else constant
instead of changing the whole tree.

**Cleanup is the real obligation now.** Every test reply and every uploaded media
row must come out, and — per this lane's own orphan finding — deleting a reply
does **not** remove its `forums.attachment` rows. Check the mirror explicitly,
not just WP; §4c and atlas §10 have it. Test data on a serving box is the thing
that outlives the window.

---

## 4. WHAT IS GENUINELY STILL UNPROVEN

Small. Both need a serve window; neither is ship-blocking in my judgement, and
the first may be moot.

### 4a. The legacy desktop tray guard has never been driven by a finger

`lgComposerTray({max: 6})` in `bb-mirror/web/forums.js` guards the *legacy*
desktop composers (the frm reply modal at `:2580`, the single-topic reply form at
`:3065`). Only composer v2's mobile path was driven. It is lint-clean and
structurally identical to the path that was proven, but unexercised on a surface.

**It may be moot:** `composer-v2-p3` deletes those create paths (inventory
W1/W5). If p3 lands first, this guard becomes belt-and-braces behind the server
cap and is arguably not worth a window. **Check whether p3 has landed before
spending anything here.**

*How to prove it if you do:* desktop viewport ≥ 641px, log in, open a feed card's
reply modal (`[data-frm-open]` — visible on desktop, **0×0 on mobile**, see the
trap in §6), attach 6 photos via the tray's own file input, confirm the 7th is
refused with the real message — `A reply can have at most 6 photos — remove one to
add another.` (client, `hub-polish.js`) — and that `frmMediaIds.length` stays 6.
**Earlier revisions of this file quoted `You can add up to 6 images.`; no such
string exists in the code.** I wrote it from memory of the design rather than
from the source, and a run that greps for it would find nothing and read that as
a failure. The server's 422 body is a *different* string again: `A reply can have
at most 6 images.` with `error: too_many_media`.
Adapt `drive-composer-guard.py`; it already does the hard parts.

### 4b. Removing a chip to free a slot was never exercised

Proven: filling to 6 and being refused at 7. Never proven: remove one chip
(the `✕` on a `.lgc-pv`), confirm the counter drops to `5 of 6`, the photo button
re-enables, and a 7th photo is then accepted.

This matters more than it sounds, because the count is taken from **chips in the
strip**, not from `lcpMediaIds` — deliberately, so an in-flight upload, a
failed-awaiting-retry tile and a photo kept on an edit each hold a slot. The
release path is the untested half of that design.

*How to prove it:* extend `drive-composer-guard.py` — after reaching `6 of 6`,
`tap('#lgc-strip .lgc-pv:nth-child(3) .lgc-pv-x')`, assert `5 of 6` +
`photoDisabled:false`, then attach one more and assert `6 of 6` again.

### 4c. Orphan `forums.attachment` rows (housekeeping, dev2 only)

Deleting a reply does **not** clean its mirror attachment rows. Three orphans on
dev2: 72083/72084 (reply-images-6 tests, 2026-07-09) and **72225 — this lane's
own serve-window test reply**. Not user-visible (every read starts
`FROM forums.reply`), but it pollutes any attachment-only count. Cleanup SQL is
in the atlas doc §10. **Needs a window, must not run while anyone is testing**,
and check first whether a materializer re-sync clears them before writing a
DELETE. The underlying delete-path defect is pre-existing and NOT this lane's to
fix.

---

## 5. THE SERVE PROTOCOL (dev2) — read before you overlay anything

**The serve IS the repo.** `/srv/bb-mirror`, `/srv/profile-app`, `/srv/lg-shared`
and all 24 top-level docroot `.js`/`.css` are symlinks into
`~/loothplatformv2-clean`. That is the **design**, not a hazard — dev2 is
pull-only, so a deploy is one `git pull`. Ian corrected keeper on this.

- **NEVER write to `/var/www/dev`.** Not `cp`, not rm-and-replace, not
  `webroot/deploy.sh` (no guard — it clobbers the symlink farm;
  `deploy/deploy.sh` is the guarded one and correctly prints SKIP on dev2).
  Change the file in the repo; the docroot follows. `?v=` cache-busts derive from
  `filemtime`, so a changed file self-busts.
- **Overlay by editing tracked files in `~/loothplatformv2-clean`**, then restore
  with a path-restore. That is the pattern keeper wants from every lane.
- **THE ONLY ALLOWED RESTORE IS `git checkout HEAD -- <path>`**, followed by
  `git reset -q` if you staged anything. **Never the bare `git checkout -- <path>`
  — keeper ruled this on 2026-07-28 after issuing the wrong form in every window
  grant that night.** The bare form restores from the **INDEX**: on a shared clone
  with five lanes and a keeper touching it, if any process has staged work it
  **installs that staged work into the serve and exits 0** — success reported,
  someone else's edit serving. keeper proved it in a scratch repo rather than
  reasoning about it (committed `COMMITTED`, another process stages
  `STAGED-BY-ANOTHER-PROCESS`; bare form yields the staged text, `HEAD --` yields
  the committed text). This is not hypothetical here: during this lane's window
  composer-p3 had six files staged in the serve clone, **three overlapping this
  lane's set**. Had their staging landed first, the bare form would have written
  their content into the serve under my name.
- **The tree hash cannot detect a staged overlay.** `git rev-parse HEAD:` is the
  *committed* tree and matches regardless of index state. Only
  `git status --porcelain` catches it. Prove a restore with **all three**: HEAD,
  tree, porcelain.
- **Verify the restore against the WHOLE-TREE HASH, never an md5 of the files you
  remember touching.** This is exactly the failure that backstop exists for: all
  three windows on 7/27 were restored with the *wrong* command and came back
  matching `74b39757…` anyway, which is the only reason nothing shipped wrong. A
  spot-check of remembered files cannot catch a file you did not remember.
- Reload `php8.3-fpm` after PHP changes; `nginx -t` before any reload and again
  after the restore; a full `systemctl restart nginx` at the end is the bar.
- **Ask keeper for the window. Never assume one.**

Files this lane overlays (8, all tracked):
`bb-mirror/config.php`, `api/v0/reply.php`, `api/v0/auth.php`,
`web/forums/_topic-replies.php`, `web/forums/_reply-render.php`,
`web/forums.css`, `web/forums.js`, `webroot/hub-polish.js`.

---

## 6. TRAPS THE NEXT RUN NEEDS

- **`[data-frm-open]` is the DESKTOP reply CTA and measures 0×0 at 390px.** The
  mobile entry point is **`.lg-fb-reply`**. `Input.dispatchTouchEvent` happily
  delivers a touch to whatever is at the coordinates, so tapping an off-screen
  element silently hits the page behind it and your wait times out with no clue.
  Always `scrollIntoView` first and assert `document.elementFromPoint` is your
  target. `drive-composer-guard.py` does both.
- **The `/hub` anon microcache serves stale fragment bytes** right after an fpm
  reload. An authenticated or cache-busted request is the truthful read. This bit
  this lane mid-window and it is the same false-stale trap that bites on restore.
- **Assert the srcset candidate the browser PICKED (`img.currentSrc`)**, never
  that a `srcset` attribute exists. A markup-level assertion passed the whole
  time while desktop was pulling `w=800` for a 229px tile, because `sizes`
  declared 360px — sized against the modal's 724px column, ignoring
  `.reply-stub__gallery`'s own `max-width:460px`. Fixed in `e183136`.
- **Assert on the STORE, not the DOM.** composer-p3 found `data-lg-uuid` is
  stripped between the composer and `wp_posts`. This lane's gallery reads
  `forums.attachment` rows so it is structurally clear, but that was *proven*,
  not assumed.
- **One browser. Count with `pgrep -x chrome`** (~11 processes per engine — count
  engines, not processes; `pgrep -f` self-matches). Park it between batches. RAM
  on this box is routinely under 700MB with five lanes resident.
- `Emulation.setTouchEmulationEnabled` rejects `maxTouchPoints=0`; use 1.
- Probe `loading=lazy` **before** forcing eager, or you measure your own
  instrumentation.
- **`verify.sh` runs under `sudo -u postgres`**, so temp files must be
  world-readable (`mktemp -d` is 0700 — use plain `/tmp` + explicit chmod) and
  `git` needs `-c safe.directory='*'` or it writes an **empty** baseline that
  reads as "the render changed".
- **wp_mail on dev2 is a false positive** — containment swallows it into mailpit
  and returns true. Never trust a `true` return as proof mail was sent.
- **`dev.loothgroup.com` is DEAD.** Anything naming it is broken by definition,
  not merely misaddressed. Do not preserve it as a fallback.
- **`live-ro` is DIRECT now — it no longer touches dev1.** Ian added dev2's IP to
  live's security group and the `ProxyJump` is stripped from `~/.ssh/config`.
  **dev1 is off and staying off.** Re-proven here on 2026-07-28 with dev1
  confirmed `stopped`: `ssh live-ro` connects and queries fine. An earlier
  revision of this file said live-ro proxies through dev1 and warned about dev1
  auto-stopping mid-query — **that was true for about a day and is now wrong;
  ignore it.** Both live stores are readable: MySQL `looth_import` (WP) and
  Postgres `psql -h 127.0.0.1 -U looth_ro -d looth` (the `forums.*` mirror).
  **Live is READ-ONLY and stays that way** — the account is `looth-ro`, a test
  `CREATE TABLE` was refused, and every live write is Ian's.
  Still worth doing anyway: **script a whole query set into ONE file and pipe it
  over a single ssh on stdin** (`ssh live-ro 'mysql … looth_import' < q.sql`).
  Not because the link is fragile now, but because it makes the measurement
  re-runnable and quotable instead of a pile of ad-hoc shell.
- **A GREP THAT UNDER-MATCHES READS AS A PASS.** Sweeping this branch for the
  banned bare restore form, I searched `"git checkout -- "` **with a trailing
  space** and reported "nothing to fix". `docs/atlas/OPERATOR.md` writes it as
  `` `git checkout --` `` — backtick, no space — so the pattern silently returned
  fewer hits and the empty-ish result read as clean. Same family as `grep -c`
  counting lines, and the same failure keeper flagged in composer-p3's guard grep
  (an unescaped `$` in a BRE returns empty rather than erroring). **Use `grep -F`
  for literal strings, never assume the whitespace around a quoted command, and
  when a sweep returns "clean", confirm the pattern can match a known-present
  instance before believing it.**
- **Do not carry another lane's figures — including keeper's — without checking.**
  `free -m` and `swapon --show` take one second. Measured here 2026-07-28: swap
  is **2GB total, not 4GB**, and it is **not** "essentially untouched" (984MB in
  use, ~211MB free RAM, ~793MB available). Check before starting anything.

---

## 7. HOW TO VERIFY THE BUILD WITHOUT A SERVE WINDOW

```sh
cd footer-mockups/reply-images
sudo -u postgres bash verify.sh        # 38 assertions, ~20s, no window needed
```

**It was 31/36 when this handoff first said "36 assertions" — a false RED.**
Section 4 hardcoded the `sizes` strings from before `e183136` corrected them
(360px/240px → 228px/151px), so it failed against *correct* code. Fixed in
`886966c`: section 4 now parses `max-width`, columns, gap and the grid spans out
of `forums.css` and computes the expected tile widths, so it tracks the source of
truth instead of a snapshot. Proven both directions — reintroduce the e183136
defect → 5 FAILs; change the CSS max-width to 500px → re-derives 248/164 and
fails on the renderer's drift; restored → 38/38.

**Lesson worth carrying:** a verifier that hardcodes the output of the code it
verifies rots the moment that code is fixed, and it rots *silently* into a RED
that reads as a regression. Derive from the source of truth or expect to be lied
to. Run it before you quote it — this handoff pointed the next run at a check it
had never re-run after the last commit.

`render-harness.php` `require`s the shipping `_reply-render.php` and runs the
shipping `_topic-replies.php` query against the live mirror, so reply markup can
be verified — and previewed — with nothing deployed. It asserts, among others,
that a **single-image reply renders byte-identically** to the pre-change code
(diffed against the renderer pulled out of `git show`, not eyeballed).

Previs deck (frames are the actual shipped output, not drawings):
`https://dev2.loothgroup.com/mockups/reply-images/` — regenerate with
`python3 gen.py out` and copy to `/var/www/dev/mockups/reply-images/`. That
directory is a shared, group-writable mockups dir; **adding a subdirectory there
is additive and does NOT need a serve window** (no served file mutated, no
symlink repointed, no fpm reload).

---

## 8. GATE COVERAGE — stated openly

`tools/gates/run-all.sh` is 4/5 `GATE-ERROR`: three gates hardcode the dead
`dev.loothgroup.com` and a conf path that does not exist, and the token lookup
greps a site conf for a token that moved to
`/etc/nginx/snippets/loothdev-tokens.conf`. Pre-existing and box-wide — proven by
stashing this lane's diff and getting identical RED. **`slug-backfill` owns
fixing it; do not duplicate that work.**

**This lane shipped without craft-gate coverage.** Gate 2 *is* the craft gate, so
there is currently no gate on the srcset/weight class this lane changes.
`verify.sh` stood in, and keeper accepted that specifically because it asserts
the candidate the browser actually picks. Worth knowing that the craft gate would
**not** have caught the `w=800` over-fetch either — the markup was correct and
only the picked candidate was wrong.
