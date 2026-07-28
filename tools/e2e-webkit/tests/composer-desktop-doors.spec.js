// COMPOSER-V2 PHASE 3 — the DESKTOP DOORS now open THE composer, not frm.
//
// Needs BOTH overrides, because the doors and the composer live in different files:
//   LGC_JS_OVERRIDE=<worktree>/webroot/hub-polish.js        (the composer)
//   LGC_FORUMS_OVERRIDE=<worktree>/bb-mirror/web/forums.js  (the delegate)
// That pairing is what lets this be verified without a serve window on dev2.
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

test.describe('phase 3 — desktop reply doors', () => {
  test.skip(({ isMobile }) => !!isMobile, 'desktop-webkit project only');

  /* MEASURED 2026-07-27, and it corrected the inventory. On the desktop hub feed
     at 1280px, ZERO write affordances render:

       .fc-composer          7 in DOM / 0 visible
       .fc-composer__rich    7 / 0        .fc-composer__input  7 / 0
       .fc-composer__send    7 / 0        [data-frm-open]      7 / 0
       .reply-stub__reply    3 / 0

     forums.css:4417–4424 hides all of them on .feed-card--topic at ≥641 —
     "the reply CTA lives in the §4e modal" — and forums.css:4047 hides
     .fc-composer outright below that. So the ONE visible desktop reply door on
     the hub is inside the discussion modal, which is the first test below.

     The rest drive the DELEGATE directly rather than a rendered control. That is
     deliberate and labelled: they assert the ROUTING contract (which handler a
     [data-frm-open] click reaches), not that a user can see a button — because a
     user cannot. Dispatching a click on a hidden element would be a lie if it were
     dressed up as an affordance test; as a routing test it is exactly right, and
     it is what protects the fallback path on surfaces where those CTAs DO show. */

  test('the discussion-modal reply CTA opens composer v2, and frm stays shut', async ({ page, context }) => {
    await memberHub(page, context);
    const opened = await page.evaluate(() => {
      const c = document.querySelector('.feed-card[data-topic-id]');
      if (typeof window.lgDmodalOpen !== 'function') return false;
      window.lgDmodalOpen(c); return true;
    });
    test.skip(!opened, 'lgDmodalOpen not present on this build');
    const cta = page.locator('#lg-dmodal .lg-dmodal__opacts [data-frm-open]').first();
    await cta.waitFor({ state: 'visible', timeout: 15_000 });
    await cta.click();                       // a REAL click on a REAL visible control
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    const frmHidden = await page.evaluate(() => {
      const o = document.getElementById('frm-overlay'); return !o || o.hidden;
    });
    expect(frmHidden).toBe(true);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
  });

  test('ROUTING: a [data-frm-open] click carries that card topic into the composer', async ({ page, context }) => {
    await memberHub(page, context);
    const want = await page.evaluate(() => {
      const cta = document.querySelector('.feed-card__reply-cta[data-frm-open]');
      const card = cta.closest('.feed-card');
      return String(cta.dataset.topicId || (card && card.dataset.topicId) || '');
    });
    await page.evaluate(() => document.querySelector('.feed-card__reply-cta[data-frm-open]').click());
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    const got = await page.evaluate(() =>
      String((document.getElementById('looth-comp-sheet').__lcpCtx || {}).tid || ''));
    expect(got).toBe(want);
    expect(want).not.toBe('');
  });

  test('ROUTING FALLBACK: with no composer loaded the delegate still opens frm', async ({ page, context }) => {
    // Real state, not hypothetical: pwa.js injects hub-polish.js only when onHub
    // matches (pwa.js:44), so off-hub surfaces reach this delegate with no composer
    // and MUST keep the old door rather than swallowing the click.
    await memberHub(page, context);
    await page.evaluate(() => { delete window.lgOpenComposer; });
    await page.evaluate(() => document.querySelector('.feed-card__reply-cta[data-frm-open]').click());
    await page.waitForFunction(() => {
      const o = document.getElementById('frm-overlay'); return o && !o.hidden;
    }, null, { timeout: 10_000 });
    expect(await page.locator('#looth-comp-sheet.is-open').count()).toBe(0);
  });


  // NOTE: the lg:reply-posted contract (composer v2 must emit it, or the dmodal
  // goes stale after a desktop reply) is deliberately NOT asserted here. Proving it
  // requires actually posting, which belongs in the phase-3 exit test alongside the
  // mint + bell checks — a spec that only greps for the string would pass on code
  // that never fires it.
});
