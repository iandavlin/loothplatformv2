// ANON POSTING GATE — desktop half (2026-07-27).
//
// Desktop and mobile fail differently, and the difference is the whole diagnosis:
// every DESKTOP composer affordance is SERVER-rendered and already gated on
// $can_post (fc-composer at _feed.php:1645, the reply CTA at :1555, the post CTAs at
// :1300/:1341/:1367/:1372), so anon receives none of them. The MOBILE action bar is
// the one exception — feed_action_bar() (:1177) emits .lg-act-replies unconditionally,
// four lines under a comment promising every reply affordance is gated server-side —
// and it feeds a CLIENT-BUILT composer. That asymmetry is why the bug was mobile.
//
// These specs pin the desktop side so a future change can't quietly start shipping
// composer affordances to anon and rely on a client check that isn't there.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { installJsOverride } = require('./_helpers');

const ENV_PATH = process.env.WP_COOKIES_ENV || '/tmp/mentions-verify/wp-cookies.env';

function gateValue() {
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split('\n')) {
    const i = line.indexOf('=');
    if (i > 0 && line.slice(0, i).trim() === 'GATE_VAL') return line.slice(i + 1).trim();
  }
  throw new Error('GATE_VAL missing from ' + ENV_PATH);
}

async function anonHubDesktop(page, context) {
  await context.addCookies([
    { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: gateValue(), path: '/', secure: true, httpOnly: true },
  ]);
  await installJsOverride(page);
  await page.goto('/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id]', { timeout: 20_000 });
}

test.describe('anon posting gate — desktop', () => {
  // Desktop project ONLY. The config runs every spec under both projects; this file
  // asserts DESKTOP-shaped truths (no touch, desktop-gated affordances), so under
  // iphone-webkit it is meaningless rather than merely failing.
  // (Gate on the isMobile FIXTURE — the describe-level modifier callback receives
  // fixtures, not testInfo, so a testInfo.project check throws before any test runs.)
  test.skip(({ isMobile }) => !!isMobile, 'desktop-webkit project only');

  test('anon receives NO server-rendered composer affordance', async ({ page, context }) => {
    await anonHubDesktop(page, context);
    // The server gate that DOES hold. If any of these starts appearing for anon,
    // it needs a client gate too — that is exactly how the mobile hole opened.
    expect(await page.locator('.fc-composer').count()).toBe(0);
    expect(await page.locator('[data-frm-open]').count()).toBe(0);
    expect(await page.locator('.fc-composer__rich').count()).toBe(0);
    // ...and no composer was built client-side either.
    expect(await page.locator('#looth-comp-sheet').count()).toBe(0);
    expect(await page.locator('#lgc-editor').count()).toBe(0);
  });

  test('the client gate holds on desktop too when an affordance is reachable', async ({ page, context }) => {
    await anonHubDesktop(page, context);
    // Drive the composer door directly: this is the code path any future desktop
    // affordance would land on, so it must refuse anon regardless of how it was
    // reached. Proves the gate is at the DOOR, not in a mobile-only tap handler.
    await page.evaluate(() => document.body.setAttribute('data-lg-can-post', '0'));
    const opened = await page.evaluate(() => {
      const card = document.querySelector('.feed-card[data-topic-id]');
      if (window.lgOpenTopicMobile) window.lgOpenTopicMobile(card, { toReplies: true, focus: true });
      return !!document.querySelector('#looth-comp-sheet');
    });
    expect(opened).toBe(false);
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 8_000 });
    await expect(page.locator('#looth-signin-sheet .lgsi-t')).toHaveText(/sign in to reply/i);
  });
});
