#!/usr/bin/env python3
"""
reply-images-count previs generator.

Emits static frames of a hub reply stub carrying N images, at the two real
surfaces (mobile replies sheet 390px, desktop discussion modal 760px panel /
724px content), plus the deck index.

Honest by construction:
  - the frames <link> the REAL /forums.css off dev2 — no re-drawn styles
  - the stub markup is copied from bb_mirror_render_reply_stub()
  - every image is a REAL attachment already in forums.attachment, fetched
    through the REAL resizer (/img.php?s=&w=) at the widths a tile would ask for

The only invented CSS is `.reply-stub__gallery` — that IS the proposal.

Run:  python3 gen.py <outdir>
"""
import json, os, sys, html

OUT = sys.argv[1] if len(sys.argv) > 1 else 'out'

# ---- real images, verified present on the dev2 uploads volume ---------------
# (w, h, uploads-relative path). Chosen for aspect mix, not cherry-picked pretty.
LAND = [
    (1200, 675, 'bb_medias/2026/05/1000046438.jpg'),
    (1200, 834, 'bb_medias/2025/01/IMG_0004.jpg'),
    (1200, 900, 'bb_medias/2025/08/20250807_072002.jpg'),
    (1200, 675, 'bb_medias/2025/10/IMG_20251008_125113716.jpg'),
    (1200, 900, 'bb_medias/2023/09/IMG_5085.jpeg'),
    (1200, 900, 'bb_medias/2025/01/IMG_4860.jpeg'),
]
PORT = [
    (600,  800,  'bb_medias/2024/05/IMG_7054.JPG.jpeg'),
    (480,  640,  'bb_medias/2026/03/Top-on-form.jpeg'),
    (904,  1200, 'bb_medias/2024/06/1000002088.jpg'),
    (900,  1200, 'bb_medias/2023/09/02A64F06-DE32-4345-AED8-90CFABF33AB6.jpeg'),
    (900,  1200, 'bb_medias/2024/07/IMG_2653.jpeg'),
    (612,  792,  'bb_medias/2025/08/1000011599.jpg'),
]
SQ = [
    (1200, 1154, 'bb_medias/2023/11/20231108_083155.jpg'),
    (862,  972,  'bb_medias/2024/10/Gibson_1.jpg'),
    (800,  874,  'bb_medias/2026/04/TEISCO.jpg'),
    (640,  562,  'bb_medias/2026/05/IMG_1483.jpg'),
]
# reply 58510 — a real 4-photo Martin repair reply already in the store.
# 3 landscape + 1 portrait: the "one portrait among landscapes" case, unstaged.
REAL_58510 = [
    (1200, 900,  'bb_medias/2025/09/20181008_174214.jpg'),
    (1200, 900,  'bb_medias/2025/09/20181008_174214-1.jpg'),
    (1200, 900,  'bb_medias/2025/09/20181019_162934.jpg'),
    (900,  1200, 'bb_medias/2025/09/20190105_132533-rotated.jpg'),
]

MIX = []
for i in range(24):
    MIX.append([LAND, PORT, SQ, LAND, LAND, PORT][i % 6][i // 6 % 4 % len(LAND)])


def pool(n, kind='mix'):
    if kind == 'real':  return REAL_58510[:n]
    if kind == 'port':  return [PORT[i % len(PORT)] for i in range(n)]
    if kind == 'land':  return [LAND[i % len(LAND)] for i in range(n)]
    src = LAND + PORT + SQ
    return [src[i % len(src)] for i in range(n)]


def img_src(path, w):
    from urllib.parse import quote
    return f'/img.php?s={quote(path, safe="")}&amp;w={w}'


# ---------------------------------------------------------------- the proposal
GALLERY_CSS = """
/* ===== PROPOSAL — .reply-stub__gallery =====================================
   The only invented CSS in this deck. Everything else is live /forums.css.
   6-column base grid + span rules, so every count fills its rows with no
   orphan gap. Single image is NOT a gallery — it keeps today's bare
   <img class="reply-stub__img"> byte-for-byte, so the 275 one-image replies
   in the store render exactly as they do now.                              */
.reply-stub__gallery{
  display:grid; grid-template-columns:repeat(6,1fr); gap:3px;
  margin-top:6px; border-radius:8px; overflow:hidden;
}
/* thirds are the default span, so any count with no rule of its own (7, 11, 20
   in option B) lays out as a 3-up grid rather than collapsing to 1/6-width slivers */
.reply-stub__gallery a{ display:block; position:relative; margin:0; line-height:0; grid-column:span 2; }
.reply-stub__gallery img{
  display:block; width:100%; height:100%; object-fit:cover; aspect-ratio:1/1;
}
/* 2 and 4 -> halves (2-up rows).  3, 5, 6, 7+ -> thirds. 5 = 3 then 2 halves. */
.reply-stub__gallery[data-count="2"] a,
.reply-stub__gallery[data-count="4"] a{ grid-column:span 3; }
.reply-stub__gallery[data-count="2"] img,
.reply-stub__gallery[data-count="4"] img{ aspect-ratio:4/3; }
.reply-stub__gallery[data-count="3"] a{ grid-column:span 2; }
.reply-stub__gallery[data-count="5"] a{ grid-column:span 2; }
.reply-stub__gallery[data-count="5"] a:nth-child(4),
.reply-stub__gallery[data-count="5"] a:nth-child(5){ grid-column:span 3; }
.reply-stub__gallery[data-count="5"] a:nth-child(4) img,
.reply-stub__gallery[data-count="5"] a:nth-child(5) img{ aspect-ratio:4/3; }
.reply-stub__gallery[data-count="6"] a{ grid-column:span 2; }
/* overflow: 6 tiles shown, the 6th wears the +N */
.reply-stub__gallery[data-more] a{ grid-column:span 2; }
.reply-stub__gallery__more{
  position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
  background:rgba(12,14,12,.62); color:#fff; font:700 20px/1 var(--font-head,sans-serif);
  letter-spacing:.5px; backdrop-filter:blur(1px);
}
/* ALTERNATIVE (option B) — mobile swipe carousel, mirroring the ALREADY-SHIPPED
   .post__attachments behaviour on the single-topic page (forums.css:3537). */
.reply-stub__gallery--carousel{
  display:flex; grid-template-columns:none; gap:6px;
  overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;
  border-radius:8px;
}
.reply-stub__gallery--carousel a{ flex:0 0 62%; scroll-snap-align:start; }
.reply-stub__gallery--carousel img{ aspect-ratio:4/3; border-radius:8px; }
"""

# What today actually does, for the honest "before" frames.
TODAY_NOTE = ('Today the reply stub renders <b>one</b> image — <code>_topic-replies.php:53</code> '
              'takes <code>ORDER BY id ASC LIMIT 1</code>. The other images are stored and '
              'invisible.')


def gallery_html(imgs, surface, cap=6, mode='grid'):
    """Render the proposed gallery. cap=None -> show all."""
    n = len(imgs)
    if n == 1:
        w, h, p = imgs[0]
        return (f'<img class="reply-stub__img" src="{img_src(p, 800)}" alt="" '
                f'loading="lazy" width="{w}" height="{h}">')
    shown = imgs if (cap is None or n <= cap) else imgs[:cap]
    more = 0 if (cap is None or n <= cap) else n - cap
    tile_w = 240 if surface == 'phone' else 480
    # `sizes` MUST track the span rules, not the count. Caught by the browser
    # probe: with a flat 33vw, the 2-up and 4-up layouts (half-width tiles) were
    # under-declared and the browser jumped to the 800w candidate — a 79 KB fetch
    # for a 160px tile. Half-width layouts declare 50vw; thirds declare 33vw.
    half = len(shown) in (2, 4)
    if mode == 'carousel':
        vw = '62vw'
    elif surface == 'phone':
        vw = '47vw' if half else '30vw'   # true tile width: (390 - 32 padding - gaps) / n
    else:
        vw = '360px' if half else '240px'
    cls = 'reply-stub__gallery' + (' reply-stub__gallery--carousel' if mode == 'carousel' else '')
    attrs = f' data-count="{len(shown)}"' + (f' data-more="{more}"' if more else '')
    out = [f'<div class="{cls}"{attrs}>']
    for i, (w, h, p) in enumerate(shown):
        overlay = ''
        if more and i == len(shown) - 1:
            overlay = f'<span class="reply-stub__gallery__more">+{more}</span>'
        out.append(
            f'<a href="{img_src(p, 1200)}" target="_blank" rel="noopener">'
            f'<img src="{img_src(p, tile_w)}" '
            f'srcset="{img_src(p, 240)} 240w, {img_src(p, 480)} 480w, {img_src(p, 800)} 800w" '
            f'sizes="{vw}" '
            f'alt="" loading="lazy" decoding="async" width="{w}" height="{h}">{overlay}</a>')
    out.append('</div>')
    return ''.join(out)


AVATAR = ('<img class="avatar-init avatar-init--img" '
          'src="/img.php?s=avatars%2F1%2F6569bdf24feb9-bpthumb.jpg&amp;w=96" '
          'width="28" height="28" alt="" loading="lazy" decoding="async">')

BODY_TEXT = ('I did a repair on this Martin and had to sand the entire side down. '
             'Here is how it went — pictures in order.')


def stub(imgs, surface, cap=6, mode='grid', body=BODY_TEXT, author='Steve Kramer'):
    return f'''<div class="reply-stub" data-reply-id="58510" data-author-id="1372">
  <div class="reply-stub__head">{AVATAR}<a class="reply-stub__author" href="#">{author}</a>
    <time class="reply-stub__time">3d</time>
    <button class="reply-stub__reply" type="button">&#8617; Reply</button>
  </div>
  <div class="reply-stub__body">
    <span class="reply-stub__excerpt">{html.escape(body)}</span>
    {gallery_html(imgs, surface, cap, mode)}
  </div>
  <div class="reply-stub__actions"><div class="fcr"><button class="fcr-btn" type="button">♡ React</button></div></div>
</div>'''


FRAME_TMPL = '''<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<link rel="stylesheet" href="/hub/forums.css">
<style>
  html,body{{margin:0;background:var(--bg,#f7f4ec);}}
  body{{font-family:var(--font-body,-apple-system,sans-serif);}}
  .frame{{width:{width}px;margin:0 auto;background:var(--bg-card,#fff);
    min-height:100vh;padding:{pad};box-sizing:border-box;}}
  .frame__cap{{font:600 11px/1.4 var(--font-body,sans-serif);letter-spacing:.6px;
    text-transform:uppercase;color:var(--fg-muted,#7d7a70);margin:0 0 10px;}}
{gallery_css}
</style></head><body>
<div class="frame"><p class="frame__cap">{cap}</p>{stubs}</div>
</body></html>
'''


def frame(name, title, cap, stubs, surface):
    width = 390 if surface == 'phone' else 760
    pad = '10px 16px 24px' if surface == 'phone' else '16px 18px 24px'
    open(os.path.join(OUT, name), 'w').write(FRAME_TMPL.format(
        title=title, width=width, pad=pad, cap=cap, stubs=stubs, gallery_css=GALLERY_CSS))
    return name


# ------------------------------------------------------------------ frame set
os.makedirs(OUT, exist_ok=True)
FRAMES = []


def both(slug, label, imgs, cap=6, mode='grid', body=BODY_TEXT):
    for surface in ('phone', 'desk'):
        m = 'carousel' if (mode == 'carousel' and surface == 'phone') else 'grid'
        FRAMES.append(frame(f'{slug}--{surface}.html', f'{label} · {surface}',
                            f'{label} — {"390px replies sheet" if surface == "phone" else "760px discussion modal"}',
                            stub(imgs, surface, cap, m, body), surface))


# 0 — today (a 4-image reply as it renders right now: one image, w=800, no dims)
for surface in ('phone', 'desk'):
    w, h, p = REAL_58510[0]
    today = f'''<div class="reply-stub" data-reply-id="58510">
  <div class="reply-stub__head">{AVATAR}<a class="reply-stub__author" href="#">Steve Kramer</a>
    <time class="reply-stub__time">3d</time></div>
  <div class="reply-stub__body"><span class="reply-stub__excerpt">{html.escape(BODY_TEXT)}</span>
  <img class="reply-stub__img" src="{img_src(p, 800)}" alt="" loading="lazy"></div>
</div>'''
    FRAMES.append(frame(f'today--{surface}.html', 'Today · ' + surface,
                        'TODAY — reply 58510 stores 4 images, renders 1',
                        today, surface))

# 1 — the counts, option A (grid, display-cap 6 + "+N")
for n in (2, 3, 5, 9, 20):
    both(f'a{n:02d}', f'Option A — {n} images', pool(n))

# 2 — option B (carousel on phone, grid on desktop, no display cap)
for n in (5, 9, 20):
    both(f'b{n:02d}', f'Option B — {n} images', pool(n), cap=None, mode='carousel')

# 3 — ugly cases
both('ugly-single', 'Single image (unchanged path)', pool(1),
     body='Just the one shot of the headstock.')
both('ugly-real4', 'Real reply 58510 — 3 landscape + 1 portrait', REAL_58510)
both('ugly-portrait', 'All portrait — 5 phone shots', pool(5, 'port'))
both('ugly-mixed9', 'One portrait among landscapes — 9', pool(9))

# 4 — a full page of five replies, every one carrying images (the real payload
#     unit: the replies fragment serves 5 stubs per fetch)
for surface in ('phone', 'desk'):
    stubs = ''.join(stub(pool(k), surface, 6, 'grid',
                         body=f'Reply {i + 1} of the page — {k} photos attached.')
                    for i, k in enumerate((6, 3, 20, 2, 5)))
    FRAMES.append(frame(f'page5--{surface}.html', 'Page of 5 · ' + surface,
                        'THE REAL UNIT — one "Load 5 more" fetch, every reply carrying photos',
                        stubs, surface))

json.dump(FRAMES, open(os.path.join(OUT, 'manifest.json'), 'w'), indent=1)
print(f'{len(FRAMES)} frames -> {OUT}')
