/**
 * Icon-state proof shots: OFF/OFF vs ON/ON, desktop + modal + mobile.
 * Exists because the envelope's ON state rendered as a SOLID BLOCK and only a
 * picture caught it — every aria-pressed assertion was passing at the time.
 */
const { webkit } = require('playwright-core');
const fs = require('fs');

const HUB = 'http://127.0.0.1:8791/hub/';
const API = '/bb-mirror-api/v0/follow';
const S   = '/tmp/tf-exercise/shots';

const cookies = fs.readFileSync('/tmp/tf-exercise/cookies.txt', 'utf8')
  .trim().split('\n').filter(Boolean).map(l => { const i = l.indexOf('=');
    return { name: l.slice(0, i), value: l.slice(i + 1), domain: '127.0.0.1', path: '/', httpOnly: true, secure: false }; });

const setBits = (page, t, on) => page.evaluate(async ([api, t, on]) => {
  const g = await (await fetch(`${api}?topics=${t}`, { credentials: 'same-origin' })).json();
  for (const ch of ['notify', 'email'])
    await fetch(api, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': g.nonce },
      body: JSON.stringify({ topic_id: +t, channel: ch, on }) });
}, [API, t, on]);

const pick = page => page.evaluate(() => {
  for (const b of document.querySelectorAll('[data-follow="notify"]')) {
    const r = b.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) return b.dataset.topicId;
  }
  return null;
});

const shotRow = async (page, t, file, pad) => {
  const box = await page.evaluate(([t]) => {
    for (const b of document.querySelectorAll(`[data-follow="notify"][data-topic-id="${t}"]`)) {
      const r = b.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) {
        b.scrollIntoView({ block: 'center' });
        const row = b.closest('.fc-actions') || b.closest('.lg-card-actions') || b.parentElement;
        const q = row.getBoundingClientRect();
        return { x: q.x, y: q.y, w: q.width, h: q.height };
      }
    }
    return null;
  }, [t]);
  await page.screenshot({ path: file, clip: {
    x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad),
    width: Math.min(box.w + pad * 2, 1200), height: box.h + pad * 2 } });
};

const ready = page => page.waitForFunction(
  () => document.body.classList.contains('lg-follow-authed'), { timeout: 20000 });

(async () => {
  const b = await webkit.launch({ headless: true });

  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies(cookies);
  const p = await ctx.newPage();
  await p.goto(HUB, { waitUntil: 'load' });
  await ready(p);
  const T = await pick(p);

  await setBits(p, T, false);
  await p.reload({ waitUntil: 'load' }); await ready(p); await p.waitForTimeout(700);
  await shotRow(p, T, `${S}/A-desktop-row-OFF.png`, 14);

  await setBits(p, T, true);
  await p.reload({ waitUntil: 'load' }); await ready(p); await p.waitForTimeout(700);
  await shotRow(p, T, `${S}/B-desktop-row-ON.png`, 14);
  console.log('desktop OFF/ON shots, topic', T);

  await p.evaluate(t => {
    const btn = document.querySelector(`[data-follow][data-topic-id="${t}"]`);
    const card = btn.closest('.feed-card') || btn.closest('article');
    (card.querySelector('.fc-title, .feed-card__title') || card).click();
  }, T);
  await p.waitForTimeout(2800);
  const head = await p.evaluate(() => {
    const m = document.getElementById('lg-dmodal');
    if (!m || m.hidden) return null;
    const h = m.querySelector('.lg-dmodal__head').getBoundingClientRect();
    return { x: h.x, y: h.y, w: h.width, h: h.height };
  });
  if (head) {
    await p.screenshot({ path: `${S}/C-modal-header-ON.png`,
      clip: { x: head.x, y: head.y, width: head.w, height: head.h } });
    console.log('modal header shot');
  } else console.log('modal did not open');
  await ctx.close();

  const mc = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true,
    hasTouch: true, deviceScaleFactor: 3,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' });
  await mc.addCookies(cookies);
  const mp = await mc.newPage();
  await mp.goto(HUB, { waitUntil: 'load' });
  await ready(mp);
  const MT = await pick(mp);
  await setBits(mp, MT, true);
  await mp.reload({ waitUntil: 'load' }); await ready(mp); await mp.waitForTimeout(700);
  await shotRow(mp, MT, `${S}/D-mobile-row-ON.png`, 10);
  console.log('mobile ON shot, topic', MT);
  await mc.close();

  await b.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
