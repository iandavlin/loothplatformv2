const { webkit } = require('playwright');
const fs = require('fs');
const OUT = '/home/ubuntu/worktrees/map-infinite/footer-mockups/directory-list/shots';
const URL = 'file:///home/ubuntu/worktrees/map-infinite/footer-mockups/directory-list/build-preview.html';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await webkit.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  for (const f of [{h:'',n:'light'},{h:'#dark',n:'dark'}]) {
    await page.goto(URL + f.h, { waitUntil: 'networkidle' });
    await sleep(250);
    await page.screenshot({ path: `${OUT}/build-real-${f.n}.png`, fullPage: true });
    console.log('shot', f.n);
  }
  await browser.close();
})();
