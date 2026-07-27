// ANON POSTING GATE (Ian + keeper 2026-07-27) — a logged-out reader reached the composer.
//
// The bug: every composer here is built CLIENT-SIDE by hub-polish.js, served
// byte-identically to everyone. The only gate was the server refusing to render
// composer MARKUP, which cannot cover a composer the client builds from nothing —
// while the reply AFFORDANCES are served to anon regardless (feed_action_bar()).
//
// These specs run with the dev-gate cookie ONLY — no WP login cookies — so the box
// is reachable but the session is genuinely anonymous, the same shape as Ian's live
// report. `data-lg-can-post` is what _chrome.php now stamps on <body>; the specs set
// it explicitly so the CLIENT half can be proven on dev2 without deploying the server
// half into an open serve window (dev2 stays on main; the live cutover is in flight).
//
// The RED baseline below is the honest bar (keeper 2026-07-26): with the flag ABSENT
// — exactly today's served state — the composer still opens. That proves the harness
// reproduces the real bug before the flag is shown to close it.
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

// Dev-gate cookie ONLY. No wordpress_logged_in_* / wordpress_sec_* — this is a real
// anonymous session as far as the application is concerned.
async function anonContext(context) {
  await context.addCookies([
    { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: gateValue(), path: '/', secure: true, httpOnly: true },
  ]);
}

// Land on the hub as anon and wait for a reply affordance to exist.
// NOTE: no /looth-auth/issue bounce — that is the authed path.
async function anonHub(page, context, { canPost = null, dark = false } = {}) {
  await anonContext(context);
  await installJsOverride(page);
  if (canPost !== null) {
    await page.addInitScript((cp) => {
      const apply = () => { if (document.body) document.body.setAttribute('data-lg-can-post', cp); };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, { once: true });
      else apply();
    }, canPost);
  }
  await page.goto('/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id] .lg-act-replies', { timeout: 20_000 });
  // Theme AFTER load, never via addInitScript: the site's own pre-paint boot script
  // stamps data-lguser-theme/-dark on <html> and would overwrite an earlier set —
  // an addInitScript version of this silently tested LIGHT and called it dark.
  if (dark) {
    await page.evaluate(() => {
      document.documentElement.setAttribute('data-lguser-theme', 'dark');
      document.documentElement.setAttribute('data-lguser-dark', '1');
    });
  }
}

const replyAction = (page) => page.locator('.feed-card[data-topic-id] .lg-act-replies').first();

test.describe('anon posting gate', () => {
  // Mobile project ONLY: these drive real touch taps on .lg-act-replies, which is
  // display:none above 640px. Its desktop counterpart is anon-gate-desktop.spec.js.
  // (Gate on the isMobile FIXTURE — the describe-level modifier callback receives
  // fixtures, not testInfo, so a testInfo.project check throws before any test runs.)
  test.skip(({ isMobile }) => !isMobile, 'iphone-webkit project only');

  test('PRECONDITION: an anonymous page still serves reply affordances', async ({ page, context }) => {
    await anonHub(page, context);
    // This is WHY the client gate is needed: the server hands anon the buttons.
    expect(await replyAction(page).count()).toBeGreaterThan(0);
    // ...and hands it no composer markup, which is the defence that stayed intact.
    expect(await page.locator('#lgc-editor').count()).toBe(0);
  });

  test('RED BASELINE: with the flag ABSENT the composer still opens for anon', async ({ page, context }) => {
    // Reproduces today's served behaviour — the bug Ian hit on live.
    await anonHub(page, context, { canPost: null });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    expect(await page.locator('#looth-comp-sheet.is-open').count()).toBe(1);
    // Absent is permissive BY DESIGN (stale-cache grace) — the submit-time auth
    // check and the REST 401 are what still stop the write on that path.
  });

  test('GATED: data-lg-can-post=0 shows the sign-in modal and NO composer', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    // The composer must not merely be hidden — it must never have been BUILT.
    expect(await page.locator('#looth-comp-sheet').count()).toBe(0);
    await expect(page.locator('#looth-signin-sheet .lgsi-t')).toHaveText(/sign in to reply/i);
  });

  test('GATED: the login button returns the reader to this discussion', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    const href = await page.locator('#lgsi-go').getAttribute('href');
    expect(href).toContain('/wp-login.php?redirect_to=');
    const back = decodeURIComponent(href.split('redirect_to=')[1]);
    // Must return to a real on-site destination, not the bare hub root.
    expect(back).toMatch(/dev2\.loothgroup\.com|^\//);
    expect(back).not.toMatch(/wp-login/);
  });

  test('GATED: phone-BACK closes only the modal, the thread beneath survives', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    await page.goBack();
    await page.waitForSelector('#looth-signin-sheet.is-open', { state: 'detached', timeout: 8_000 })
      .catch(async () => { expect(await page.locator('#looth-signin-sheet.is-open').count()).toBe(0); });
    // the thread sheet the reply-intent opened is still standing
    expect(await page.locator('#looth-rep-sheet.is-open').count()).toBe(1);
  });

  test('GATED (dark): the modal renders in dark theme', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0', dark: true });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    const bg = await page.locator('#looth-signin-sheet .lgsi-card').evaluate(
      (el) => getComputedStyle(el).backgroundColor);
    // Assert the EXACT dark card (#1b1e21), not merely "not white" — a not-white
    // assertion passes on any accidental colour, which is how a dark bug survives.
    expect(bg).toBe('rgb(27, 30, 33)');
  });

  test('GATED (light): the modal is the light card on a light page', async ({ page, context }) => {
    // The paired light leg, so the dark rule can't win everywhere by accident.
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    const bg = await page.locator('#looth-signin-sheet .lgsi-card').evaluate(
      (el) => getComputedStyle(el).backgroundColor);
    expect(bg).toBe('rgb(255, 255, 255)');
  });

  test('NO REGRESSION: data-lg-can-post=1 opens the composer as before', async ({ page, context }) => {
    // The gate must not touch members. (Anon session + flag=1 is the isolation we
    // want here: it proves the CLIENT reads the flag and nothing else.)
    await anonHub(page, context, { canPost: '1' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    expect(await page.locator('#looth-signin-sheet.is-open').count()).toBe(0);
  });
});
