// COMPOSER-V2 phase-2 contracts @390 (WebKit) — the ONE composer replacing lcp.
// Rulings under test: HANDLES ARE INVISIBLE (Ian final 2026-07-26 — rows are
// name + avatar + location/business, mention text is the member's CURRENT NAME,
// no @handle text anywhere); Quill rich text w/ the approved 6-btn toolbar;
// tag-people picker as a second LgSheets layer, Done drops mentions INLINE at
// the caret; text-scale honored (rem type). Keyboard dock geometry is a device
// contract (receipt R8) — Ian's phone gates it, not this harness.
const { test, expect } = require('@playwright/test');
const { addAuthCookies, openTopicComposer, typeMention, editorText } = require('./_helpers');

test.describe('composer-v2 @390 (WebKit)', () => {
  test.skip(({ isMobile }) => !isMobile, 'mobile-profile only');
  test.beforeEach(async ({ context }) => { await addAuthCookies(context); });

  test('full-height sheet: header X/identity/Post, editor mounts, no peek', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    // the card slides in (looth-pwa-up, .26s) — wait for settled geometry, don't
    // read a mid-animation rect (first-run flake)
    await page.waitForFunction(() => {
      const c = document.querySelector('#looth-comp-sheet [data-lg-sheet-card]');
      return c && c.getBoundingClientRect().top === 0;
    }, null, { timeout: 3_000 });
    const geo = await page.evaluate(() => {
      const card = document.querySelector('#looth-comp-sheet [data-lg-sheet-card]');
      const r = card.getBoundingClientRect();
      return {
        top: r.top, left: r.left, w: r.width,
        x: !!document.querySelector('#lgc-x'),
        post: !!document.querySelector('#lgc-post'),
        grab: !!document.querySelector('#looth-comp-sheet .lgc-grab'),
      };
    });
    expect(geo.top).toBe(0);                       // full height — the "double modal" peek is dead
    expect(geo.left).toBe(0);
    expect(geo.w).toBe(390);
    expect(geo.x && geo.post && geo.grab).toBe(true);
  });

  test('HANDLES INVISIBLE: dropdown rows carry no @handle line; pick inserts a NAME mention with uuid', async ({ page }) => {
    await openTopicComposer(page);
    await typeMention(page, '@mik');
    const rows = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('.lg-mnt .lg-mnt__i')).map((r) => ({
        primary: (r.querySelector('.lg-mnt__h') || {}).textContent || '',
        secondary: (r.querySelector('.lg-mnt__n') || {}).textContent || '',
      }));
    });
    expect(rows.length).toBeGreaterThan(0);
    for (const r of rows) {
      expect(r.primary.trim().startsWith('@')).toBe(false);     // name, not handle
      expect(r.secondary.trim().startsWith('@')).toBe(false);   // location/business, not handle
    }
    // pick the first row (desktop-path mousedown is fine for the insertion contract;
    // the tap-vs-scroll contract has its own spec in reply-stack)
    await page.locator('.lg-mnt .lg-mnt__i').first().dispatchEvent('mousedown');
    const mention = await page.evaluate(() => {
      const a = document.querySelector('#looth-comp-sheet .ql-editor a.bp-suggestions-mention');
      return a ? { uuid: a.getAttribute('data-lg-uuid'), href: a.getAttribute('href'), text: a.textContent.replace(/﻿/g, '') } : null;
    });
    expect(mention).not.toBeNull();
    expect(mention.uuid).toMatch(/^[0-9a-f-]{36}$/i);           // uuid-anchored
    expect(mention.href).toMatch(/^\/u\//);                     // slug lives on as the URL key only
    expect(mention.text.startsWith('@')).toBe(false);           // visible text = current NAME
    expect(mention.text.length).toBeGreaterThan(1);
    const txt = await editorText(page);
    expect(txt.indexOf('@')).toBe(-1);                          // the typed token is fully replaced
  });

  test('toolbar: bold + bullet list round-trip through Quill semantic HTML', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    const input = page.locator('#looth-comp-sheet .ql-editor');
    await input.tap();
    await page.locator('#looth-comp-sheet .ql-bold').first().click();
    await input.pressSequentially('solid joint', { delay: 40 });
    await page.locator('#looth-comp-sheet .ql-list[value="bullet"]').first().click();
    const html = await page.evaluate(() => {
      const q = document.querySelector('#looth-comp-sheet .lgc-editor');
      return q ? document.querySelector('#looth-comp-sheet .ql-editor').innerHTML : '';
    });
    expect(html).toMatch(/<(strong|b)>/);                        // bold applied
    expect(html).toMatch(/<(ul|ol)[\s>]/);                       // list applied
    // Post arms once there is content
    const disabled = await page.locator('#lgc-post').isDisabled();
    expect(disabled).toBe(false);
  });

  test('text-scale: editor + rows track a root font-size bump (rem contract)', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    const base = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('#looth-comp-sheet .ql-editor')).fontSize));
    await page.evaluate(() => { document.documentElement.style.fontSize = '130%'; });
    const scaled = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('#looth-comp-sheet .ql-editor')).fontSize));
    expect(scaled / base).toBeGreaterThan(1.25);   // rem type scales with the member's setting
    await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
  });

  test('tag-people picker: second sheet layer, selection persists across queries, Done drops NAME mentions inline', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.locator('#lgc-tag').click();
    await page.waitForSelector('#looth-tag-sheet.is-open', { timeout: 8_000 });
    // stacked ABOVE the composer on the manager stack
    const stack = await page.evaluate(() => window.LgSheets.stack());
    expect(stack[stack.length - 1]).toBe('lgtag');
    // search + select one
    const q = page.locator('#lgt-q');
    await q.fill('mik');
    await page.waitForSelector('#looth-tag-sheet .lgt-row', { timeout: 8_000 });
    const firstName = (await page.locator('#looth-tag-sheet .lgt-row .lgt-n').first().textContent()).trim();
    await page.locator('#looth-tag-sheet .lgt-row').first().click();
    // rows never show a handle
    const rowTexts = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#looth-tag-sheet .lgt-n, #looth-tag-sheet .lgt-c')).map((n) => n.textContent.trim()));
    for (const t of rowTexts) expect(t.startsWith('@')).toBe(false);
    // NEW query: the picked row PERSISTS (pinned, checked) even though it no longer matches
    await q.fill('zz-no-such-member');
    await page.waitForTimeout(700);
    const pinned = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#looth-tag-sheet .lgt-row.on .lgt-n')).map((n) => n.textContent.trim()));
    expect(pinned).toContain(firstName);
    // Done → picker closes, composer regains top, mention landed INLINE in the editor
    await page.locator('#lgt-done').click();
    await page.waitForSelector('#looth-tag-sheet.is-open', { state: 'detached', timeout: 8_000 }).catch(() => {});
    const closed = await page.evaluate(() => !document.querySelector('#looth-tag-sheet').classList.contains('is-open'));
    expect(closed).toBe(true);
    const mentions = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#looth-comp-sheet .ql-editor a.bp-suggestions-mention'))
        .map((a) => a.textContent.replace(/﻿/g, '').trim()));
    expect(mentions).toContain(firstName);
    for (const m of mentions) expect(m.startsWith('@')).toBe(false);
    // ...and the COMPOSER IS STILL THERE. This assertion is why the spec below
    // exists; without it this test passed green while the composer was gone.
    await page.waitForTimeout(600);
    expect(await page.evaluate(() => document.querySelector('#looth-comp-sheet').classList.contains('is-open'))).toBe(true);
  });

  // ── REGRESSION: dismissing a sheet must never take the sheet BENEATH with it ──
  // Ian, real iPhone, 2026-07-26: "when a user is selected it closes the composer."
  // close() consumes its own history entry with history.back(); that pop arrives
  // ONE TASK LATER and the manager's popstate listener read it as a phone-back and
  // closeTop'd the next sheet down. Two reasons the phase-2 suite missed it:
  //   (1) the picker test above asserted the PICKER closed + the mention landed and
  //       never asserted the composer was still open;
  //   (2) every assertion ran synchronously after the click — BEFORE the pop landed.
  // So both halves are mandatory here: settle the event loop, then assert the layer
  // beneath SURVIVED. Real taps throughout (locator.tap = touchstart/touchend), and
  // the last test proves the self-pop swallow still lets REAL backs through.
  const SETTLE = 600;   // the defect is async — asserting sooner cannot see it

  test('picker Done dismisses ONLY the picker — the composer survives (Ian real-iPhone FAIL 2026-07-26)', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.locator('#lgc-tag').tap();
    await page.waitForSelector('#looth-tag-sheet.is-open', { timeout: 8_000 });
    await page.locator('#lgt-q').fill('mik');
    await page.waitForSelector('#looth-tag-sheet .lgt-row', { timeout: 8_000 });
    await page.locator('#looth-tag-sheet .lgt-row').first().tap();
    await page.locator('#lgt-done').tap();
    await page.waitForTimeout(SETTLE);
    const s = await page.evaluate(() => ({
      stack: window.LgSheets.stack(),
      tag: document.querySelector('#looth-tag-sheet').classList.contains('is-open'),
      comp: document.querySelector('#looth-comp-sheet').classList.contains('is-open'),
      lrs: document.querySelector('#looth-rep-sheet').classList.contains('is-open'),
      mentions: document.querySelectorAll('#looth-comp-sheet .ql-editor a.bp-suggestions-mention').length,
    }));
    expect(s.tag).toBe(false);                          // the picker layer went away
    expect(s.comp).toBe(true);                          // the composer DID NOT
    expect(s.lrs).toBe(true);                           // nor the thread under it
    expect(s.stack[s.stack.length - 1]).toBe('lcp');    // composer is top again
    expect(s.mentions).toBeGreaterThan(0);              // and it kept the mention
  });

  test('composer ✕ dismisses ONLY the composer — the thread sheet survives', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.locator('#lgc-x').tap();
    await page.waitForTimeout(SETTLE);
    const s = await page.evaluate(() => ({
      stack: window.LgSheets.stack(),
      comp: document.querySelector('#looth-comp-sheet').classList.contains('is-open'),
      lrs: document.querySelector('#looth-rep-sheet').classList.contains('is-open'),
    }));
    expect(s.comp).toBe(false);
    expect(s.lrs).toBe(true);
    expect(s.stack).toEqual(['lrs']);
  });

  test('phone-back still walks the stack ONE layer per press (the self-pop swallow eats only OUR pops)', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.locator('#lgc-tag').tap();
    await page.waitForSelector('#looth-tag-sheet.is-open', { timeout: 8_000 });
    const stackNow = () => page.evaluate(() => window.LgSheets.stack());
    expect(await stackNow()).toEqual(['lrs', 'lcp', 'lgtag']);
    await page.goBack();                    // real back #1 → picker only
    await page.waitForTimeout(SETTLE);
    expect(await stackNow()).toEqual(['lrs', 'lcp']);
    await page.goBack();                    // real back #2 → composer only
    await page.waitForTimeout(SETTLE);
    expect(await stackNow()).toEqual(['lrs']);
    await page.goBack();                    // real back #3 → thread
    await page.waitForTimeout(SETTLE);
    expect(await stackNow()).toEqual([]);
  });

  test('dark render: composer card, tool row and picker use dark surfaces', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.evaluate(() => document.documentElement.setAttribute('data-lguser-theme', 'dark'));
    await page.locator('#lgc-tag').click();
    await page.waitForSelector('#looth-tag-sheet.is-open', { timeout: 8_000 });
    const s = await page.evaluate(() => ({
      card: getComputedStyle(document.querySelector('#looth-comp-sheet [data-lg-sheet-card]')).backgroundColor,
      editorInk: getComputedStyle(document.querySelector('#looth-comp-sheet .ql-editor')).color,
      picker: getComputedStyle(document.querySelector('#looth-tag-sheet .lgt-card')).backgroundColor,
    }));
    expect(s.card).toBe('rgb(27, 30, 33)');      // dark card, no light leak
    expect(s.picker).toBe('rgb(27, 30, 33)');    // picker follows (dark merge gate)
    expect(s.editorInk).toBe('rgb(229, 231, 225)');
  });
});
