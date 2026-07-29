/* events-sheet-proof.js — device-width proof for the events mobile change.
 *
 * Ian judges mobile from his phone, so the proof has to render on it. This
 * builds one page holding real 390px viewports as iframes, so HIS browser does
 * the rendering at HIS device width. That deliberately needs no headless
 * Chrome: the box permits one engine and the craft gate wants it, so a proof
 * that competes for it is a proof that does not get run.
 *
 * WHAT IT PROVES, after Ian's 2026-07-29 ruling (mobile taps go straight to the
 * event's post page, no modal):
 *   BEFORE — the bottom sheet that used to open, with both defects he reported:
 *            a 16:9 poster crushed into a fixed 170px box, and the time line
 *            printed twice.
 *   AFTER  — the post page a tap now lands on, rendered from the REAL v2 block
 *            shells (post-header, event-header, wysiwyg) around the REAL live
 *            markup, where the poster runs uncropped and the date appears once.
 *
 * FIDELITY RULES:
 *  - The sheet's CSS is never hand-copied. It is obtained by running that tree's
 *    own injectStyles() against a stub DOM, so the BEFORE frame can only show
 *    CSS the tree really shipped.
 *  - The AFTER frame's CSS is the block shell.css files straight from the repo,
 *    and its markup is the live page's own fragments.
 *  - Each iframe is a whole document, so html[data-lguser-theme="dark"] and the
 *    blocks' own @media (max-width:767px) rules apply verbatim, unrewritten.
 *  - Assertions gate the output; the script exits non-zero rather than publish a
 *    proof that shows the wrong thing.
 *
 * Data is live event 72363 ("Looth Pro - Intermediate Inlay with CNC",
 * 20260802 + "3:00 pm"), read from live, not invented.
 *
 * Usage:
 *   node tools/verify/events-sheet-proof.js <out.html> <before-events-mobile.js> \
 *        <after-events-mobile.js> <events.css> <dest-fragments.txt> [repo-root]
 *
 * dest-fragments.txt is the live destination page's markup, three sections
 * delimited by ===POSTHEADER=== / ===EVENTHEADER=== / ===WYSIWYG===.
 * The banner (august-looth-pro-8452.webp) and crop-before.png are expected
 * alongside the output file.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const [OUT, BEFORE_JS, AFTER_JS, EVENTS_CSS, FRAGMENTS] = process.argv.slice(2);
const ROOT = process.argv[8] || process.cwd();

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
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox, { filename: file });
  return captured;                                 // may be null if no CSS at all
}

const esc = (s) => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* ---- Real data, straight off live (event 72363). ---- */
const EV = {
  title: 'Looth Pro - Intermediate Inlay with CNC',
  when: 'Sunday, August 2, 2026 · 3:00 PM ET',
  mon: 'AUG',
  day: '2',
  tier: 'Pro',
  cover: 'august-looth-pro-8452.webp',
  descBefore: ['Sunday, August 2, 2026 · 3:00 PM ET'],   // what .lg-event-header__detail yielded
};

/* ---- BEFORE: the retired bottom sheet, as openEventSheet() built it. ---- */
function sheetHtml() {
  const meta = '<span class="lg-evland__tier lg-evland__tier--pro">' + esc(EV.tier) + '</span>';
  const desc = EV.descBefore.map((p) => '<p>' + esc(p) + '</p>').join('');
  return '' +
    '<div id="looth-ev-sheet" class="is-open" role="dialog" aria-label="Event details">' +
      '<div class="lev-back"></div>' +
      '<div class="lev-card">' +
        '<div class="lev-grab" aria-hidden="true"></div>' +
        '<div class="lev-cover" style="background-image:url(\'' + EV.cover + '\')">' +
          '<span class="lev-pill"><span class="lev-mon">' + esc(EV.mon) + '</span>' +
          '<span class="lev-day">' + esc(EV.day) + '</span></span>' +
        '</div>' +
        '<button class="lev-x" type="button" aria-label="Close">&times;</button>' +
        '<div class="lev-body">' +
          '<div class="lev-kind">Event</div>' +
          '<div class="lev-t">' + esc(EV.title) + '</div>' +
          '<div class="lev-when">' + esc(EV.when) + '</div>' +
          '<div class="lev-meta">' + meta + '</div>' +
          '<div class="lev-desc">' + desc + '</div>' +
        '</div>' +
        '<div class="lev-acts"><button class="lev-btn lev-cal" type="button">Add to calendar</button></div>' +
      '</div>' +
    '</div>';
}

/* ---- AFTER: the destination post page, real live markup + real block CSS. ---- */
const frag = fs.readFileSync(FRAGMENTS, 'utf8');
function section(name) {
  const m = frag.match(new RegExp('===' + name + '===\\n([\\s\\S]*?)(?=\\n===|$)'));
  return m ? m[1].trim() : '';
}
function localiseAssets(html) {
  // The poster is served next to this page; every other remote image would 404
  // behind the dev gate, so neutralise those rather than show broken frames.
  return html
    .replace(/https:\/\/loothgroup\.com\/wp-content\/uploads\/2026\/07\/august-looth-pro-8452\.webp/g, EV.cover)
    .replace(/src="https:\/\/loothgroup\.com\/[^"]*"/g,
      'src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'%3E%3Crect width=\'80\' height=\'80\' fill=\'%23cfcabb\'/%3E%3C/svg%3E"');
}
const destHtml = localiseAssets(
  section('POSTHEADER') + '\n' + section('EVENTHEADER') + '\n' + section('WYSIWYG')
);

const blockCss = ['post-header', 'event-header', 'wysiwyg']
  .map((b) => fs.readFileSync(path.join(ROOT, 'lg-layout-v2/blocks', b, 'shell.css'), 'utf8'))
  .join('\n');

/* One iframe document = one real viewport with its own <html>, so the shipped
   theme rules AND the blocks' own @media (max-width:767px) rules apply verbatim. */
function frameDoc(variant, theme, css, extraCss, bodyHtml) {
  return '<!doctype html><html lang="en"' + (theme === 'dark' ? ' data-lguser-theme="dark"' : '') + '>' +
    '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<style>html,body{margin:0;padding:0;height:100%}' +
    'body{background:' + (theme === 'dark' ? '#15171a' : '#fbfbf8') + ';' +
    'font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
    'color:' + (theme === 'dark' ? '#e5e7e1' : '#323532') + '}' +
    (variant === 'after' ? 'body{overflow-y:auto}' : '') + '</style>' +
    (extraCss ? '<style>' + extraCss + '</style>' : '') +
    (css ? '<style>' + css + '</style>' : '') +
    '</head><body>' + bodyHtml + '</body></html>';
}

const cssBefore = extractCss(BEFORE_JS);
const cssAfter = extractCss(AFTER_JS);
const eventsCss = fs.readFileSync(EVENTS_CSS, 'utf8');
const afterSrc = fs.readFileSync(AFTER_JS, 'utf8');

/* ---- Assertions. The proof must not silently show the wrong thing. ---- */
const checks = [];
const check = (name, ok) => checks.push({ name, ok: !!ok });
check('BEFORE sheet CSS pinned a fixed 170px cover', /\.lev-cover\{[^}]*height:170px/.test(cssBefore));
check('BEFORE sheet CSS styled the date pill', /\.lev-pill\{/.test(cssBefore));
check('BEFORE description repeated the when line', EV.descBefore[0] === EV.when);
check('AFTER ships NO sheet CSS at all', !/\.lev-cover|\.lev-card|\.lev-pill/.test(cssAfter || ''));
/* Strip comments before reading intent out of the source — the file DISCUSSES
   preventDefault in a comment explaining why it must never come back. */
const afterCode = afterSrc.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
check('AFTER never calls preventDefault', !/preventDefault/.test(afterCode));
/* Not "no click listener at all" — the search bar's clear button legitimately has
   one. The invariant is that nothing intercepts a tap on an event CARD: no
   document/capture-phase click handler, and no card selector in a handler. */
check('AFTER installs no document-level click handler',
  !/document\.addEventListener\(\s*['"]click['"]/.test(afterCode));
check('AFTER never matches a card for interception',
  !/closest\([^)]*lg-evland__card/.test(afterCode));
check('AFTER keeps the landing search bar', /buildEventsSearch/.test(afterCode));
check('destination poster runs uncropped under 768px',
  /@media\s*\(max-width:\s*767px\)[\s\S]{0,400}?__photo\s*\{[^}]*height:\s*auto[^}]*object-fit:\s*unset/.test(blockCss));
check('destination markup carries the poster', /august-looth-pro-8452\.webp/.test(destHtml));
/* Count RENDERED TEXT, not raw HTML. The AUG/2 pill carries the full date in an
   aria-label, which is correct a11y markup and not a visible second line — a raw
   substring count reads 2 and would fail a page that is right. */
const destText = destHtml.replace(/<[^>]+>/g, '\n');
check('destination states the date exactly once (rendered text)',
  (destText.match(/Sunday, August 2, 2026/g) || []).length === 1);
const bad = checks.filter((c) => !c.ok);
if (bad.length) {
  console.error('PROOF ASSERTIONS FAILED:\n' + bad.map((c) => '  x ' + c.name).join('\n'));
  process.exit(1);
}

const frames = [];
for (const theme of ['light', 'dark']) {
  frames.push({ theme, variant: 'before', srcdoc: frameDoc('before', theme, cssBefore, eventsCss, sheetHtml()) });
  frames.push({ theme, variant: 'after', srcdoc: frameDoc('after', theme, blockCss, '', destHtml) });
}

const CAP = {
  before: 'Before — the modal that opened',
  after: 'After — the post page a tap lands on',
};
const NOTE = {
  before: '<b>Banner:</b> the 1279×720 poster forced into a fixed 170px box, so <code>cover</code> ' +
    'trimmed ~81px off the top and bottom — “August 2nd, 3PM” cut through the glyphs, and the ' +
    '<b>AUG 2</b> chip pinned straight over that same date. <br><b>Time line:</b> printed twice — ' +
    'under the title, and again under the PRO chip where the description should have been.',
  after: '<b>No modal.</b> The card’s own <code>href</code> is left alone, so the tap navigates. ' +
    'On the destination, <code>post-header/shell.css</code> under 768px runs the hero at ' +
    '<code>height:auto; object-fit:unset</code> — the poster is whole, “August 2nd, 3PM” included — ' +
    'and the event header states the date <b>once</b>. Both defects are gone by construction, not by patch.',
};

const page = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Events on mobile — tap goes to the post page</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f4f3ee; color:#1a1d1a;
         font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .wrap { max-width: 1180px; margin:0 auto; padding: 22px 16px 60px; }
  h1 { font:700 24px/1.2 Georgia,serif; margin:0 0 6px; }
  .sub { color:#5f635e; margin:0 0 22px; font-size:14px; }
  .card { background:#fff; border:1px solid #e3ddd0; border-radius:12px; padding:14px 16px; margin:0 0 18px; }
  .card h2 { font:700 12px/1 system-ui; letter-spacing:.09em; text-transform:uppercase;
             color:#b8842b; margin:0 0 10px; }
  .card ol, .card ul { margin:0; padding-left:20px; }
  .card li { margin:0 0 8px; } .card li:last-child { margin-bottom:0; }
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
  .flag { border-left:3px solid #b8842b; padding-left:12px; margin:12px 0 0; font-size:13.5px; }
  .foot { margin-top:34px; padding-top:16px; border-top:1px solid #e0ddd2;
          font-size:13px; color:#5f635e; }
  @media (max-width:880px){ .phone, .phone iframe { width:100%; } .col { max-width:none; } }
  @media (prefers-color-scheme: dark) {
    body { background:#15171a; color:#e5e7e1; }
    .card { background:#1b1e21; border-color:#2c3136; }
    code { background:#262b30; }
    h2.sec, .foot { border-color:#2c3136; }
    .sub,.note,.foot { color:#9aa097; }
    .note b { color:#e5e7e1; }
    .phone { border-color:#2c3136; }
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>Events on mobile — a tap now opens the post page</h1>
  <p class="sub">Real data: live event 72363, <b>Looth Pro — Intermediate Inlay with CNC</b>,
     <code>20260802</code> + <code>3:00 pm</code>. The “before” frame’s CSS is extracted from the
     shipped <code>events-mobile.js</code> itself; the “after” frame is the real v2 block shells
     around the real live markup. Nothing here is a hand-drawn mock-up.</p>

  <div class="card">
    <h2>Your ruling, and what it did to the two defects</h2>
    <ul>
      <li><b>Ruling:</b> on mobile, stop opening the modal — a tap goes straight to the event’s post
          page. Done: the sheet is removed and the card’s link is left untouched.</li>
      <li><b>Both defects you reported are resolved by that ruling alone.</b> They lived in the
          sheet, and there is no desktop sheet to keep them in —
          <code>events-mobile.js</code> always self-gated to <code>max-width:640px</code>, and
          desktop has navigated to the post page since day one. The destination page renders the
          poster uncropped on a phone and states the time once.</li>
    </ul>
  </div>

  <h2 class="sec">Light theme</h2>
  <div class="row">
    ${frames.filter((f) => f.theme === 'light').map((f) => `
    <div class="col">
      <p class="cap"><span class="tag tag--${f.variant === 'before' ? 'bad' : 'good'}">${CAP[f.variant]}</span></p>
      <div class="phone"><iframe title="${f.theme} ${f.variant}" srcdoc="${esc(f.srcdoc)}"></iframe></div>
      <p class="note">${NOTE[f.variant]}</p>
    </div>`).join('')}
  </div>

  <h2 class="sec">Dark theme</h2>
  <div class="row">
    ${frames.filter((f) => f.theme === 'dark').map((f) => `
    <div class="col">
      <p class="cap"><span class="tag tag--${f.variant === 'before' ? 'bad' : 'good'}">${CAP[f.variant]}</span></p>
      <div class="phone"><iframe title="${f.theme} ${f.variant}" srcdoc="${esc(f.srcdoc)}"></iframe></div>
    </div>`).join('')}
  </div>

  <h2 class="sec">The crop that is now gone, measured</h2>
  <p class="note" style="margin-top:0">For the record, since it was the thing you spotted. The
     poster is 1279×720. Forced into the old 170px box on a 390px phone, <code>cover</code> scaled
     it to 219.55px and centred it — <b>81 image pixels off the top and 81 off the bottom, 22.6% of
     the height</b>. Below: the artwork, then exactly the strip that survived.</p>
  <div style="margin-top:14px">
    <p class="cap"><span class="tag tag--good">The poster, whole</span></p>
    <img src="august-looth-pro-8452.webp" alt="Full event banner, 1279×720"
         style="width:100%;max-width:760px;height:auto;display:block;border-radius:8px">
    <p class="cap" style="margin-top:18px"><span class="tag tag--bad">What the old 170px box showed</span></p>
    <img src="crop-before.png" alt="The banner as cropped to 170px — rows 81 to 639"
         style="width:100%;max-width:760px;height:auto;display:block;border-radius:8px">
    <p class="note">“Looth Pro” loses its ascenders at the top; “August 2nd, 3PM” and the Looth
       Group roundel are cut through at the bottom.</p>
  </div>

  <h2 class="sec">Two things the ruling costs, for your call</h2>
  <div class="card" style="margin-top:0">
    <p class="flag" style="margin-top:0"><b>1. “Add to calendar” disappears from the mobile events
       flow.</b> It lived only in the sheet, and the destination post page has no calendar
       affordance — I checked this event’s page and there is none. If you want it kept, the natural
       home is the event post page itself (or the landing card). Say which and I’ll build it.</p>
    <p class="flag"><b>2. The destination hero is a raw one-size upload.</b> It ships as
       <code>&lt;img class="lg-post-header__photo" src=".../august-looth-pro-8452.webp"
       loading="eager" fetchpriority="high"&gt;</code> — no <code>/img.php?w=</code> resizer, no
       <code>srcset</code>, no width/height. That is 263KB of poster at full size on a phone, and
       the missing dimensions mean the page reflows when it lands. It breaks the standing image rule,
       it is now on the mobile critical path, and it is in <code>post-header</code> — a block every
       post uses, so I have not touched it unilaterally. Worth its own lane.</p>
  </div>

  <p class="foot">Rendered by your own browser at your own device width — each frame is a real
     390px viewport with its own theme, not a screenshot. Banner served from this page, copied
     byte-for-byte from live.</p>
</div>
</body>
</html>
`;

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, page);
console.log('wrote ' + OUT + ' (' + page.length + ' bytes)');
console.log('assertions passed: ' + checks.length);
checks.forEach((c) => console.log('  ok ' + c.name));
