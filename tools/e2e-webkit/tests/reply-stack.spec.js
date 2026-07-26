// The mobile reply/composer regression set — every check here is a contract that a
// REAL device broke at least once in 2026-07 (see docs/atlas/REPLY-SURFACES-AUDIT.md
// "iOS receipts"). Runs on Playwright WebKit (same engine family as iOS Safari).
const { test, expect } = require('@playwright/test');
const { addAuthCookies, openTopicComposer, typeMention } = require('./_helpers');

test.beforeEach(async ({ context }) => { await addAuthCookies(context); });

test.describe('mobile reply stack @390 (WebKit)', () => {
  test.skip(({ isMobile }) => !isMobile, 'mobile-profile only');

  test('composer opens; mention dropdown appears above/over the sheet with items', async ({ page }) => {
    await openTopicComposer(page);
    await typeMention(page);
    const panel = page.locator('.lg-mnt');
    await expect(panel).toBeVisible();
    expect(await panel.locator('.lg-mnt__i').count()).toBeGreaterThan(0);
    const onTop = await page.evaluate(() => {
      const p = document.querySelector('.lg-mnt');
      const r = p.getBoundingClientRect();
      const hit = document.elementFromPoint(r.x + r.width / 2, r.y + 10);
      return !!(hit && hit.closest('.lg-mnt'));
    });
    expect(onTop).toBe(true);
  });

  test('reopen cycle: dismiss composer, reopen, dropdown works again (iPhone 2026-07-24 receipt)', async ({ page }) => {
    await openTopicComposer(page);
    await typeMention(page);
    // COMPOSER-V2: the full-height card has no off-card backdrop region (df97f87 —
    // the "double modal" peek is cut); the explicit ✕ is the dismissal affordance.
    await page.locator('#lgc-x').tap();
    await expect(page.locator('#looth-comp-sheet.is-open')).toHaveCount(0);
    const clean = await page.evaluate(() => {
      const lrs = document.getElementById('looth-rep-sheet');
      // LgSheets makes the TOP container click-transparent (off-card taps reach the
      // shared backdrop) — interactivity lives on the CARD. The contract: not
      // behind, not inert, and the card is interactive.
      const card = lrs.querySelector('[data-lg-sheet-card]') || lrs;
      return { behind: lrs.classList.contains('lg-sheet-behind'), inert: !!lrs.inert,
               cardPe: getComputedStyle(card).pointerEvents };
    });
    expect(clean.behind).toBe(false);
    expect(clean.inert).toBe(false);
    expect(clean.cardPe).not.toBe('none');
    await page.locator('#looth-rep-sheet .lrs-comp').tap();
    await page.waitForSelector('#looth-comp-sheet.is-open');
    await typeMention(page);
    await expect(page.locator('.lg-mnt')).toBeVisible();
  });

  test('scroll-lock: background locked while sheets open; restored on close', async ({ page }) => {
    await openTopicComposer(page);
    const locked = await page.evaluate(() => ({
      pos: document.body.style.position, lock: document.body.classList.contains('lg-sheet-lock'),
    }));
    expect(locked.pos).toBe('fixed');
    expect(locked.lock).toBe(true);
    await page.locator('#lgc-x').tap();                        // composer ✕ (full-height card — no backdrop region)
    await page.locator('#looth-rep-sheet .lrs-x').tap();       // thread X
    await expect(page.locator('#looth-rep-sheet.is-open')).toHaveCount(0);
    const after = await page.evaluate(() => ({
      pos: document.body.style.position, lock: document.body.classList.contains('lg-sheet-lock'),
    }));
    expect(after.pos).toBe('');
    expect(after.lock).toBe(false);
  });

  test('reactions reachable after composer dismiss (behind-state root invariant)', async ({ page }) => {
    await openTopicComposer(page);
    await page.locator('#lgc-x').tap();    // dismiss composer via ✕, thread stays
    const state = await page.evaluate(() => {
      const lrs = document.getElementById('looth-rep-sheet');
      const chip = [...lrs.querySelectorAll('.fcr-chip, .fcr-add')]
        .find(e => { const b = e.getBoundingClientRect(); return b.width > 4 && b.height > 4; });
      if (!chip) return { chipFound: false };
      chip.scrollIntoView({ block: 'center' });
      const b = chip.getBoundingClientRect();
      const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
      return { chipFound: true, reachable: !!(hit && (chip === hit || chip.contains(hit) || hit.closest('.fcr-chip, .fcr-add'))) };
    });
    if (state.chipFound) expect(state.reachable).toBe(true);
  });

  test('dropdown: scroll gesture scrolls without closing; quick tap still picks', async ({ page }) => {
    await openTopicComposer(page);
    await typeMention(page);
    const panel = page.locator('.lg-mnt');
    await expect(panel).toBeVisible();
    // Touch sequences synthesized as plain Events carrying a touches[] payload —
    // Linux WebKit exposes no Touch() constructor. Faithful to the contract under
    // test (the pick handler reads only touches[0].clientX/Y and target); native
    // momentum scrolling itself is NOT exercised here (the device gate covers that).
    const fireSeq = (moves) => page.evaluate((seq) => {
      const row = document.querySelector('.lg-mnt .lg-mnt__i');
      const r = row.getBoundingClientRect();
      const cx = r.x + r.width / 2, cy = r.y + r.height / 2;
      for (const [type, dy, ends] of seq) {
        const ev = new Event(type, { bubbles: true, cancelable: true });
        ev.touches = ends ? [] : [{ clientX: cx, clientY: cy + dy }];
        ev.changedTouches = [{ clientX: cx, clientY: cy + dy }];
        row.dispatchEvent(ev);
      }
    }, moves);
    // 40px drag = scroll intent (past the 10px slop): list must STAY OPEN, no pick
    await fireSeq([['touchstart', 0, false], ['touchmove', -40, false], ['touchend', -40, true]]);
    await expect(panel).toBeVisible();
    // quick tap (no movement): must PICK into the input and close the list
    const { editorText } = require('./_helpers');
    const before = await editorText(page);
    await fireSeq([['touchstart', 0, false], ['touchend', 0, true]]);
    const after = await editorText(page);
    expect(after).not.toBe(before);
    await expect(panel).toBeHidden();
  });
});

// Suggest-robustness (Ian 2026-07-25): multi-word @-queries — the token spans
// spaces while the dropdown has hits; a space after a zero-hit word ends capture.
// Server side is CLI-proven; this exercises the client tokenizer end-to-end @390.
const { test: test2, expect: expect2 } = require('@playwright/test');
test2.describe('mention multi-word @390 (WebKit)', () => {
  test2.skip(({ isMobile }) => !isMobile, 'mobile-profile only');
  const { addAuthCookies, openTopicComposer } = require('./_helpers');
  test2.beforeEach(async ({ context }) => { await addAuthCookies(context); });

  test2('"@doug proper" narrows across the space instead of dying', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15000 });
    const input = page.locator('#looth-comp-sheet .ql-editor');
    await input.tap();
    await input.pressSequentially('@doug', { delay: 90 });
    await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 8000 });
    const before = await page.locator('.lg-mnt .lg-mnt__i').count();
    await input.pressSequentially(' proper', { delay: 90 });
    await page.waitForFunction(() => {
      const p = document.querySelector('.lg-mnt');
      return p && getComputedStyle(p).display !== 'none' &&
             p.querySelectorAll('.lg-mnt__i').length === 1;
    }, { timeout: 8000 });
    expect2(before).toBeGreaterThan(1);   // narrowed from many to exactly Doug Proper
    const label = await page.locator('.lg-mnt .lg-mnt__h').first().textContent();
    expect2(label).toContain('Doug Proper');
  });

  test2('a space after a zero-hit word ends the token (prose not captured)', async ({ page }) => {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15000 });
    const input = page.locator('#looth-comp-sheet .ql-editor');
    await input.tap();
    await input.pressSequentially('@doug xyz', { delay: 90 });
    await page.waitForTimeout(900);        // zero-hit query lands, panel hides
    await input.pressSequentially(' hello world', { delay: 60 });
    await page.waitForTimeout(900);
    const open = await page.evaluate(() => {
      const p = document.querySelector('.lg-mnt');
      return !!(p && getComputedStyle(p).display !== 'none');
    });
    expect2(open).toBe(false);             // prose after the failed @ is NOT captured
  });
});

// Scrunched match (Ian 2026-07-25 mid-look): a glued query must match across
// spaces/hyphens on both name and slug — q=dougproper -> "Doug Proper - …".
const { test: test3, expect: expect3 } = require('@playwright/test');
test3.describe('mention scrunched match @390 (WebKit)', () => {
  test3.skip(({ isMobile }) => !isMobile, 'mobile-profile only');
  const H = require('./_helpers');
  test3.beforeEach(async ({ context }) => { await H.addAuthCookies(context); });

  test3('"@dougproper" finds Doug Proper (separator-stripped match)', async ({ page }) => {
    await H.openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15000 });
    const input = page.locator('#looth-comp-sheet .ql-editor');
    await input.tap();
    await input.pressSequentially('@dougproper', { delay: 80 });
    await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 8000 });
    const label = await page.locator('.lg-mnt .lg-mnt__h').first().textContent();
    expect3(label).toContain('Doug Proper');
  });
});

// Dark-mode merge gate (Ian standing rule 7/26): every changed surface must verify
// in dark. The reply stack's dark contract: composer card + mention panel render the
// dark tokens (not light leaks), and the shared manager backdrop is present.
const { test: test4, expect: expect4 } = require('@playwright/test');
test4.describe('reply stack dark render @390 (WebKit)', () => {
  test4.skip(({ isMobile }) => !isMobile, 'mobile-profile only');
  const H4 = require('./_helpers');
  test4.beforeEach(async ({ context }) => { await H4.addAuthCookies(context); });

  test4('dark theme: composer card + mention panel use dark surfaces; backdrop present', async ({ page }) => {
    await H4.openTopicComposer(page);
    // set dark AFTER load — app-settings.js applies the member's stored theme at
    // boot and would stomp an init-script attribute; post-load flips are live CSS.
    await page.evaluate(() => document.documentElement.setAttribute('data-lguser-theme', 'dark'));
    await page.waitForTimeout(300);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15000 });
    const input = page.locator('#looth-comp-sheet .ql-editor');
    await input.tap();
    await input.pressSequentially('@mik', { delay: 90 });
    await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 8000 });
    const s = await page.evaluate(() => {
      const card = document.querySelector('#looth-comp-sheet [data-lg-sheet-card]');
      const panel = document.querySelector('.lg-mnt');
      const back = document.getElementById('lg-sheet-backdrop');
      return {
        cardBg: getComputedStyle(card).backgroundColor,
        panelBg: getComputedStyle(panel).backgroundColor,
        backdropShown: !!(back && getComputedStyle(back).display !== 'none'),
      };
    });
    expect4(s.cardBg).toBe('rgb(27, 30, 33)');    // dark card, not light leak
    expect4(s.panelBg).toBe('rgb(27, 30, 33)');   // dark panel (explicit override)
    expect4(s.backdropShown).toBe(true);          // ONE shared backdrop under top sheet
  });
});
