// Directory desktop LIST-view previs — file://, one WebKit engine, 1280 wide.
const { webkit } = require('playwright');
const fs = require('fs');
const OUT = '/home/ubuntu/worktrees/map-infinite/footer-mockups/directory-list/shots';
const URL = 'file:///home/ubuntu/worktrees/map-infinite/footer-mockups/directory-list/directory-list.html';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const frames = [
  { hash: '', name: 'comfortable-light' },
  { hash: '#dark', name: 'comfortable-dark' },
  { hash: '#compact', name: 'compact-light' },
  { hash: '#compact-dark', name: 'compact-dark' },
];
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await webkit.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  for (const f of frames) {
    await page.goto(URL + f.hash, { waitUntil: 'networkidle' });
    await sleep(300);
    await page.screenshot({ path: `${OUT}/dir-list-${f.name}.png`, fullPage: true });
    console.log('shot', f.name);
  }
  await browser.close();
})();
