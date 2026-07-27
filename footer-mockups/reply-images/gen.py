#!/usr/bin/env python3
"""
reply-images-count previs generator — BUILD stage (Ian's ruling: max 6).

Every frame is the ACTUAL SHIPPED OUTPUT. The stub markup is not written here:
it comes out of `render-harness.php`, which requires the shipping
`_reply-render.php` and runs the shipping `_topic-replies.php` query against the
live mirror. The stylesheet is not written here either — it is this branch's
`bb-mirror/web/forums.css`, copied in whole. So what Ian sees in these frames is
what the hub will render, not a drawing of it.

(The exploration deck this replaces used hand-written markup because there was
no implementation yet. There is one now, so nothing here is hand-written.)

Run:  python3 gen.py out
Needs mirror read access for the harness, so it shells out via sudo -u postgres.
"""
import json, os, subprocess, sys, shutil

OUT = sys.argv[1] if len(sys.argv) > 1 else 'out'
HERE = os.path.dirname(os.path.abspath(__file__))
CSS_SRC = os.path.join(HERE, '..', '..', 'bb-mirror', 'web', 'forums.css')


def harness(*args) -> str:
    """Real renderer, real rows. Fails loudly — a silent empty frame is a lie."""
    r = subprocess.run(['sudo', '-u', 'postgres', 'php', 'render-harness.php', *map(str, args)],
                       cwd=HERE, capture_output=True, text=True)
    if r.returncode != 0 or not r.stdout.strip():
        raise SystemExit(f'harness failed for {args}:\n{r.stderr[:800]}')
    return r.stdout.strip()


FRAME = '''<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<!-- this branch's forums.css, copied whole — the gallery rules are in it -->
<link rel="stylesheet" href="forums.css">
<style>
  html,body{{margin:0;background:var(--bg,#f7f4ec)}}
  body{{font-family:var(--font-body,-apple-system,sans-serif)}}
  .frame{{width:{width}px;margin:0 auto;background:var(--bg-card,#fff);
    min-height:100vh;padding:{pad};box-sizing:border-box}}
  .frame__cap{{font:600 11px/1.4 var(--font-body,sans-serif);letter-spacing:.6px;
    text-transform:uppercase;color:var(--fg-muted,#7d7a70);margin:0 0 10px}}
</style></head><body>
<div class="frame"><p class="frame__cap">{cap}</p>{stubs}</div>
</body></html>
'''


def frame(name, title, cap, stubs, surface):
    width = 390 if surface == 'phone' else 760
    pad = '10px 16px 24px' if surface == 'phone' else '16px 18px 24px'
    open(os.path.join(OUT, name), 'w').write(
        FRAME.format(title=title, width=width, pad=pad, cap=cap, stubs=stubs))
    return name


os.makedirs(OUT, exist_ok=True)
shutil.copyfile(CSS_SRC, os.path.join(OUT, 'forums.css'))
FRAMES = []

# 1..6 — the counts Ian ruled on
for n in range(1, 7):
    html = harness('synth', n)
    label = '1 image — unchanged, NOT a gallery' if n == 1 else f'{n} images'
    for surface in ('phone', 'desk'):
        FRAMES.append(frame(f'n{n}--{surface}.html', f'{n} images · {surface}',
                            f'{label} — {"390px replies sheet" if surface == "phone" else "760px discussion modal"}',
                            html, surface))

# the over-limit case: a legacy reply that holds more than the cap
over = harness('over', 6, 11)
for surface in ('phone', 'desk'):
    FRAMES.append(frame(f'over--{surface}.html', f'Over the limit · {surface}',
                        'OVER THE LIMIT — a legacy reply holding 11; shows 6 + “+5”',
                        over, surface))

# before/after on a real reply that has been hiding images since September 2025
real = harness('stubs', 58510)
for surface in ('phone', 'desk'):
    FRAMES.append(frame(f'real58510--{surface}.html', f'Reply 58510 · {surface}',
                        'REAL REPLY 58510 — 4 photos stored since Sep 2025, 1 ever shown',
                        real, surface))

# the payload unit: five stubs, which is what one "Load 5 more" actually serves
page = '\n'.join(harness('synth', n) for n in (6, 3, 2, 5, 4))
for surface in ('phone', 'desk'):
    FRAMES.append(frame(f'page5--{surface}.html', f'Page of 5 · {surface}',
                        'ONE FETCH — the replies fragment serves 5 stubs at a time',
                        page, surface))

json.dump(FRAMES, open(os.path.join(OUT, 'manifest.json'), 'w'), indent=1)
print(f'{len(FRAMES)} frames -> {OUT}')
