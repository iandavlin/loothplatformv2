/**
 * thread-follow EXERCISE PASS — the browser leg.
 *
 * Real engine (WebKit — also the right proxy for Ian's iPhone), real clicks,
 * against the branch's Hub on loopback and the branch's follow.php on the WP pool.
 * Every UI assertion is re-checked against the SERVER, because an optimistic UI
 * that flips and silently reverts would otherwise read as a pass.
 *
 * NOTE: the card renders BOTH a desktop toggle pair (.fc-actions) and a mobile pair
 * (.lg-card-actions/.lg-act-follow); CSS shows one per breakpoint. So the topic under
 * test is resolved from a button that actually has a box in THIS viewport, never
 * hardcoded.
 */
const { webkit } = require('playwright-core');
const fs = require('fs');

const HUB   = 'http://127.0.0.1:8791/hub/';
const API   = '/bb-mirror-api/v0/follow';
const SHOTS = '/tmp/tf-exercise/shots';

const cookies = fs.readFileSync('/tmp/tf-exercise/cookies.txt', 'utf8')
  .trim().split('\n').filter(Boolean).map(l => {
    const i = l.indexOf('=');
    return { name: l.slice(0, i), value: l.slice(i + 1),
             domain: '127.0.0.1', path: '/', httpOnly: true, secure: false };
  });

const out = [];
const log = (...a) => { const s = a.join(' '); out.push(s); console.log(s); };
let failures = 0, passes = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? passes++ : failures++;
  log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${ok ? '' : `   got=${JSON.stringify(got)} want=${JSON.stringify(want)}`}`);
  return ok;
}

/** The first notify toggle that actually has a box in this viewport. */
const pickVisible = page => page.evaluate(() => {
  for (const b of document.querySelectorAll('[data-follow="notify"]')) {
    const r = b.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) {
      return { topic: b.dataset.topicId, cls: b.className,
               bar: b.parentElement && b.parentElement.className,
               w: Math.round(r.width), h: Math.round(r.height) };
    }
  }
  return null;
});

/** Ask the SERVER — never trust the DOM for the store's state. */
const serverState = (page, t) => page.evaluate(async ([api, t]) => {
  const r = await fetch(`${api}?topics=${t}`, { credentials: 'same-origin' });
  return (await r.json()).state[t] || null;
}, [API, t]);

/** Put both bits back to OFF so a click is unambiguously a turn-ON. */
const resetOff = (page, t) => page.evaluate(async ([api, t]) => {
  const g = await (await fetch(`${api}?topics=${t}`, { credentials: 'same-origin' })).json();
  for (const ch of ['notify', 'email']) {
    await fetch(api, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': g.nonce },
      body: JSON.stringify({ topic_id: +t, channel: ch, on: false }) });
  }
}, [API, t]);

const uiOf = (page, t) => page.evaluate(([t]) => {
  const vis = b => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const rd = ch => { for (const b of document.querySelectorAll(`[data-follow="${ch}"][data-topic-id="${t}"]`))
                       if (vis(b)) return { pressed: b.getAttribute('aria-pressed'), isOn: b.classList.contains('is-on') };
                     return null; };
  return { notify: rd('notify'), email: rd('email') };
}, [t]);

const clickVisible = (page, ch, t) => page.evaluate(([ch, t]) => {
  for (const b of document.querySelectorAll(`[data-follow="${ch}"][data-topic-id="${t}"]`)) {
    const r = b.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) { b.click(); return true; }
  }
  return false;
}, [ch, t]);


const visEl = (page, ch, t) => page.evaluate(([ch, t]) => {
  for (const b of document.querySelectorAll(`[data-follow="${ch}"][data-topic-id="${t}"]`)) {
    const r = b.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) { b.scrollIntoView({ block: 'center' });
      const r2 = b.getBoundingClientRect();
      return { x: r2.x, y: r2.y, w: r2.width, h: r2.height }; }
  }
  return null;
}, [ch, t]);

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await webkit.launch({ headless: true });

  /* ── 1. ANON — inert and hidden, never an error ─────────────────────────── */
  log('\n=== 1. ANON (no cookies) ===');
  {
    const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(HUB, { waitUntil: 'load' });
    await page.waitForTimeout(3000);
    const a = await page.evaluate(() => ({
      anon: document.body.classList.contains('lg-follow-anon'),
      authed: document.body.classList.contains('lg-follow-authed'),
      anyVisible: [...document.querySelectorAll('[data-follow]')]
        .some(b => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0; }),
      count: document.querySelectorAll('[data-follow]').length,
    }));
    log('  ' + JSON.stringify(a));
    check('body.lg-follow-anon set', a.anon, true);
    check('never marked authed', a.authed, false);
    check('no toggle is visible to anon', a.anyVisible, false);
    await ctx.close();
  }

  /* ── 2. DESKTOP CARD ────────────────────────────────────────────────────── */
  log('\n=== 2. DESKTOP FEED CARD (1280x900) ===');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/404|Failed to load resource/.test(m.text())) errs.push(m.text()); });

  await page.goto(HUB, { waitUntil: 'load' });
  await page.waitForFunction(() => document.body.classList.contains('lg-follow-authed')
                                || document.body.classList.contains('lg-follow-anon'), { timeout: 20000 });
  check('authed on hydration', await page.evaluate(() => document.body.classList.contains('lg-follow-authed')), true);

  const dpick = await pickVisible(page);
  log('  desktop toggle: ' + JSON.stringify(dpick));
  const T = dpick.topic;
  check('desktop toggle is the .fc-* pair (§2.2)', /fc-notify/.test(dpick.cls), true);

  await resetOff(page, T);
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => document.body.classList.contains('lg-follow-authed'), { timeout: 20000 });
  await visEl(page, 'notify', T);
  log(`  baseline UI ${JSON.stringify(await uiOf(page, T))}  SERVER ${JSON.stringify(await serverState(page, T))}`);
  check('baseline is OFF/OFF (nothing auto-subscribes)', await serverState(page, T), { notify: false, email: false });

  log('\n  -- click 🔔 --');
  await clickVisible(page, 'notify', T);
  check('UI flips optimistically', (await uiOf(page, T)).notify.pressed, 'true');
  await page.waitForTimeout(1800);
  check('UI still on after the round trip', (await uiOf(page, T)).notify.pressed, 'true');
  check('SERVER notify=true', (await serverState(page, T)).notify, true);
  check('email untouched by the 🔔 click', (await serverState(page, T)).email, false);

  log('\n  -- click ✉ --');
  await clickVisible(page, 'email', T);
  await page.waitForTimeout(1800);
  check('SERVER email=true', (await serverState(page, T)).email, true);
  check('notify still true', (await serverState(page, T)).notify, true);

  await page.screenshot({ path: `${SHOTS}/1-desktop-card-both-on.png` });
  const bx = await visEl(page, 'notify', T);
  await page.screenshot({ path: `${SHOTS}/2-desktop-card-closeup.png`,
    clip: { x: Math.max(0, bx.x - 470), y: Math.max(0, bx.y - 150), width: 700, height: 240 } });

  log('\n  -- click 🔔 again (OFF) — the independence proof in the UI --');
  await clickVisible(page, 'notify', T);
  await page.waitForTimeout(1800);
  check('SERVER notify=false, email STILL true', await serverState(page, T), { notify: false, email: true });
  check('UI agrees', await uiOf(page, T),
        { notify: { pressed: 'false', isOn: false }, email: { pressed: 'true', isOn: true } });

  /* ── 3. MODAL HEADER ───────────────────────────────────────────────────── */
  log('\n=== 3. DISCUSSION MODAL HEADER (§2.3) ===');

  // First: the toggle must NEVER open the thread behind it (stopPropagation, §2.2).
  await clickVisible(page, 'notify', T);
  await page.waitForTimeout(900);
  check('clicking a toggle does NOT open the modal',
        await page.evaluate(() => { const m = document.getElementById('lg-dmodal');
                                    return !!(m && !m.hidden); }), false);
  await clickVisible(page, 'notify', T);   // put it back
  await page.waitForTimeout(1200);

  // Now open it the way a member does — the card title.
  await page.evaluate(t => {
    const b = document.querySelector(`[data-follow][data-topic-id="${t}"]`);
    const card = b.closest('.feed-card') || b.closest('article');
    const title = card && card.querySelector('.fc-title, .feed-card__title');
    (title || card).click();
  }, T);
  await page.waitForTimeout(3000);

  const modal = await page.evaluate(() => {
    const m = document.getElementById('lg-dmodal');
    if (!m) return { open: false, reason: 'no #lg-dmodal in DOM' };
    if (m.hidden) return { open: false, reason: '#lg-dmodal present but hidden' };
    const rd = s => { const b = m.querySelector(s); if (!b) return null;
      const r = b.getBoundingClientRect();
      return { pressed: b.getAttribute('aria-pressed'), topic: b.getAttribute('data-topic-id'),
               vis: r.width > 0 && r.height > 0, w: Math.round(r.width), h: Math.round(r.height) }; };
    return { open: true, notify: rd('.lg-dmodal__notify'), email: rd('.lg-dmodal__email'),
             title: (m.querySelector('.lg-dmodal__title') || {}).textContent,
             cluster: [...m.querySelectorAll('.lg-dmodal__head > *')]
               .map(e => (e.className || '').toString().split(' ')[0] || e.tagName.toLowerCase()) };
  });
  log('  ' + JSON.stringify(modal));

  if (modal.open && modal.notify && modal.email) {
    check('modal 🔔 visible', modal.notify.vis, true);
    check('modal ✉ visible', modal.email.vis, true);
    check('cluster order: title, 🔔, ✉, size, close (§2.3)', modal.cluster.slice(0, 5),
          ['lg-dmodal__title', 'lg-dmodal__notify', 'lg-dmodal__email', 'lg-dmodal__size', 'lg-dmodal__x']);
    const mt = modal.notify.topic;
    check('modal toggles point at the opened topic', mt, T);
    const before = await serverState(page, mt);
    log('  server-before ' + JSON.stringify(before));
    check('modal ✉ shows the state set from the CARD (email=true)', modal.email.pressed, 'true');
    await page.screenshot({ path: `${SHOTS}/3-modal-header.png` });
    await page.locator('.lg-dmodal__notify').click();
    await page.waitForTimeout(1800);
    const after = await serverState(page, mt);
    log('  server-after  ' + JSON.stringify(after));
    check('modal 🔔 click wrote through to the store', after.notify, !before.notify);
    await page.screenshot({ path: `${SHOTS}/4-modal-header-clicked.png` });
  } else {
    log('  NOT EXERCISED — ' + (modal.reason || '?'));
    failures++;
  }
  await ctx.close();

  /* ── 4. MOBILE CARD ────────────────────────────────────────────────────── */
  log('\n=== 4. MOBILE FEED CARD (390x844, touch, iOS UA) ===');
  const mctx = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
  });
  await mctx.addCookies(cookies);
  const mp = await mctx.newPage();
  await mp.goto(HUB, { waitUntil: 'load' });
  await mp.waitForFunction(() => document.body.classList.contains('lg-follow-authed'), { timeout: 20000 });
  const mpick = await pickVisible(mp);
  log('  mobile toggle: ' + JSON.stringify(mpick));
  const MT = mpick.topic;
  check('mobile toggle sits in the mobile action bar (§2.2b)', /lg-act-follow|lg-card-actions/.test(mpick.bar || ''), true);
  const touchOk = mpick.h >= 44 && mpick.w >= 44;
  check('touch target >= 44px (§2.2b)', touchOk, true);
  if (!touchOk) log(`  ^ measured ${mpick.w}x${mpick.h}px — spec asks for >=44px effective`);

  await resetOff(mp, MT);
  await mp.reload({ waitUntil: 'load' });
  await mp.waitForFunction(() => document.body.classList.contains('lg-follow-authed'), { timeout: 20000 });
  await visEl(mp, 'notify', MT);
  await mp.screenshot({ path: `${SHOTS}/5-mobile-card.png` });
  log('\n  -- tap 🔔 --');
  await clickVisible(mp, 'notify', MT);
  await mp.waitForTimeout(1800);
  check('SERVER notify=true after the tap', (await serverState(mp, MT)).notify, true);
  log('\n  -- tap ✉ --');
  await clickVisible(mp, 'email', MT);
  await mp.waitForTimeout(1800);
  check('SERVER email=true, notify still true', await serverState(mp, MT), { notify: true, email: true });
  await mp.screenshot({ path: `${SHOTS}/6-mobile-card-both-on.png` });
  const mbx = await visEl(mp, 'notify', MT);
  await mp.screenshot({ path: `${SHOTS}/7-mobile-card-closeup.png`,
    clip: { x: 0, y: Math.max(0, mbx.y - 150), width: 390, height: 230 } });
  await mctx.close();

  await browser.close();
  log('\n=== JS errors: ' + (errs.length ? errs.slice(0, 4).join(' | ') : 'none') + ' ===');
  log(`=== ${passes} passed, ${failures} failed ===`);
  fs.writeFileSync('/tmp/tf-exercise/e2e/report.txt', out.join('\n'));
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
