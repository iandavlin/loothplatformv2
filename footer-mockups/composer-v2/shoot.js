// Shot generator for the composer-v2 previs. Mock tooling only — never shipped.
// Run from this dir: node shoot.js   (RAM-flag the board first; one engine per box.)
// Uses the repo's Playwright install (tools/e2e-webkit) + file:// URLs — no serve.
const path = require('path');
const { webkit } = require(path.join(__dirname, '../../tools/e2e-webkit/node_modules/playwright-core'));

const FILE = 'file://' + path.join(__dirname, 'composer-previs.html');
const SHOTS = [];
for (const theme of ['light', 'dark']) {
  for (const state of ['base', 'kb', 'mentions', 'photos', 'picker', 'picked'])
    SHOTS.push({ skin: 'mobile', state, theme, vp: { width: 390, height: 844 } });
  for (const state of ['base', 'mentions', 'photos', 'picker', 'picked'])
    SHOTS.push({ skin: 'desktop', state, theme, vp: { width: 1280, height: 900 } });
}

// single icon-decision frame (Ian: person vs @+, side-by-side)
SHOTS.push({ skin: 'mobile', state: 'kb', theme: 'light', vp: { width: 390, height: 844 }, icon: 'at', name: 'm-icon-at-light' });

(async () => {
  const browser = await webkit.launch();
  for (const s of SHOTS) {
    const page = await browser.newPage({ viewport: s.vp, deviceScaleFactor: 2 });
    await page.goto(`${FILE}?skin=${s.skin}&state=${s.state}&theme=${s.theme}` + (s.icon ? `&icon=${s.icon}` : ''));
    await page.waitForTimeout(150);
    const out = path.join(__dirname, 'shots', `${s.name || (s.skin[0] + '-' + s.state + '-' + s.theme)}.png`);
    await page.screenshot({ path: out });
    console.log('shot', out.split('/').pop());
    await page.close();
  }
  await browser.close();
})();
