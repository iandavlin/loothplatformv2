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
const { installJsOverride, addAuthCookies } = require('./_helpers');

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
async function anonHub(page, context, { canPost = null, dark = false, stripFlag = false } = {}) {
  await anonContext(context);
  await installJsOverride(page);
  if (canPost !== null || stripFlag) {
    await page.addInitScript(([cp, strip]) => {
      // stripFlag simulates HTML older than the attribute — i.e. a deploy that
      // shipped hub-polish.js without _chrome.php. dev2 now serves the flag, so
      // without this the "absent" branch is untestable there.
      const apply = () => {
        if (!document.body) return;
        if (strip) document.body.removeAttribute('data-lg-can-post');
        else document.body.setAttribute('data-lg-can-post', cp);
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, { once: true });
      else apply();
    }, [canPost, stripFlag]);
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

  // THE SPEC THAT SHOULD HAVE EXISTED FIRST. Its previous version asserted the
  // OPPOSITE — that with the flag absent the composer opens, "permissive by design".
  // That encoded the hole as correct behaviour, which is exactly why the suite was
  // green while a partial deploy (this file without _chrome.php — live's state on
  // 2026-07-27) served every anon a live composer. Absent must FAIL CLOSED.
  test('flag ABSENT still gates anon (partial-deploy / stale-cache safety)', async ({ page, context }) => {
    await anonHub(page, context, { canPost: null, stripFlag: true });
    expect(await page.evaluate(() => document.body.getAttribute('data-lg-can-post'))).toBeNull();
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    expect(await page.locator('#looth-comp-sheet').count()).toBe(0);
  });

  test('flag ABSENT does NOT gate a member (the fallback reads real affordances)', async ({ page, context }) => {
    // The reason the old default was permissive: never block a real member on stale
    // html. The derived fallback keeps that promise without the hole — a page that
    // carries server-rendered post affordances means the viewer can post.
    await anonHub(page, context, { canPost: null, stripFlag: true });
    await page.evaluate(() => {
      const d = document.createElement('div');
      d.className = 'fc-composer';        // the marker _feed.php emits only when $can_post
      document.body.appendChild(d);
    });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    expect(await page.locator('#looth-signin-sheet.is-open').count()).toBe(0);
  });

  // IAN'S EXACT SYMPTOM, as an assertion: "the cursor just moves down the composer".
  // The old suite asserted the composer SHEET was absent but never that nothing was
  // FOCUSED — a caret in the sheet's own composer bar would have passed it.
  test("anon card tap leaves NOTHING focused — no caret anywhere (Ian's live symptom)", async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    const state = await page.evaluate(() => {
      const ae = document.activeElement;
      return {
        editableFocused: ae ? (ae.isContentEditable || /^(INPUT|TEXTAREA)$/.test(ae.tagName)) : false,
        qlEditors: document.querySelectorAll('.ql-editor').length,
        sheetInput: document.querySelectorAll('#lrs-comp-input').length,
        sheetSend: document.querySelectorAll('#lrs-comp-send').length,
        sheetPhoto: document.querySelectorAll('#lrs-comp-photo').length,
        sheetFile: document.querySelectorAll('#lrs-comp-file').length,
      };
    });
    expect(state.editableFocused).toBe(false);   // no caret — Ian's symptom
    expect(state.qlEditors).toBe(0);
    // and the sheet's own composer was never MOUNTED, not merely hidden
    expect(state.sheetInput).toBe(0);
    expect(state.sheetSend).toBe(0);
    expect(state.sheetPhoto).toBe(0);
    expect(state.sheetFile).toBe(0);
  });

  // THE OTHER HALF: gating the composer must not gate READING. Anon opening the
  // sheet and reading the thread is the public teaser; losing it would be a
  // regression in the opposite direction.
  test('anon can still OPEN the sheet and READ the replies', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-rep-sheet.is-open', { timeout: 10_000 });
    // the thread actually rendered content, not an empty/blocked shell
    await page.waitForFunction(() => {
      const t = document.querySelector('#looth-rep-sheet #lrs-thread, #looth-rep-sheet #lrs-body');
      return t && t.textContent.trim().length > 20;
    }, null, { timeout: 15_000 });
    // and the write surface is a sign-in affordance, not a composer
    expect(await page.locator('#looth-rep-sheet #lrs-signin').count()).toBe(1);
    expect(await page.locator('#looth-rep-sheet #lrs-comp-input').count()).toBe(0);
  });

  test('the sheet sign-in bar opens the modal when tapped', async ({ page, context }) => {
    await anonHub(page, context, { canPost: '0' });
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-rep-sheet.is-open', { timeout: 10_000 });
    // dismiss the auto-raised modal from the reply INTENT, then tap the bar itself
    await page.goBack();
    await page.waitForTimeout(400);
    await page.locator('#looth-rep-sheet #lrs-signin').tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 8_000 });
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

/* ══════════════════════════════════════════════════════════════════════════
   THE CHROME DOORS (login-destination lane, 2026-07-27)
   ══════════════════════════════════════════════════════════════════════════

   The sign-in SHEET above already keeps the promise it makes out loud. The
   chrome "Sign in" — the most-used door on the site, present on every page —
   carried nothing, so signing in from anywhere landed everyone on BuddyBoss's
   /activity/ default. Two doors: the desktop button (>640) and the phone
   drawer item (<=640, where the button is display:none).

   RED BASELINE: these fail against dev2's current serve (main, a2ec633), where
   both hrefs are a bare /wp-login.php. That is the honest bar — the specs
   reproduce the real defect before the fix is served. The server half is PHP in
   the shared header, so unlike the sheet it cannot be proven through
   LGC_JS_OVERRIDE; it needs the branch actually served.

   The paired server-side proof that runs with no browser and no serve window is
   tools/gates/login-doors-gate.php, which renders the SERVING partial directly.
*/

// A real thread page — the destination a reader actually wants to come back to.
async function anonThread(page, context) {
  await anonContext(context);
  await page.goto('/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id]', { timeout: 20_000 });
  const href = await page.locator('.feed-card[data-topic-id] a[href*="/hub/"]').first().getAttribute('href');
  const topicPath = new URL(href, 'https://dev2.loothgroup.com').pathname;
  await page.goto(topicPath, { waitUntil: 'domcontentloaded' });
  return topicPath;
}

// Pull the destination out of a sign-in href, or null when it carries none.
function boundDest(href) {
  if (!href) return null;
  const i = href.indexOf('redirect_to=');
  return i === -1 ? null : decodeURIComponent(href.slice(i + 'redirect_to='.length));
}

test.describe('chrome sign-in carries the destination', () => {
  test('desktop: the header button returns the reader to THIS thread', async ({ page, context, isMobile }) => {
    test.skip(isMobile, 'the header button is display:none <=640; the drawer leg covers phones');
    const topicPath = await anonThread(page, context);
    const btn = page.locator('.lg-chrome__signin');
    await expect(btn).toBeVisible();
    const href = await btn.getAttribute('href');
    // The defect this lane fixes is EXACTLY this being null.
    expect(boundDest(href)).toBe(topicPath);
    expect(href).not.toMatch(/wp-login\.php$/);
  });

  test('mobile: the drawer "Sign in" returns the reader to THIS thread', async ({ page, context, isMobile }) => {
    test.skip(!isMobile, 'the drawer item is the phone-only door');
    const topicPath = await anonThread(page, context);
    // NOT via the hamburger: bottom-nav.js hides it outright on phones
    // ('.lg-chrome__hamburger{display:none !important}'), so the drawer item is
    // in the DOM but has no opener of its own down here. It is still the SOURCE
    // OF TRUTH — bottom-nav's account tray copies this href (see the next spec)
    // — so the href is what we assert, not its visibility.
    const item = page.locator('.lg-chrome__menu-signin a');
    await expect(item).toHaveCount(1);
    expect(boundDest(await item.getAttribute('href'))).toBe(topicPath);
    // Sanity that we're testing the phone door: the desktop button is gone here.
    await expect(page.locator('.lg-chrome__signin')).toBeHidden();
  });

  test('mobile: the bottom-nav account tray inherits the same destination', async ({ page, context, isMobile }) => {
    test.skip(!isMobile, 'the tray is the phone-only surface');
    // The tray builds its Sign in from hdrHref('.lg-chrome__signin, .lg-chrome__menu-signin a')
    // (bottom-nav.js), so it rides the header fix with no change of its own —
    // and this is the door a phone reader actually taps.
    const topicPath = await anonThread(page, context);
    await page.locator('[aria-label="You"]').tap();
    const login = page.locator('#looth-sheet .lt-sheet__login');
    await expect(login).toBeVisible();
    expect(boundDest(await login.getAttribute('href'))).toBe(topicPath);
  });

  test('the door survives the round trip: following it lands on the thread', async ({ page, context, isMobile }) => {
    // Read the href as a genuinely anonymous session, then follow it WITH WP
    // cookies. wp-login.php honours redirect_to for an already-authenticated
    // visitor, so this proves the destination survives the login endpoint —
    // the half a static href assertion cannot show.
    const topicPath = await anonThread(page, context);
    // Attribute read only — the phone drawer item has no opener (see above).
    const sel = isMobile ? '.lg-chrome__menu-signin a' : '.lg-chrome__signin';
    const href = await page.locator(sel).getAttribute('href');
    expect(boundDest(href)).toBe(topicPath);

    await addAuthCookies(context);
    await page.goto(href, { waitUntil: 'domcontentloaded' });
    // Landed on the thread, NOT on /activity/ and NOT back on wp-login.
    expect(new URL(page.url()).pathname).toBe(topicPath);
    expect(page.url()).not.toMatch(/\/activity\//);
    expect(page.url()).not.toMatch(/wp-login/);
  });

  test('a query on the destination survives whole', async ({ page, context, isMobile }) => {
    // /hub/?type=<x> is a real filtered view; before this lane a reader signing
    // in from one came back to an unfiltered page (when they came back at all).
    await anonContext(context);
    await page.goto('/hub/?type=discussions', { waitUntil: 'domcontentloaded' });
    // Attribute read only — the phone drawer item has no opener (see above).
    const sel = isMobile ? '.lg-chrome__menu-signin a' : '.lg-chrome__signin';
    // The query must arrive as ONE value, not split into sibling params —
    // which is what add_query_arg would have done (it does not encode values).
    expect(boundDest(await page.locator(sel).getAttribute('href'))).toBe('/hub/?type=discussions');
  });

  test('on wp-login itself the door binds nothing (no sign-in loop)', async ({ page, context, isMobile }) => {
    // Ruling 3: landing on an auth URL is the infinite loop. wp-login.php does
    // not render the shared chrome, so the surface under test is any page whose
    // own URL is unbindable; /wp-login.php is the canonical one. The header is
    // asserted directly by tools/gates/login-doors-gate.php; here we assert the
    // door never offers itself as a destination anywhere it IS rendered.
    await anonContext(context);
    await page.goto('/hub/', { waitUntil: 'domcontentloaded' });
    // Attribute read only — the phone drawer item has no opener (see above).
    const sel = isMobile ? '.lg-chrome__menu-signin a' : '.lg-chrome__signin';
    const dest = boundDest(await page.locator(sel).getAttribute('href'));
    // Bound (the lane's whole point) AND never an auth path (ruling 3).
    expect(dest).toBe('/hub/');
    expect(dest).not.toMatch(/wp-login|patreon-connect|patreon-password/);
  });
});
