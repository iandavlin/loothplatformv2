// COMPOSER-V2 PHASE 3 — the cross-file seam + the desktop modal SKIN.
//
// The thesis under test is "one composer, two skins". So this file deliberately
// asserts BOTH sides from the SAME code path: the desktop project must get a
// centered modal, and the mobile project must still get the byte-identical
// full-height sheet phase 2 shipped. A skin that leaks into mobile fails here,
// which is the parity gate stated as an executable contract rather than a promise.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { installJsOverride } = require('./_helpers');

const ENV_PATH = process.env.WP_COOKIES_ENV || '/tmp/mentions-verify/wp-cookies.env';

function readEnv() {
  const env = {};
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split('\n')) {
    const i = line.indexOf('=');
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return env;
}

// A MEMBER session — the skin is only reachable by someone who can post.
async function memberHub(page, context) {
  const e = readEnv();
  await context.addCookies([
    { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: e.GATE_VAL, path: '/', secure: true, httpOnly: true },
    { domain: 'dev2.loothgroup.com', name: e.LOGGED_IN_NAME, value: e.LOGGED_IN_VAL, path: '/', secure: true, httpOnly: true },
    { domain: 'dev2.loothgroup.com', name: e.AUTH_NAME, value: e.AUTH_VAL, path: '/', secure: true, httpOnly: true },
  ]);
  await installJsOverride(page);
  await page.goto('/looth-auth/issue?return=/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id]', { timeout: 20_000 });
}

// Drive the SEAM, not a surface — this is the call forums.js will make.
async function openViaSeam(page, extra = {}) {
  const ok = await page.evaluate((x) => {
    const c = document.querySelector('.feed-card[data-topic-id]');
    return window.lgOpenComposer(Object.assign({
      tid: c.getAttribute('data-topic-id'),
      fid: c.getAttribute('data-forum-id'),
      focus: false,
    }, x));
  }, extra);
  await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
  return ok;
}

const cardGeometry = (page) => page.evaluate(() => {
  const card = document.querySelector('#looth-comp-sheet .lgc-card');
  const r = card.getBoundingClientRect();
  return {
    vw: innerWidth, vh: innerHeight,
    w: Math.round(r.width), h: Math.round(r.height),
    top: Math.round(r.top), left: Math.round(r.left),
    radius: parseFloat(getComputedStyle(card).borderTopLeftRadius) || 0,
    grabDisplay: getComputedStyle(document.querySelector('#looth-comp-sheet .lgc-grab')).display,
  };
});

test.describe('composer v2 — seam', () => {
  test('window.lgOpenComposer exists and opens THE composer', async ({ page, context }) => {
    await memberHub(page, context);
    expect(await page.evaluate(() => typeof window.lgOpenComposer)).toBe('function');
    expect(await openViaSeam(page)).toBe(true);
    // it opened the ONE composer, not a copy
    expect(await page.locator('#looth-comp-sheet.is-open').count()).toBe(1);
    // ...carrying the shared component: Quill editor, not a bespoke desktop editor
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    expect(await page.locator('#looth-comp-sheet .ql-editor').count()).toBe(1);
  });

  test('the seam carries reply-to context through unchanged', async ({ page, context }) => {
    await memberHub(page, context);
    await openViaSeam(page, { replyTo: 999999, replyToName: 'Seam Probe' });
    await expect(page.locator('#looth-comp-sheet #lgc-ctx')).toContainText('Seam Probe');
  });
});

test.describe('desktop modal skin', () => {
  test.skip(({ isMobile }) => !!isMobile, 'desktop-webkit project only');

  test('the composer is a CENTERED, BOUNDED modal — not a full-height dock', async ({ page, context }) => {
    await memberHub(page, context);
    await openViaSeam(page);
    const g = await cardGeometry(page);
    // bounded, not edge-to-edge
    expect(g.w).toBeLessThan(g.vw - 100);
    expect(g.h).toBeLessThanOrEqual(g.vh - 40);
    // centred within a couple of px on both axes
    expect(Math.abs(g.left - (g.vw - g.w) / 2)).toBeLessThanOrEqual(2);
    expect(Math.abs(g.top - (g.vh - g.h) / 2)).toBeLessThanOrEqual(2);
    // modal chrome
    expect(g.radius).toBeGreaterThan(0);
    // the swipe handle is a touch affordance with no desktop meaning
    expect(g.grabDisplay).toBe('none');
  });

  test('Esc closes the desktop modal (LgSheets escClose, no bespoke handler)', async ({ page, context }) => {
    await memberHub(page, context);
    await openViaSeam(page);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#looth-comp-sheet.is-open', { state: 'detached', timeout: 8_000 })
      .catch(async () => { expect(await page.locator('#looth-comp-sheet.is-open').count()).toBe(0); });
  });

  test('dark render: the desktop modal uses the dark card', async ({ page, context }) => {
    await memberHub(page, context);
    // theme AFTER load — the pre-paint boot script would overwrite an earlier set
    await page.evaluate(() => {
      document.documentElement.setAttribute('data-lguser-theme', 'dark');
      document.documentElement.setAttribute('data-lguser-dark', '1');
    });
    await openViaSeam(page);
    const bg = await page.locator('#looth-comp-sheet .lgc-card')
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bg).toBe('rgb(27, 30, 33)');
  });
});

test.describe('mobile parity — the skin must NOT leak', () => {
  test.skip(({ isMobile }) => !isMobile, 'iphone-webkit project only');

  test('mobile still gets the full-height phase-2 sheet, unchanged', async ({ page, context }) => {
    await memberHub(page, context);
    await openViaSeam(page);
    const g = await cardGeometry(page);
    // full-bleed, docked to the top-left corner — the df97f87 "no peek" geometry
    expect(g.w).toBe(g.vw);
    expect(g.top).toBe(0);
    expect(g.left).toBe(0);
    expect(g.radius).toBe(0);
    // and the grab pill (swipe-to-dismiss) is still there
    expect(g.grabDisplay).not.toBe('none');
  });
});
