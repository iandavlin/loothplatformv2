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
      // A marker _feed.php emits ONLY when $can_post. (Was .fc-composer until phase 3
      // deleted that element — a marker that can never match would have made this
      // test pass for the wrong reason, i.e. by no longer testing anything.)
      const d = document.createElement('button');
      d.setAttribute('data-frm-open', '');
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
  // PHASE 3 GUARD: .fc-composer was one of the five can-post markers and is now
  // deleted. This pins the property that actually matters — the four survivors must
  // still separate member from anon — on OLD html as well as new, since the absent
  // branch exists precisely for pages served before the flag shipped.
  test('the can-post markers still separate anon from member without .fc-composer', async ({ page, context }) => {
    const M = '[data-frm-open],[data-ntm-open],.lg-newpost,.forum-header__new-post';
    await anonHub(page, context, { canPost: null, stripFlag: true });
    expect(await page.evaluate((m) => !!document.querySelector(m), M)).toBe(false);
    expect(await page.evaluate((m) => m.split(',')
      .every((s) => document.querySelectorAll(s).length === 0), M)).toBe(true);
    // The gate must hold on this page: no marker, no flag => sign-in modal, no composer.
    const r = replyAction(page);
    await r.scrollIntoViewIfNeeded();
    await r.tap();
    await page.waitForSelector('#looth-signin-sheet.is-open', { timeout: 10_000 });
    expect(await page.locator('#looth-comp-sheet').count()).toBe(0);
  });

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
