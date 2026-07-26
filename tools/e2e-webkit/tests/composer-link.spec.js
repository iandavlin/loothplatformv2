// COMPOSER LINK PANEL @390 (WebKit) — Ian design call 2026-07-26: link is a
// two-part INSERT (URL, then OPTIONAL text) placed at the cursor, not a
// decoration applied to a selection via window.prompt.
//
// Every leg keeper listed is here: insert with no selection, insert over a
// selection, edit an existing link, remove, empty-text fallback, bare-domain
// prefix, hostile scheme rejected, back-button walk, and draft round-trip with
// a link in the body. The back-button leg is the one that matters most — this
// panel is a THIRD sheet layer on the stack machinery fbf6b57 just repaired, so
// it is exactly the place that wound would re-open.
const { test, expect } = require('@playwright/test');
const { addAuthCookies, openTopicComposer } = require('./_helpers');

const SETTLE = 600;    // sheet close + popstate are async — assert after they land

// Type into the editor with real key events, then open the link panel by tapping
// the toolbar button (the real gesture — never a direct handler call).
async function openPanel(page) {
  await page.locator('#looth-comp-sheet .ql-link').tap();
  await page.waitForSelector('#looth-link-sheet.is-open', { timeout: 8_000 });
}
const fill = async (page, url, text) => page.evaluate(([u, t]) => {
  const set = (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  };
  set(document.querySelector('#lgl-url'), u);
  if (t !== null) set(document.querySelector('#lgl-text'), t);
}, [url, text === undefined ? null : text]);
const body = (page) => page.evaluate(() =>
  document.querySelector('#looth-comp-sheet .ql-editor').innerHTML);

test.describe('composer link panel @390 (WebKit)', () => {
  test.skip(({ isMobile }) => !isMobile, 'mobile-profile only');
  test.beforeEach(async ({ context }) => { await addAuthCookies(context); });

  async function editor(page) {
    await openTopicComposer(page);
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    return page.locator('#looth-comp-sheet .ql-editor');
  }

  test('NO SELECTION: the button opens a panel, not a native prompt, and inserts at the cursor', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await ed.pressSequentially('see ', { delay: 40 });

    // a native dialog would hang the run; prove none is even offered
    let native = false;
    page.on('dialog', async (d) => { native = true; await d.dismiss(); });

    await openPanel(page);
    const opened = await page.evaluate(() => ({
      title: document.querySelector('#lgl-t').textContent,
      url: document.querySelector('#lgl-url').value,
      text: document.querySelector('#lgl-text').value,
      insertDisabled: document.querySelector('#lgl-go').disabled,
      removeHidden: document.querySelector('#lgl-rm').hidden,
      stack: window.LgSheets.stack(),
      focused: document.activeElement && document.activeElement.id,
    }));
    expect(native).toBe(false);                 // NOT window.prompt
    expect(opened.title).toBe('Add link');
    expect(opened.url).toBe('');                // nothing to pre-fill
    expect(opened.text).toBe('');
    expect(opened.insertDisabled).toBe(true);   // no plausible URL yet
    expect(opened.removeHidden).toBe(true);     // nothing to remove
    expect(opened.stack).toEqual(['lrs', 'lcp', 'lglink']);
    expect(opened.focused).toBe('lgl-url');     // the URL field is where you land

    await fill(page, 'example.com', 'the example');
    expect(await page.evaluate(() => document.querySelector('#lgl-go').disabled)).toBe(false);
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);

    const after = await page.evaluate(() => ({
      html: document.querySelector('#looth-comp-sheet .ql-editor').innerHTML,
      text: document.querySelector('#looth-comp-sheet .ql-editor').textContent,
      panel: !!document.querySelector('#looth-link-sheet.is-open'),
      comp: document.querySelector('#looth-comp-sheet').classList.contains('is-open'),
      stack: window.LgSheets.stack(),
      postDisabled: document.querySelector('#lgc-post').disabled,
    }));
    expect(after.html).toContain('href="https://example.com"');   // bare domain got https://
    expect(after.html).toContain('>the example</a>');             // the label they typed
    expect(after.text).toContain('see the example');              // dropped AT the cursor
    expect(after.panel).toBe(false);                              // panel closed on insert
    expect(after.comp).toBe(true);                                // composer survived
    expect(after.stack).toEqual(['lrs', 'lcp']);
    expect(after.postDisabled).toBe(false);
  });

  test('EMPTY TEXT falls back to showing the address — that is what makes the field optional', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await openPanel(page);
    await fill(page, 'https://looth.example/page', '');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);
    const html = await body(page);
    expect(html).toContain('href="https://looth.example/page"');
    expect(html).toContain('>https://looth.example/page</a>');
  });

  test('SELECTION: the selected text pre-fills and gets linked (today\'s habit, kept)', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await ed.pressSequentially('read the docs', { delay: 40 });
    await page.evaluate(() => window.Quill.find(document.querySelector('#lgc-editor')).setSelection(5, 8));   // "the docs"
    await openPanel(page);
    expect(await page.evaluate(() => document.querySelector('#lgl-text').value)).toBe('the docs');

    await fill(page, 'docs.example.org');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);
    const t = await page.evaluate(() => ({
      html: document.querySelector('#looth-comp-sheet .ql-editor').innerHTML,
      text: document.querySelector('#looth-comp-sheet .ql-editor').textContent,
    }));
    expect(t.html).toContain('href="https://docs.example.org"');
    expect(t.html).toContain('>the docs</a>');
    expect(t.text).toBe('read the docs');       // the words did not change
  });

  test('EDIT + REMOVE: cursor inside a link pre-fills both fields and offers Remove', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await openPanel(page);
    await fill(page, 'first.example', 'my link');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);

    await page.evaluate(() => window.Quill.find(document.querySelector('#lgc-editor')).setSelection(3, 0));   // inside "my link"
    await openPanel(page);
    const pre = await page.evaluate(() => ({
      title: document.querySelector('#lgl-t').textContent,
      url: document.querySelector('#lgl-url').value,
      text: document.querySelector('#lgl-text').value,
      removeHidden: document.querySelector('#lgl-rm').hidden,
    }));
    expect(pre.title).toBe('Edit link');
    expect(pre.url).toBe('https://first.example');   // the WHOLE run was found, not one char
    expect(pre.text).toBe('my link');
    expect(pre.removeHidden).toBe(false);

    await fill(page, 'second.example', 'my link');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);
    let html = await body(page);
    expect(html).toContain('href="https://second.example"');
    expect(html).not.toContain('first.example');     // edited in place, not duplicated

    await page.evaluate(() => window.Quill.find(document.querySelector('#lgc-editor')).setSelection(3, 0));
    await openPanel(page);
    await page.locator('#lgl-rm').tap();
    await page.waitForTimeout(SETTLE);
    html = await body(page);
    expect(html).not.toContain('<a');                 // link gone
    expect(await page.evaluate(() => document.querySelector('#looth-comp-sheet .ql-editor').textContent))
      .toContain('my link');                          // the WORDS stay — remove the link, not the text
  });

  test('HOSTILE SCHEMES are refused AT INSERT, with a legible reason', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await openPanel(page);

    for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHN2Zz4=',
      'vbscript:msgbox(1)', 'file:///etc/passwd']) {
      await fill(page, bad);
      const s = await page.evaluate(() => {
        const e = document.querySelector('#lgl-err');
        const r = e.getBoundingClientRect();
        return {
          disabled: document.querySelector('#lgl-go').disabled,
          msg: e.textContent, w: Math.round(r.width), wants: e.scrollWidth, h: Math.round(r.height),
        };
      });
      expect(s.disabled, bad).toBe(true);            // Insert never arms
      expect(s.msg, bad).toMatch(/only web, email and phone/i);
      expect(s.h, bad).toBeGreaterThan(0);
      expect(s.w, bad).toBeGreaterThanOrEqual(s.wants);   // legible, not ellipsed
    }

    // and a good one still arms, so the guard is not just "always off"
    await fill(page, 'mailto:hello@example.com');
    expect(await page.evaluate(() => document.querySelector('#lgl-go').disabled)).toBe(false);
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);
    expect(await body(page)).toContain('href="mailto:hello@example.com"');
  });

  test('nothing is inserted when the panel is cancelled or dismissed', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await ed.pressSequentially('plain', { delay: 40 });
    await openPanel(page);
    await fill(page, 'example.com', 'nope');
    await page.locator('#lgl-x').tap();               // Cancel
    await page.waitForTimeout(SETTLE);
    const s = await page.evaluate(() => ({
      html: document.querySelector('#looth-comp-sheet .ql-editor').innerHTML,
      panel: !!document.querySelector('#looth-link-sheet.is-open'),
      comp: document.querySelector('#looth-comp-sheet').classList.contains('is-open'),
    }));
    expect(s.html).not.toContain('<a');               // a half-typed URL is not an intention
    expect(s.panel).toBe(false);
    expect(s.comp).toBe(true);
  });

  // THE LEG THAT MATTERS: this panel is a third layer on the machinery fbf6b57
  // repaired. A self-pop leaking here would close the composer under it, which is
  // precisely the bug Ian's thumb found on the picker.
  test('PHONE-BACK closes the link panel ONLY — the composer survives (fbf6b57 machinery)', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await ed.pressSequentially('keep me', { delay: 40 });
    await openPanel(page);
    expect(await page.evaluate(() => window.LgSheets.stack())).toEqual(['lrs', 'lcp', 'lglink']);

    await page.goBack();
    await page.waitForTimeout(SETTLE);
    const s = await page.evaluate(() => ({
      stack: window.LgSheets.stack(),
      panel: !!document.querySelector('#looth-link-sheet.is-open'),
      comp: document.querySelector('#looth-comp-sheet').classList.contains('is-open'),
      lrs: document.querySelector('#looth-rep-sheet').classList.contains('is-open'),
      text: document.querySelector('#looth-comp-sheet .ql-editor').textContent,
    }));
    expect(s.panel).toBe(false);                      // the panel went away
    expect(s.comp).toBe(true);                        // the composer DID NOT
    expect(s.lrs).toBe(true);
    expect(s.stack).toEqual(['lrs', 'lcp']);
    expect(s.text).toContain('keep me');              // and kept what was typed

    // the swallow must not eat the member's NEXT real back either
    await page.goBack();
    await page.waitForTimeout(SETTLE);
    expect(await page.evaluate(() => window.LgSheets.stack())).toEqual(['lrs']);
  });

  test('Insert-then-back walks the stack correctly too (the panel closed itself first)', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await openPanel(page);
    await fill(page, 'example.com', 'x');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);
    expect(await page.evaluate(() => window.LgSheets.stack())).toEqual(['lrs', 'lcp']);
    await page.goBack();                              // one real press -> composer only
    await page.waitForTimeout(SETTLE);
    expect(await page.evaluate(() => window.LgSheets.stack())).toEqual(['lrs']);
  });

  // Draft preservation restores the body with dangerouslyPasteHTML — a SECOND
  // door into the editor that never touches the panel's normaliser. The link must
  // survive it intact, and a hostile href must not survive it at all.
  test('DRAFT round-trip: a link in the body comes back after an accidental dismiss', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await ed.pressSequentially('saved ', { delay: 40 });
    await openPanel(page);
    await fill(page, 'draft.example', 'my draft link');
    await page.locator('#lgl-go').tap();
    await page.waitForTimeout(SETTLE);

    await page.goBack();                       // accidental dismiss -> draft kept
    await page.waitForTimeout(SETTLE);
    expect(await page.evaluate(() =>
      document.querySelector('#looth-comp-sheet').classList.contains('is-open'))).toBe(false);

    // NOTE, and it is the whole reason this leg is shaped like this: drafts live in
    // an in-memory map, so the round-trip must NOT navigate — a reload would wipe
    // them and the test would prove nothing. And the thread sheet is still up over
    // the feed after the composer closes, so its images intercept a tap aimed at
    // the card beneath; walk the stack all the way out first, exactly as a member
    // would, then come back to the same topic.
    await page.goBack();                       // close the thread sheet too
    await page.waitForTimeout(SETTLE);
    expect(await page.evaluate(() => window.LgSheets.stack())).toEqual([]);

    const reply = page.locator('.feed-card[data-topic-id] .lg-act-replies').first();
    await reply.scrollIntoViewIfNeeded();
    await reply.tap();
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
    await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
    await page.waitForTimeout(SETTLE);
    const back = await page.evaluate(() => ({
      html: document.querySelector('#looth-comp-sheet .ql-editor').innerHTML,
      text: document.querySelector('#looth-comp-sheet .ql-editor').textContent,
    }));
    expect(back.text).toContain('saved');
    expect(back.html).toContain('href="https://draft.example"');   // href intact
    expect(back.html).toContain('>my draft link</a>');
  });

  test('a hostile href cannot enter through the PASTE/restore door either', async ({ page }) => {
    await editor(page);
    // dangerouslyPasteHTML is the exact call the draft restore uses
    const html = await page.evaluate(() => {
      const q = window.Quill.find(document.querySelector('#lgc-editor'));
      q.clipboard.dangerouslyPasteHTML(
        '<p><a href="javascript:alert(1)">tap me</a> and <a href="example.com/ok">fine</a></p>', 'silent');
      return document.querySelector('#looth-comp-sheet .ql-editor').innerHTML;
    });
    expect(html).not.toContain('javascript:');   // scheme stripped, not merely escaped
    expect(html).toContain('tap me');            // the words survive; only the link dies
    expect(html).toContain('href="https://example.com/ok"');   // the good one normalised
  });

  test('dark render: the panel follows the theme (merge gate)', async ({ page }) => {
    const ed = await editor(page);
    await ed.tap();
    await openPanel(page);
    await fill(page, 'javascript:alert(1)');          // so the error line is present to measure
    const s = await page.evaluate(() => {
      document.documentElement.setAttribute('data-lguser-theme', 'dark');
      const cs = (sel) => getComputedStyle(document.querySelector(sel));
      return {
        card: cs('#looth-link-sheet .lgl-card').backgroundColor,
        ink: cs('#looth-link-sheet .lgl-card').color,
        err: cs('#lgl-err').color,
        input: cs('#lgl-url').color,
      };
    });
    expect(s.card).toBe('rgb(27, 30, 33)');
    expect(s.ink).toBe('rgb(229, 231, 225)');
    expect(s.err).toBe('rgb(242, 184, 181)');         // lifted for AA on the dark card
    expect(s.input).toBe('rgb(229, 231, 225)');
  });
});
