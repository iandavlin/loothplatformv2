# reply-images-count previs — how many photos on a discussion reply

*`reply-images-count` lane, 2026-07-27. Findings doc:
`docs/atlas/REPLY-IMAGE-COUNT-CEILING.md`.*

**Deck (behind the dev gate):**
https://dev2.loothgroup.com/mockups/reply-images/index.html

## What is in the deck

A reply stub carrying 1, 2, 3, 5, 9 and 20 photos, on the phone (390px replies
sheet) and desktop (760px discussion modal), plus the ugly cases: a single image,
a real 4-photo reply with one portrait among landscapes (reply 58510, unstaged),
five all-portrait phone shots, and one page of five replies all carrying photos —
which is the real payload unit, because the replies fragment serves five stubs
per fetch.

Two proposals: **A** (grid, six tiles shown, "+N" for the rest) and **B**
(swipe carousel on phone, full grid on desktop, no truncation).

## Honest by construction

The frames `<link>` the real `/hub/forums.css`, use the `.reply-stub` markup
copied out of `bb_mirror_render_reply_stub()`, and pull real attachments already
in `forums.attachment` through the real `/img.php` resizer at the widths a tile
would actually request. The only invented CSS is `.reply-stub__gallery` — that
is the thing being decided.

Two artefacts of this box, neither part of the design: nginx injects the site's
PWA bottom-nav into every HTML page here (the deck sandboxes its iframes, so you
only see it if you open a frame directly), and the deck's own chrome is plain
mockup styling, not product styling.

## Regenerating

```sh
python3 gen.py out
sudo -u looth-dev cp index.html out/*.html out/manifest.json /var/www/dev/mockups/reply-images/
```

`/var/www/dev/mockups/` is a shared, group-writable directory that already holds
other lanes' decks. Adding a subdirectory is additive — no served file is
mutated, no `/srv` symlink is repointed, no fpm reload — so it does **not** need
an overlay serve window.

## Shooting + measuring

`shoot.py` drives a real browser over CDP, probes each frame (gallery count,
tile width, the srcset candidate actually picked, gallery height, stub height,
broken tiles) and writes a PNG. dev2 is 3.8 GB at a 4-lane cap, so it takes an
explicit frame list and runs one browser — never a fleet.

```sh
PROF=$(mktemp -d)
/opt/lg-chrome/chrome-linux64/chrome --headless=new --no-sandbox --disable-gpu \
  --disable-dev-shm-usage --user-data-dir=$PROF --remote-debugging-port=9333 \
  --remote-allow-origins='*' \
  --host-resolver-rules="MAP dev2.loothgroup.com 127.0.0.1" about:blank &
sleep 5
python3 shoot.py 9333 shots a09--phone:390:844 b20--desk:820:900
pkill -f 'remote-debugging-port=9333'; rm -rf $PROF
```

`--host-resolver-rules` puts the browser on loopback, which the dev gate
authorizes (`geo $loothdev_src_local`) — no cookie, no trip through the CF edge.
Chrome dies across tool calls, so launch + drive + kill must be one shell call.

The probe earns its keep: it caught the first cut declaring a flat
`sizes="33vw"` for every layout, which made the half-width 2-up and 4-up tiles
pull the `w=800` candidate — 79 KB for a 160px tile. Assert the candidate the
browser *picked* (`img.currentSrc`), never just that a `srcset` exists.
