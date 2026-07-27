# reply-images — up to 6 photos on a discussion reply

*`reply-images-count` lane, 2026-07-27. Ian ruled **max 6**. Findings +
implementation notes: `docs/atlas/REPLY-IMAGE-COUNT-CEILING.md`.*

**Deck (behind the dev gate):**
https://dev2.loothgroup.com/mockups/reply-images/

## The frames are the shipped output, not a drawing

`gen.py` does not contain any reply markup. It shells out to
`render-harness.php`, which `require`s the shipping `_reply-render.php` and runs
the shipping `_topic-replies.php` query against the live mirror, and it copies
this branch's `bb-mirror/web/forums.css` in whole. So the frames are the real
renderer, the real query, the real stylesheet and real photos through the real
`/img.php`.

Covers 1–6 images, the over-the-limit case (a reply holding 11 → 6 + "+5"), real
reply 58510 (four photos stored since September 2025, one ever shown), and a page
of five — which is the real payload unit, because the replies fragment serves
five stubs per fetch.

Two artefacts of this box, neither part of the design: nginx injects the site's
PWA bottom-nav into every HTML page here (the deck sandboxes its iframes, so you
only see it if you open a frame directly), and the harness picks attachments by
id rather than curating them, so some tiles are screenshots rather than guitars.

## Verifying — no serve window needed

```sh
sudo -u postgres bash verify.sh        # 36 assertions
```

Runs the real renderer against the live mirror and asserts:

- a **single-image reply renders byte-identically** to the pre-change code —
  diffed against the renderer pulled out of git, not eyeballed
- 2–6 produce the right cell count; 1 produces no gallery at all
- every tile carries `srcset` (3 candidates), `sizes`, intrinsic
  `width`/`height`, `loading=lazy`
- `sizes` tracks the **span rule** in both directions (half-width layouts declare
  half-width and never thirds, and vice versa)
- an over-cap reply shows six tiles and a `+5`
- both server caps sit **before** the writes they guard, and the PUT cap counts
  the resulting set rather than just the additions

`render-harness.php` is the reusable piece — reply markup can be verified, and
previewed, without deploying anything.

Two things that will bite whoever edits `verify.sh`: it is normally run under
`sudo -u postgres` (peer auth is how the mirror is read), so temp files must be
world-readable and `git` needs `-c safe.directory='*'` or it writes an **empty**
baseline that reads as "the render changed"; and `grep -c` counts **lines** while
the renderer emits one line, so occurrence counts need `grep -o | wc -l`.

## Regenerating

```sh
python3 gen.py out
sudo -u looth-dev cp index.html out/*.html out/*.css out/manifest.json \
  /var/www/dev/mockups/reply-images/
```

`/var/www/dev/mockups/` is a shared, group-writable directory that already holds
other lanes' decks. Adding a subdirectory is additive — no served file mutated,
no `/srv` symlink repointed, no fpm reload — so it does **not** need an overlay
serve window.

## Shooting + measuring

`shoot.py` drives a real browser over CDP, probes each frame (gallery count, tile
width, the srcset candidate actually picked, gallery height, stub height, broken
tiles) and writes a PNG. dev2 is 3.8 GB at a 4-lane cap, so it takes an explicit
frame list and runs one browser — never a fleet.

```sh
PROF=$(mktemp -d)
/opt/lg-chrome/chrome-linux64/chrome --headless=new --no-sandbox --disable-gpu \
  --disable-dev-shm-usage --user-data-dir=$PROF --remote-debugging-port=9333 \
  --remote-allow-origins='*' \
  --host-resolver-rules="MAP dev2.loothgroup.com 127.0.0.1" about:blank &
sleep 5
python3 shoot.py 9333 shots n6--phone:390:844 over--desk:820:900
pkill -f 'remote-debugging-port=9333'; rm -rf $PROF
```

`--host-resolver-rules` puts the browser on loopback, which the dev gate
authorizes (`geo $loothdev_src_local`) — no cookie, no trip through the CF edge.
Chrome dies across tool calls, so launch + drive + kill must be one shell call.

The probe earns its keep: it caught a flat `sizes="33vw"` making the half-width
2-up and 4-up tiles pull the `w=800` candidate — 79 KB for a 160 px tile, the
exact over-fetch the gallery exists to end. Assert the candidate the browser
**picked** (`img.currentSrc`), never just that a `srcset` exists.

Measured stub heights, 390 px phone at DPR 2 — 1 image **332 px**, 2 **237**,
3 **223**, 4 **360**, 5 **345**, **6 → 331 px**: a six-photo reply is a pixel
shorter than the single-image reply the hub renders today.
