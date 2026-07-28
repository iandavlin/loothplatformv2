// COMPOSER-V2 PHASE 3 — THE EXIT TEST, MOBILE SURFACE.
//
// The desktop half (phase3-exit.spec.js) skips on mobile by construction, so
// until now mobile was proven by ARGUMENT — the mint fix is server-side, inside
// the shared minter both surfaces POST to, therefore it cannot be surface-
// specific. That argument is sound but it is not a measurement, and keeper
// rightly kept it flagged as owed. This closes it.
//
// It differs from the desktop spec in the DOOR only: a real TOUCH tap on the
// feed card's reply action (the mobile composer entry) instead of the §4e
// discussion modal's CTA. Everything downstream — the shared lgcInsertMention,
// the shared reply.php write, the mint — is the same code.
//
// Runs against SHIPPED bytes: composer v2 phase 3 merged at 6ef25e3, so there is
// no LGC_JS_OVERRIDE here and no serve mutation of any kind. That makes this a
// stronger check than the pre-merge desktop run it complements.
//
// As always the assertions that matter are in SQL, not on the page — see the
// runbook at the foot of this file.
const { test, expect } = require('@playwright/test');
const fs = require('fs');

const ENV_PATH = process.env.WP_COOKIES_ENV || '/tmp/mentions-verify/wp-cookies.env';
const TOPIC_ID = process.env.EXIT_TOPIC_ID;
const MENTION = process.env.EXIT_MENTION || 'dan-erlewine';

function readEnv() {
  const env = {};
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split('\n')) {
    const i = line.indexOf('=');
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return env;
}

test.describe('phase 3 exit — MOBILE reply mint + bell', () => {
  test.skip(({ isMobile }) => !isMobile, 'iphone-webkit project only');
  test.skip(!TOPIC_ID, 'EXIT_TOPIC_ID not set');

  test('a mobile reply posts through composer v2 and announces itself', async ({ page, context }) => {
    const e = readEnv();
    await context.addCookies([
      { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: e.GATE_VAL, path: '/', secure: true, httpOnly: true },
      { domain: 'dev2.loothgroup.com', name: e.LOGGED_IN_NAME, value: e.LOGGED_IN_VAL, path: '/', secure: true, httpOnly: true },
      { domain: 'dev2.loothgroup.com', name: e.AUTH_NAME, value: e.AUTH_VAL, path: '/', secure: true, httpOnly: true },
    ]);

    // looth_id bounce first — /profile-api (mention-suggest) is 401 without it.
    await page.goto('/looth-auth/issue?return=/hub/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.feed-card[data-topic-id]', { timeout: 25_000 });

    await page.evaluate(() => {
      window.__lgPosted = [];
      document.addEventListener('lg:reply-posted', (ev) => window.__lgPosted.push(ev.detail));
    });

    // THE MOBILE DOOR: this exact topic's reply action, tapped for real. Pinning
    // to the topic id (rather than .first()) is what lets the SQL below assert on
    // a known post_parent instead of guessing which card the feed put on top.
    const card = page.locator(`.feed-card[data-topic-id="${TOPIC_ID}"]`);
    await card.waitFor({ state: 'attached', timeout: 20_000 });
    await card.scrollIntoViewIfNeeded();
    const replyAct = card.locator('.lg-act-replies').first();
    await replyAct.waitFor({ state: 'visible', timeout: 15_000 });
    await replyAct.tap();

    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 15_000 });

    const editor = page.locator('#looth-comp-sheet .ql-editor');
    await editor.waitFor({ timeout: 20_000 });
    await editor.tap();

    const marker = 'p3mob-' + (process.env.EXIT_STAMP || 'x');
    await editor.pressSequentially(marker + ' hi ', { delay: 40 });

    // REAL mention pick. On mobile the panel picks on TOUCH, and the same
    // swallow-synthetic-click guard applies, so this must be a real tap.
    await editor.pressSequentially('@' + MENTION.slice(0, 4), { delay: 90 });
    await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 15_000 });
    const rowIdx = await page.evaluate((slug) => {
      const rows = [...document.querySelectorAll('.lg-mnt .lg-mnt__i')];
      const i = rows.findIndex((r) => (r.textContent || '').toLowerCase().includes(slug.split('-')[0]));
      return i < 0 ? 0 : i;
    }, MENTION);
    const row = page.locator('.lg-mnt .lg-mnt__i').nth(rowIdx);
    console.log('MENTION_ROW_PICKED=' + (await row.textContent()).trim().slice(0, 60));
    await row.tap();

    // GATE: the mention must be a real anchor in the editor before we post —
    // without this the spec will happily submit a plain-text "@dan-" and pass.
    await page.waitForFunction(() => {
      const ed = document.querySelector('#looth-comp-sheet .ql-editor');
      return !!ed && !!ed.querySelector('.bp-suggestions-mention, [data-lg-uuid], .lgmention');
    }, null, { timeout: 10_000 });
    console.log('COMPOSED_HTML=' + (await page.evaluate(() =>
      document.querySelector('#looth-comp-sheet .ql-editor').innerHTML)).slice(0, 300));

    const post = page.locator('#looth-comp-sheet #lgc-post');
    await expect(post).toBeEnabled({ timeout: 10_000 });
    await post.tap();

    await page.waitForFunction(() =>
      !document.querySelector('#looth-comp-sheet.is-open'), null, { timeout: 25_000 });

    const posted = await page.evaluate(() => window.__lgPosted || []);
    console.log('LG_REPLY_POSTED=' + JSON.stringify(posted));
    expect(posted.length).toBeGreaterThan(0);
    expect(String(posted[0].topicId)).toBe(String(TOPIC_ID));

    console.log('EXIT_MARKER=' + marker);
  });
});

/* STORE ASSERTIONS (the real gate — run after this spec):
 *
 *   MINT:  SELECT post_content FROM wp_posts
 *           WHERE post_type='reply' AND post_parent=$TOPIC AND post_content LIKE '%p3mob-%';
 *          expect href="{{mention_user_id_N}}"
 *   BELL:  SELECT type,target_id,anchor_id,actor_count FROM notifications
 *           WHERE user_uuid='<target>' ORDER BY created_at DESC LIMIT 3;
 *          expect ONE new forum.mention row, actor_count 1 (a second row means the
 *          bbp_new_reply backstop also fired and reply.php did not solely own the write)
 */
