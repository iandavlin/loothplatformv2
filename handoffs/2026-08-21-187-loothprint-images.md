# Lane 187-loothprint-images — handoff

**Issue #187** · branch `187-loothprint-images` · worktree
`~/worktrees/187-loothprint-images` · **DONE**, pushed, awaiting keeper's merge.

Ian asked one question on 8/21 — *"are the images getting compression?"* — and
the answer is a number:

    the hero on /loothprint/fret-sander-v2/
      raw original      105,396 bytes    <- what a phone downloaded
      /img.php w=800     18,132 bytes    <- what a phone needs
    83% off, from a resizer that was already running for the Hub feed.

Uploads ARE compressed (four variants + a `jpeg_quality` filter). The article
pages just never asked for them.

## What shipped

Whole-page, real browser, cold cache, **true bytes** (see the measurement trap
below — the gate's own numbers are wrong on these pages):

| page | phone 390@3 | desktop 1440@1 |
|---|---|---|
| `loothprint/fret-sander-v2` | 422 → **230 KB** (−46%) | 414 → **215 KB** (−48%) |
| `post-type-videos/…evertune` | 931 → **285 KB** (−69%) | — |
| `post-imgcap/68-jazz-bass…` | — | 5,730 → **2,901 KB** (−49%) |

Plus the gallery block, found by asking the corpus which image-emitting blocks
real layouts use rather than assuming the three audited pages covered them:
**`/loothprint/neck-side-crack-jig/` 1,093.6 → 299.4 KB at phone width (−73%)**.
The `callout` block — 890 instances, the most-used block on the box — needed
nothing: zero callout items carry an `image_url` anywhere in the corpus.

Four commits:

1. **`c10c379` — the gate first, deliberately before any fix.** CPT singles were
   the one family `craft-gate.py` had never audited, so CLAUDE.md's image law had
   never been evaluated where members read. **RED on main: 12 violations.**
2. **`39a0004` — the fix.** One helper (`Img`), four emitters. **GREEN on the
   branch.**
3. **`f3628a0`** — handoff, domain rule, and the measurement trap.
4. **`46c0efc`** — the gallery block (71 in use), the fifth emitter.

## Gate state

| gate | verdict |
|---|---|
| craft gate, 12 pre-existing surfaces | **GREEN** — undisturbed |
| craft gate, 4 new CPT audits, vs the **branch** | **GREEN** |
| craft gate, 4 new CPT audits, vs **main's route** | **RED (12)** — by design, see below |
| 69 loothprint edit door, vs the branch | **GREEN** (49 checks) |
| loothprint paywall | **GREEN** (10 checks) |

⚠️ **The new craft-gate pages are RED until this merges, and that is not a
defect.** `/loothprint/<slug>/` serves `/srv/archive-poc`, a symlink into the
serving checkout, so dev2 runs `main`'s copy of the blocks. The gate goes green
the moment the serve pulls the merge. This is the deploy gap the lanes page's
own strip exists to surface. Nothing else to do at merge time — no symlink, no
new file in the webroot, no flag.

## The one thing every lane on this box should take from here

**`transferSize` is 0 for a cross-origin resource**, and the craft gate computes
every KB it prints from it. 28 of the jazz-bass article's 35 images are stored
with the **live** host, so the gate reported 222KB against a true 5,730KB — a 26×
understatement in the direction that looks healthy. **Cutting that page 49% made
the gate's own number rise 63%.** A lane trusting the printed figure would revert
a real improvement. Encoded in `docs/CRAFT-STANDARD.md` and `docs/domains/PAGE.md`.

## Judgement calls, both ruled by keeper before building

- **Three gate pages, not nine.** All nine managed CPT singles run the same
  renderer and the same blocks, so nine entries cost every lane ~9s of shared
  gate time to re-prove one code path. **The emitters were fixed for every
  variant anyway, including the sponsor hero that no gate page exercises** — the
  gap is uncovered-and-fixed, not uncovered-and-broken. Recorded here so nobody
  reads it as an oversight.
- **`lg-layout-v2/` not touched.** It is a second, already-diverged copy of the
  same engine (**11 files differ, no sync script**) and it does not serve these
  pages — it is the in-WordPress path. Fixing it would change what the front-end
  editor draws for no member-facing gain. **Recorded as a finding so the
  divergence stops being invisible.**

## #186's reference walk — checked before a line was written

The walk is what stops the publish-time collector deleting a member's photo, and
this lane rewrites image URLs, so:

- **Leg one** (`lg_fc_referenced_ids`) walks postmeta **structure** for integer
  attachment ids. A render-time change cannot touch it.
- **Leg two** matches the filename **stem** against
  `_lg_layout_v2_rendered_html` (`lg-frontend-compose.php:1294`). The stem
  survives `rawurlencode` intact — WordPress sanitizes filenames to
  `[a-z0-9._-]`, so only the `/` separators encode. And that meta is written by
  the WP plugin copy, which this lane does not touch.

Safe on both counts. **The next person changing image URLs needs to know the
collector reads them.**

## Noticed, not fixed

1. **4 posts will show broken photos on dev2** (`bridge-wing-flattener` +3
   heroes): 11 of 1,196 live-hosted media files do not exist on dev2 in any form.
   They render today only by fetching from production. Full reasoning — and why
   keeping the live host would have been a **false green** on 436 posts — is in
   `docs/domains/PAGE.md`. **Remedy is data: copy the 11 files into the dev
   bucket, or re-materialize those 4 posts on dev2. Live is unaffected.**
2. **A broken image is not a craft-gate violation** — `check()` skips
   `naturalWidth == 0`. That is how #1's sibling (fret-sander-v2's related card,
   broken on `main` today) went unnoticed.
3. **Author avatars are served over `http://`** on an https page — mixed content
   — and the shell's footer logo is hardcoded to `https://loothgroup.com`. Left
   raw on purpose: site chrome, and one stores a host whose file dev2 lacks.
4. **Gate 69's `--path` is a template, not a prefix**, and a worktree preview
   needs `platform/config/frontend-compose.local.php` copied in (gitignored,
   serving-checkout only). Both produce failures that read like real regressions.

## For Ian

    TEST-URL: /preview/187-loothprint-images/loothprint/fret-sander-v2/
    ACTION: Look at a Loothprint page — same photos, under half the download

The preview is **UP** and serves this branch; main's `/loothprint/` route is
untouched and still serves main, so the two can be compared side by side.
Tear down with `tools/preview/lane-preview.sh down 187-loothprint-images`.
