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
    await page.touchscreen.tap(195, 60);   // backdrop off-tap
    await expect(page.locator('#looth-comp-sheet.is-open')).toHaveCount(0);
    const clean = await page.evaluate(() => {
      const lrs = document.getElementById('looth-rep-sheet');
      return { behind: lrs.classList.contains('lg-sheet-behind'), inert: !!lrs.inert,
               pe: getComputedStyle(lrs).pointerEvents };
    });
    expect(clean.behind).toBe(false);
    expect(clean.inert).toBe(false);
    expect(clean.pe).not.toBe('none');
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
    await page.touchscreen.tap(195, 60);                       // composer backdrop
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
    await page.touchscreen.tap(195, 60);   // dismiss composer, thread stays
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
    const before = await page.locator('#lcp-input').inputValue();
    await fireSeq([['touchstart', 0, false], ['touchend', 0, true]]);
    const after = await page.locator('#lcp-input').inputValue();
    expect(after).not.toBe(before);
    await expect(panel).toBeHidden();
  });
});
