#!/usr/bin/env python3
"""Offline verify harness for the captions+accordions BUILD (mock tooling — never
shipped). Extracts the REAL CSS + the three REAL script modules (lightbox,
accordions, gallery editor) out of profile-app/web/u.php, wraps them around a
realistic /u/ DOM with window.fetch stubbed, and runs behavioral assertions whose
results land in <title> (read with chrome --headless --dump-dom).

  python3 build-harness.py [out.html]      # writes the harness next to this file
  chrome --dump-dom 'file://…/build-harness.html?view=owner'          → assertions
  chrome --screenshot 'file://…?view=visitor&noassert=1&theme=dark'   → clean frame

Params: view=visitor|owner · theme=light|dark · expand=1 · recollapse=1 ·
midedit=1 (stop at the mid-edit state for a shot) · noassert=1 (clean frames).
dev1 rule: one chrome at a time, serial, RAM-flag the board first.
"""
import re, sys, pathlib

HERE = pathlib.Path(__file__).resolve().parent
U = (HERE / '../../profile-app/web/u.php').resolve().read_text()

css = re.search(r'<style>\n(/\* Block-model /u/ render.*?)\n</style>', U, re.S).group(1)

def script(marker: str) -> str:
    m = re.search(r'<script>\n(/\* ' + re.escape(marker) + r'.*?)\n</script>', U, re.S)
    assert m, 'script block not found: ' + marker
    return m.group(1)

lightbox = script('Gallery lightbox')
accord   = script('Taxonomy accordions')
editor   = script('Gallery editor (owner/Me)')

TEMPLATE = """<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>harness</title>
<style>
:root{--lg-cream:#fbfbf8;--lg-sage:#87986a;--lg-sage-d:#6b7c52;--lg-sage-3:#d4e0b8;--lg-sage-tint:#eef2e3;
--lg-amber:#ecb351;--lg-rust:#c66845;--lg-charcoal:#1a1d1a;--lg-ink:#323532;--lg-mute:#6b6f6b;--lg-line:#e3ddd0;
--lg-font-serif:"Lora",Georgia,serif;--lg-font-sans:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;--lg-card-bg:#fff}
html[data-lguser-theme="dark"]{--lg-cream:#15171a;--lg-card-bg:#1e2124;--lg-ink:#e5e7e1;--lg-mute:#a6ac9f;
--lg-line:#2c312d;--lg-sage:#9cb37d;--lg-sage-d:#b0c693;--lg-sage-3:#2f3d2c;--lg-sage-tint:#243024;--lg-charcoal:#f2f4ee}
html[data-lguser-theme="dark"] body{background:var(--lg-cream)}
</style>
<style>
__CSS__
</style></head><body>
<div class="lg-shell"><div class="lg-profile" id="stage"></div></div>
<script>
// REAL code under test is injected below; DOM is built here, fetch is stubbed.
var PUTS = [];
window.fetch = function (url, opts) {
  PUTS.push({ url: url, body: opts && opts.body ? JSON.parse(opts.body) : null, method: opts && opts.method });
  return Promise.resolve({ ok: true, json: function () { return Promise.resolve({ ok: true }); } });
};
var q = new URLSearchParams(location.search);
document.documentElement.setAttribute('data-lguser-theme', q.get('theme') || 'light');
var VIEW = q.get('view') || 'visitor';

var INST = ['Archtop guitar','Flat-top steel-string','Classical / nylon','Electric solid-body','Semi-hollow','Bass guitar','Mandolin','Ukulele','Banjo','Resonator','Lap steel','Violin family','Cello','Double bass','Harp guitar','Weissenborn'];
var SKILL = ['Fret work','Refretting','Setups','Nut & saddle','Neck resets','Crack repair','Brace repair','Bridge reglue','French polish','Nitro finishing','UV-cure finishing','Binding & purfling','Inlay & engraving','Headstock repair','Structural restoration','Electronics & wiring','Pickup winding','CNC & CAD','3D printing'];
var MUSIC = ['Jazz','Blues','Bluegrass','Classical','Folk','Rockabilly'];
function chipBlock(key, title, items) {
  return '<section class="block lg-block lg-block--' + key + '" data-block="' + key + '"><h3 class="lg-bh">' + title + '</h3>' +
    '<div class="lg-chips">' + items.map(function (n) { return '<span class="lg-chip">' + n + '</span>'; }).join('') + '</div></section>';
}
var PHOTOS = [
  { u: '/profile-media/gallery/u/a.webp', c: 'Carved spruce top — 38 L-5 copy, tap-tuned', bg: 'radial-gradient(ellipse at 48% 42%,#e8a94f,#6e3a16 78%)' },
  { u: '/profile-media/gallery/u/b.webp', c: '', bg: 'linear-gradient(160deg,#5a3624,#2a1812)' },
  { u: '/profile-media/gallery/u/c.webp', c: 'Refret — 62 Jazzmaster', bg: 'linear-gradient(135deg,#8b8f94,#5a5e63)' },
];
function galleryBlock(owner) {
  return '<section class="block lg-block lg-block--gallery" data-block="gallery" data-g="1"><h3 class="lg-bh">On the bench</h3>' +
    '<div class="lg-gallery lg-gallery--grid' + (owner ? ' lg-gallery--edit' : '') + '">' +
    PHOTOS.map(function (p) {
      var f = '<figure class="lg-gphoto" data-url="' + p.u + '"><img alt="' + p.c + '" style="background:' + p.bg + '" width="400" height="400">';
      if (owner) f += '<button type="button" class="lg-gphoto__rm" aria-label="Remove">×</button>';
      if (owner) f += '<figcaption class="lg-gcap' + (p.c ? '' : ' lg-gcap--empty') + '" title="Click to edit caption">' + (p.c || '＋ Add caption') + '</figcaption>';
      else if (p.c) f += '<figcaption>' + p.c + '</figcaption>';
      return f + '</figure>';
    }).join('') +
    (owner ? '<button type="button" class="lg-gphoto__add" aria-label="Add photos">＋</button>' : '') +
    '</div></section>';
}
var stage = document.getElementById('stage');
stage.innerHTML = (VIEW === 'owner' ? galleryBlock(true) : galleryBlock(false)) +
  chipBlock('instruments', 'Instruments', INST) + chipBlock('skills', 'Skills', SKILL) + chipBlock('music', 'Music', MUSIC);
</script>
<script>
__LIGHTBOX__
</script>
<script>
__ACCORD__
</script>
<script>
__EDITOR__
</script>
<script>
// ---- behavioral assertions -> document.title (read via --dump-dom) ----
var A = [];
function assert(name, cond) { A.push((cond ? 'PASS ' : 'FAIL ') + name); }
addEventListener('load', function () { setTimeout(run, 60); });
function run() {
  var q = new URLSearchParams(location.search);
  if (q.get('noassert')) { document.title = 'frames-only'; return; }
  // accordion geometry: every toggle must END its last visible row
  ['instruments', 'skills', 'music'].forEach(function (k) {
    var wrap = document.querySelector('.lg-block--' + k + ' .lg-chips');
    var btn = wrap.querySelector('.lg-chip--more');
    var vis = Array.prototype.filter.call(wrap.querySelectorAll('.lg-chip:not(.lg-chip--more)'),
      function (c) { return !c.classList.contains('lg-chip--cut'); });
    var cut = wrap.querySelectorAll('.lg-chip--cut').length;
    if (btn) {
      assert(k + ':toggle-on-last-row', btn.offsetTop === vis[vis.length - 1].offsetTop);
      assert(k + ':label', btn.textContent.indexOf('Show all ' + (vis.length + cut)) === 0);
      assert(k + ':worth-it', cut >= 3);
    } else {
      assert(k + ':flat-means-fits-or-guard', cut === 0);
    }
  });
  // expand round-trip on instruments (if collapsed)
  var iw = document.querySelector('.lg-block--instruments .lg-chips');
  var ib = iw.querySelector('.lg-chip--more');
  if (ib && q.get('expand')) {
    ib.click();
    assert('inst:expanded-all-visible', iw.querySelectorAll('.lg-chip--cut').length === 0);
    var fb = iw.querySelector('.lg-chip--more');
    assert('inst:show-fewer', fb && fb.textContent.indexOf('Show fewer') === 0 && fb.getAttribute('aria-expanded') === 'true');
    if (q.get('recollapse')) { fb.click(); assert('inst:recollapsed', iw.querySelectorAll('.lg-chip--cut').length >= 3); }
  }
  if (VIEW === 'owner') {
    var wrap = document.querySelector('.lg-gallery');
    var caps = wrap.querySelectorAll('figcaption.lg-gcap');
    // owner caption click must NOT open the lightbox
    caps[0].dispatchEvent(new MouseEvent('click', { bubbles: true }));
    assert('cap:no-lightbox-on-owner-click', !document.querySelector('.lg-lightbox'));
    assert('cap:editing-started', caps[0].classList.contains('editing') && caps[0].isContentEditable);
    if (q.get('midedit')) { document.title = A.join(' | '); return; }  // stop here for the mid-edit shot
    // type + commit on the EMPTY tile
    var ghost = caps[1];
    ghost.dispatchEvent(new MouseEvent('click', { bubbles: true }));   // also blurs cap[0] (no change -> no PUT)
    assert('cap:ghost-cleared-on-edit', ghost.textContent === '');
    ghost.textContent = 'Brazilian rosewood back';
    ghost.dispatchEvent(new Event('input', { bubbles: true }));
    ghost.blur();
    setTimeout(function () {
      assert('cap:one-put', PUTS.length === 1);
      var imgs = PUTS[0] && PUTS[0].body && PUTS[0].body.images;
      assert('cap:put-carries-new-caption', imgs && imgs[1] && imgs[1].caption === 'Brazilian rosewood back');
      assert('cap:put-keeps-others', imgs && imgs[0] && imgs[0].caption === PHOTOS[0].c && imgs[2].caption === PHOTOS[2].c);
      assert('cap:strip-settled', !ghost.classList.contains('lg-gcap--empty') && ghost.textContent === 'Brazilian rosewood back');
      assert('cap:alt-live', document.querySelectorAll('.lg-gphoto img')[1].alt === 'Brazilian rosewood back');
      // ESC cancels
      var c0 = caps[0];
      c0.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      c0.textContent = 'SHOULD NOT SAVE';
      c0.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      setTimeout(function () {
        assert('cap:esc-restores', c0.textContent === PHOTOS[0].c && PUTS.length === 1);
        // visitor-path lightbox still opens from a photo click
        document.querySelector('.lg-gphoto img').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        assert('lb:opens-on-photo-click', !!document.querySelector('.lg-lightbox'));
        assert('lb:ghostless-cap', (document.querySelector('.lg-lightbox__cap') || {}).textContent === PHOTOS[0].c);
        document.title = A.join(' | ');
      }, 30);
    }, 30);
    return;
  }
  // visitor: caption click opens lightbox, ghost never renders
  assert('visitor:no-ghost-strips', !document.querySelector('.lg-gcap--empty'));
  var vcap = document.querySelector('.lg-gphoto figcaption');
  vcap.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  assert('visitor:caption-click-opens-lightbox', !!document.querySelector('.lg-lightbox'));
  document.title = A.join(' | ');
}
</script>
</body></html>
"""

out = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else HERE / 'build-harness.html')
html = (TEMPLATE.replace('__CSS__', css).replace('__LIGHTBOX__', lightbox)
                .replace('__ACCORD__', accord).replace('__EDITOR__', editor))
out.write_text(html)
print('wrote', out, len(html))
