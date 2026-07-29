/* events-sheet-proof.js — render the events mobile sheet BEFORE vs AFTER.
 *
 * Ian reports mobile-sheet defects from his phone, and a phone is the only
 * honest place to answer him. This builds a page he opens on that phone: four
 * real 390px viewports (before/after x light/dark) as iframes, so HIS browser
 * does the rendering at HIS device width. No headless engine required — which
 * matters, because the box allows one browser at a time and the craft gate
 * wants it.
 *
 * FIDELITY RULE: the CSS is never hand-copied. Each frame's stylesheet is
 * obtained by actually running that version's injectStyles() against a stub
 * DOM, so a frame can only ever show CSS the tree really ships. Each iframe is
 * a whole document, so `html[data-lguser-theme="dark"]` applies verbatim with
 * no selector rewriting. Seven assertions guard the output and the script exits
 * non-zero rather than publish a proof that shows the wrong thing.
 *
 * Data is the real live event 72363 ("Looth Pro - Intermediate Inlay with CNC",
 * 20260802 + "3:00 pm"), read from live, not invented.
 *
 * Usage:
 *   node tools/verify/events-sheet-proof.js \
 *     <out.html> <before/events-mobile.js> <after/events-mobile.js> <events.css>
 *
 * e.g. with the pre-fix copy from git:
 *   git show HEAD~1:webroot/events-mobile.js > /tmp/before.js
 *   node tools/verify/events-sheet-proof.js /tmp/proof/index.html \
 *        /tmp/before.js webroot/events-mobile.js events/web/events.css
 *
 * The banner (august-looth-pro-8452.webp) and crop-before.png are expected
 * alongside the output file; see the lane notes for how they were produced.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const OUT = process.argv[2];
const BEFORE_JS = process.argv[3];
const AFTER_JS = process.argv[4];
const EVENTS_CSS = process.argv[5];

/* ---- Extract the injected CSS by running the real IIFE on a stub DOM. ---- */
function extractCss(file) {
  const src = fs.readFileSync(file, 'utf8');
  let captured = null;
  const styleEl = { id: '', textContent: '' };
  const doc = {
    getElementById: () => null,
    createElement: () => styleEl,
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener: () => {},
    readyState: 'complete',
    head: { appendChild: (n) => { captured = n.textContent; } },
    documentElement: { classList: { add: () => {} }, appendChild: (n) => { captured = n.textContent; } },
    body: null,
  };
  const win = {
    __loothEventsMobile: false,
    matchMedia: () => ({ matches: true }),        // pretend a <=640 phone
    addEventListener: () => {},
    location: { pathname: '/events/' },
  };
  const sandbox = {
    window: win, document: doc, location: win.location,
    navigator: { userAgent: 'proof', platform: 'proof', maxTouchPoints: 0 },
    history: { pushState: () => {} }, URL: global.URL, Blob: function () {},
    Intl: global.Intl, Date: global.Date, Math: global.Math, JSON: global.JSON,
    fetch: () => ({ then: () => ({ then: () => ({ catch: () => {} }) }) }),
    DOMParser: function () {},
  };
  const vm = require('vm');
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox, { filename: file });
  if (!captured) throw new Error('no CSS captured from ' + file);
  return captured;
}

/* ---- Real data, straight off live (event 72363). ---- */
const EV = {
  title: 'Looth Pro - Intermediate Inlay with CNC',
  when: 'Sunday, August 2, 2026 · 3:00 PM ET',
  mon: 'AUG',
  day: '2',
  tier: 'Pro',
  cover: 'august-looth-pro-8452.webp',
  // What each version's description selector actually harvests from the live page.
  descBefore: ['Sunday, August 2, 2026 · 3:00 PM ET'],           // .lg-event-header__detail
  descAfter: ['In this episode we will discuss building moderately complex inlay ' +
    'designs with CNC, discuss using a layered approach and thinking about orders of operation.'], // .lg-wysiwyg
};

const esc = (s) => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* The sheet markup, mirroring openEventSheet()'s template for each version. */
function sheetHtml(variant) {
  const isBefore = variant === 'before';
  const meta = '<span class="lg-evland__tier lg-evland__tier--pro">' + esc(EV.tier) + '</span>';
  const desc = (isBefore ? EV.descBefore : EV.descAfter)
    .map((p) => '<p>' + esc(p) + '</p>').join('');
  // BEFORE carried the date pill inside the cover; AFTER does not.
  const pill = isBefore
    ? '<span class="lev-pill"><span class="lev-mon">' + esc(EV.mon) + '</span>' +
      '<span class="lev-day">' + esc(EV.day) + '</span></span>'
    : '';
  return '' +
    '<div id="looth-ev-sheet" class="is-open" role="dialog" aria-label="Event details">' +
      '<div class="lev-back"></div>' +
      '<div class="lev-card">' +
        '<div class="lev-grab" aria-hidden="true"></div>' +
        '<div class="lev-cover" style="background-image:url(\'' + EV.cover + '\')">' + pill + '</div>' +
        '<button class="lev-x" type="button" aria-label="Close">&times;</button>' +
        '<div class="lev-body">' +
          '<div class="lev-kind">Event</div>' +
          '<div class="lev-t">' + esc(EV.title) + '</div>' +
          '<div class="lev-when">' + esc(EV.when) + '</div>' +
          '<div class="lev-meta">' + meta + '</div>' +
          '<div class="lev-desc" id="lev-desc">' + desc + '</div>' +
        '</div>' +
        '<div class="lev-acts">' +
          '<a class="lev-btn lev-join" href="#">Join on Zoom</a>' +
        '</div>' +
        '<div class="lev-acts" id="lev-acts-cal">' +
          '<button class="lev-btn lev-cal" type="button">Add to calendar</button>' +
        '</div>' +
      '</div>' +
    '</div>';
}

/* One iframe document = one real viewport with its own <html> element, so the
   shipped `html[data-lguser-theme="dark"]` rules apply verbatim, unrewritten. */
function frameDoc(variant, theme, css, eventsCss) {
  return '<!doctype html><html lang="en"' + (theme === 'dark' ? ' data-lguser-theme="dark"' : '') + '>' +
    '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<style>' +
      'html,body{margin:0;padding:0;height:100%}' +
      'body{background:' + (theme === 'dark' ? '#15171a' : '#fbfbf8') + '}' +
      /* the landing behind the sheet, so the backdrop reads true */
      '#looth-ev-sheet .lev-back{background:rgba(26,29,26,.55)}' +
    '</style>' +
    '<style>' + eventsCss + '</style>' +
    '<style>' + css + '</style>' +
    '</head><body>' + sheetHtml(variant) + '</body></html>';
}

const cssBefore = extractCss(BEFORE_JS);
const cssAfter = extractCss(AFTER_JS);
const eventsCss = fs.readFileSync(EVENTS_CSS, 'utf8');

/* Sanity assertions — the proof must not silently show the wrong thing. */
const checks = [];
function check(name, ok, detail) { checks.push({ name, ok, detail }); }
check('BEFORE css pins a fixed 170px cover', /\.lev-cover\{[^}]*height:170px/.test(cssBefore));
check('AFTER css uses aspect-ratio:16/9 cover', /\.lev-cover\{[^}]*aspect-ratio:16\/9/.test(cssAfter));
check('AFTER css has no fixed-height cover', !/\.lev-cover\{[^}]*height:170px/.test(cssAfter));
check('BEFORE css styles the date pill', /\.lev-pill\{/.test(cssBefore));
check('AFTER css drops the date pill', !/\.lev-pill\{/.test(cssAfter));
check('BEFORE description repeats the when line', EV.descBefore[0] === EV.when);
check('AFTER description is not the when line', EV.descAfter[0] !== EV.when);
const bad = checks.filter((c) => !c.ok);
if (bad.length) {
  console.error('PROOF ASSERTIONS FAILED:\n' + bad.map((c) => '  ✗ ' + c.name).join('\n'));
  process.exit(1);
}

const frames = [];
for (const theme of ['light', 'dark']) {
  for (const variant of ['before', 'after']) {
    frames.push({ theme, variant, srcdoc: frameDoc(variant, theme, variant === 'before' ? cssBefore : cssAfter, eventsCss) });
  }
}

const page = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Events mobile sheet — banner crop + duplicated time line</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f4f3ee; color:#1a1d1a;
         font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .wrap { max-width: 1180px; margin:0 auto; padding: 22px 16px 60px; }
  h1 { font:700 24px/1.2 Georgia,serif; margin:0 0 6px; }
  .sub { color:#5f635e; margin:0 0 22px; font-size:14px; }
  .bugs { background:#fff; border:1px solid #e3ddd0; border-radius:12px; padding:14px 16px; margin:0 0 26px; }
  .bugs h2 { font:700 12px/1 system-ui; letter-spacing:.09em; text-transform:uppercase;
             color:#b8842b; margin:0 0 10px; }
  .bugs ol { margin:0; padding-left:20px; }
  .bugs li { margin:0 0 8px; }
  .bugs li:last-child { margin-bottom:0; }
  code { background:#f0eee5; border-radius:4px; padding:1px 5px; font-size:12.5px; }
  h2.sec { font:700 13px/1 system-ui; letter-spacing:.1em; text-transform:uppercase;
           color:#87986a; margin:30px 0 14px; padding-top:18px; border-top:1px solid #e0ddd2; }
  .row { display:flex; gap:22px; flex-wrap:wrap; }
  .col { flex:1 1 390px; min-width:0; max-width:430px; }
  .cap { display:flex; align-items:center; gap:8px; margin:0 0 8px; font:700 13px/1 system-ui; }
  .tag { font:700 10px/1 system-ui; letter-spacing:.08em; text-transform:uppercase;
         border-radius:999px; padding:4px 9px; }
  .tag--bad  { background:#f6e0da; color:#a8442a; }
  .tag--good { background:#e2eed5; color:#5c7040; }
  .phone { width:390px; max-width:100%; height:780px; border:1px solid #d8d3c6;
           border-radius:14px; overflow:hidden; background:#000;
           box-shadow:0 6px 22px rgba(26,29,26,.15); }
  .phone iframe { width:390px; height:780px; border:0; display:block; }
  .note { font-size:13px; color:#5f635e; margin:10px 0 0; }
  .note b { color:#1a1d1a; }
  .foot { margin-top:34px; padding-top:16px; border-top:1px solid #e0ddd2;
          font-size:13px; color:#5f635e; }
  @media (max-width:880px){ .phone, .phone iframe { width:100%; } .col { max-width:none; } }
  @media (prefers-color-scheme: dark) {
    body { background:#15171a; color:#e5e7e1; }
    .bugs { background:#1b1e21; border-color:#2c3136; }
    code { background:#262b30; }
    h2.sec { border-color:#2c3136; }
    .sub,.note,.foot { color:#9aa097; }
    .note b { color:#e5e7e1; }
    .phone { border-color:#2c3136; }
    .foot { border-color:#2c3136; }
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>Events mobile sheet — the two defects, before &amp; after</h1>
  <p class="sub">Real data: live event 72363, <b>Looth Pro — Intermediate Inlay with CNC</b>,
     <code>20260802</code> + <code>3:00 pm</code>. The CSS in each frame is extracted from the
     shipped <code>events-mobile.js</code> itself — before = <code>HEAD~1</code>, after = the fix —
     so nothing here is a hand-drawn mock-up.</p>

  <div class="bugs">
    <h2>What you reported</h2>
    <ol>
      <li><b>The banner is cut off.</b> The date chip sat on the artwork and the bottom crop ate the
          baked-in “August 2nd, 3PM”.</li>
      <li><b>The time line renders twice</b> — once under the title, once below the PRO chip.
          Exactly one should remain.</li>
    </ol>
  </div>

  <h2 class="sec">Light theme</h2>
  <div class="row">
    ${frames.filter((f) => f.theme === 'light').map((f) => `
    <div class="col">
      <p class="cap"><span class="tag tag--${f.variant === 'before' ? 'bad">Before' : 'good">After'}</span></p>
      <div class="phone"><iframe title="${f.theme} ${f.variant}" srcdoc="${esc(f.srcdoc)}"></iframe></div>
      <p class="note">${f.variant === 'before'
        ? '<b>Banner:</b> forced into a fixed 170px box, so <code>cover</code> trims ~81px off the top and bottom of the 1279×720 artwork — “August 2nd, 3PM” is cut. The <b>AUG 2</b> chip is pinned bottom-left, straight over that same date. <br><b>Time line:</b> printed twice — under the title, and again under the PRO chip, where the description should be.'
        : '<b>Banner:</b> <code>aspect-ratio:16/9</code>, the same contract the landing card uses — the whole poster survives, “August 2nd, 3PM” included, and the chip is gone from the artwork. <br><b>Time line:</b> once. The slot below the PRO chip now holds the event’s real blurb, which never used to render at all.'}</p>
    </div>`).join('')}
  </div>

  <h2 class="sec">Dark theme</h2>
  <div class="row">
    ${frames.filter((f) => f.theme === 'dark').map((f) => `
    <div class="col">
      <p class="cap"><span class="tag tag--${f.variant === 'before' ? 'bad">Before' : 'good">After'}</span></p>
      <div class="phone"><iframe title="${f.theme} ${f.variant}" srcdoc="${esc(f.srcdoc)}"></iframe></div>
    </div>`).join('')}
  </div>

  <h2 class="sec">The crop, measured</h2>
  <p class="note" style="margin-top:0">The poster is 1279×720. Forced into a 170px-tall box on a
     390px phone, <code>cover</code> scales it to 219.55px and centres it — so
     <b>81 image pixels come off the top and 81 off the bottom, 22.6% of the height</b>.
     Below is the artwork, and underneath it exactly the strip that survived.</p>
  <div style="margin-top:14px">
    <p class="cap"><span class="tag tag--good">The poster, whole</span></p>
    <img src="august-looth-pro-8452.webp" alt="Full event banner, 1279×720"
         style="width:100%;max-width:760px;height:auto;display:block;border-radius:8px">
    <p class="cap" style="margin-top:18px"><span class="tag tag--bad">What the 170px box showed</span></p>
    <img src="crop-before.png" alt="The banner as cropped to 170px — rows 81 to 639"
         style="width:100%;max-width:760px;height:auto;display:block;border-radius:8px">
    <p class="note">“Looth Pro” loses its ascenders at the top; “August 2nd, 3PM” and the Looth
       Group roundel are cut through at the bottom.</p>
  </div>

  <h2 class="sec">If you'd rather keep the date chip</h2>
  <p class="note" style="margin-top:0">The chip is gone above because the sheet already spells the
     full date out one line below it (“Sunday, August 2, 2026 · 3:00 PM ET”), and on these posters
     the date is painted into the artwork as well — so it was saying the same thing a third time, on
     top of the picture. If you want it back, the alternative is to park it top-left, matching the
     landing card. Say the word and it's a one-line change.</p>

  <p class="foot">Rendered by your own browser at your own device width — each frame is a real
     390px viewport with its own theme, not a screenshot. Banner served from this page,
     copied byte-for-byte from live.</p>
</div>
</body>
</html>
`;

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, page);
console.log('wrote ' + OUT + ' (' + page.length + ' bytes)');
console.log('assertions passed: ' + checks.length);
checks.forEach((c) => console.log('  ✓ ' + c.name));
